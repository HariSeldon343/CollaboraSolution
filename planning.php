<?php
// Planning (Tenant 28 internal tools)
require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';

// No-cache to avoid stale JS/CSS
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$auth = new Auth();
if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess((int)$currentUser['id'], (string)$currentUser['role']);

require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('planning');

// Tenant 28 hard gate
require_once __DIR__ . '/includes/tenant28_access_check.php';
requireTenant28AccessPage($currentUser);

require_once __DIR__ . '/includes/audit_page_access.php';
trackPageAccess('planning');

$companyFilter = new CompanyFilter($currentUser);
$csrfToken = $auth->generateCSRFToken();

$planningJsVersion = ((@filemtime(__DIR__ . '/assets/js/planning.js') ?: time()) . '-' . (@filesize(__DIR__ . '/assets/js/planning.js') ?: 0));
$planningCssVersion = ((@filemtime(__DIR__ . '/assets/css/planning.css') ?: time()) . '-' . (@filesize(__DIR__ . '/assets/css/planning.css') ?: 0));
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Pianificazione (S.CO) - Nexio';
    $pageCss = [
        'assets/css/dashboard.css',
        'assets/css/planning.css?v=' . (string)$planningCssVersion,
    ];
    require __DIR__ . '/includes/layout_head.php';
?>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
    <div class="header">
        <h1 class="page-title">Pianificazione Consulenze</h1>
        <div class="flex items-center gap-4">
            <?php if ($companyFilter->canUseCompanyFilter()): ?>
                <?php echo $companyFilter->renderDropdown(); ?>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary btn-sm" id="planningShowTourBtn">Guida rapida</button>
            <span class="text-sm text-muted">
                Solo per S.CO Srls (tenant #28)
            </span>
        </div>
    </div>

    <div class="page-content">
        <div class="planning-grid">
            <div class="planning-left">
                <!-- CARD 1 (obbligatorio): Piani -->
                <div class="planning-card">
                    <div class="planning-card-header">
                        <div class="planning-card-title">Piani</div>
                    </div>
                    <div class="planning-card-body">
                        <div class="planning-toolbar" style="margin-bottom: 12px;">
                            <select id="planningClientFilter" class="form-control" style="min-width: 180px;">
                                <option value="">Caricamento...</option>
                            </select>
                            <select id="planningStatusFilter" class="form-control" style="min-width: 140px;">
                                <option value="">Tutti gli stati</option>
                                <option value="draft">Bozza</option>
                                <option value="proposed">Proposto</option>
                                <option value="approved">Approvato</option>
                                <option value="scheduled">Schedulato</option>
                                <option value="done">Completato</option>
                                <option value="cancelled">Annullato</option>
                            </select>
                        </div>
                        <input id="planningSearch" class="form-control" placeholder="Cerca titolo/note..." />
                        <div style="height: 12px;"></div>
                        <div class="planning-list" id="planningPlansList">
                            <div class="planning-muted">Caricamento...</div>
                        </div>
                    </div>
                </div>

                <!-- CARD 2 (obbligatorio): Strumenti / Parametri -->
                <div class="planning-card">
                    <div class="planning-card-header">
                        <div class="planning-card-title">Strumenti / Parametri</div>
                    </div>
                    <div class="planning-card-body">
                        <div class="planning-muted" style="margin-bottom: 10px;">
                            Prima di creare un piano, puoi aggiornare parametri e disponibilità. Poi avvia la procedura guidata.
                        </div>
                        <div class="planning-actions">
                            <button type="button" class="btn btn-secondary btn-sm" id="planningOpenActivityCatalogBtn">Catalogo servizi / norme (parametri)</button>
                            <button type="button" class="btn btn-secondary btn-sm" id="planningOpenConsultantCapacityBtn">Consulenti</button>
                            <button type="button" class="btn btn-primary btn-sm" id="planningCreatePlanBtn" title="Crea piano con procedura guidata">+ Nuovo piano (wizard)</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="planning-right">
                <div class="planning-card">
                    <div class="planning-card-header">
                        <div class="planning-card-title" id="planningActivePlanTitle">Seleziona un piano</div>
                        <div class="planning-actions">
                            <button class="btn btn-primary btn-sm" id="planningSaveAllBtn">Salva tutto</button>
                            <button class="btn btn-secondary btn-sm" id="planningAddItemBtn">+ Attività</button>
                        </div>
                    </div>
                    <div class="planning-card-body">
                        <!-- Step / progress del piano (best-effort; no-blocking) -->
                        <div class="planning-progress" id="planningPlanProgress" style="display:none; margin-bottom: 12px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap: 12px; flex-wrap:wrap;">
                                <div style="font-weight:700;">Step piano</div>
                                <div class="planning-actions">
                                    <button type="button" class="btn btn-secondary btn-sm" id="planningPlanProgressRefreshBtn">Aggiorna</button>
                                </div>
                            </div>
                            <div id="planningPlanProgressBody" style="margin-top: 10px;"></div>
                        </div>

                    <div class="planning-muted" style="margin-bottom: 10px;">
                        Costi: giornate * tariffa + km * 0,50€/km + extra
                    </div>

                    <!-- Tabs: reduce visual overload -->
                    <div class="planning-tabs" id="planningTabs" style="margin-bottom: 12px; display:none;">
                        <button type="button" class="planning-tab-btn active" data-planning-tab="activities">Attività &amp; Costi</button>
                        <button type="button" class="planning-tab-btn" data-planning-tab="consultants">Consulenti</button>
                        <button type="button" class="planning-tab-btn" data-planning-tab="schedule">Calendario (bozza)</button>
                        <button type="button" class="planning-tab-btn" data-planning-tab="compliance">Sistema documentale</button>
                        <button type="button" class="planning-tab-btn" data-planning-tab="checklist">Checklist (Progetto SGQ)</button>
                    </div>

                    <!-- TAB: Attività -->
                    <div class="planning-tab-panel" id="planningTabPanelActivities">
                        <div style="overflow-x:auto;">
                            <table class="planning-table">
                                <thead>
                                    <tr>
                                        <th>Tipo/Data</th>
                                        <th>Durata</th>
                                        <th>Tariffa</th>
                                        <th>Costo fisso</th>
                                        <th>Km</th>
                                        <th>Extra</th>
                                        <th>Note</th>
                                        <th>Totale/Task</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody id="planningItemsTbody">
                                    <tr><td colspan="9" class="planning-muted">Seleziona un piano…</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="planning-total">
                            <div>Totale piano:</div>
                            <div id="planningTotalValue">€0,00</div>
                        </div>
                    </div>

                    <!-- TAB: Calendario -->
                    <div class="planning-tab-panel" id="planningTabPanelSchedule" style="display:none;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap: 12px; flex-wrap:wrap;">
                            <div class="planning-card-title">Calendario proposto (bozza)</div>
                            <div class="planning-actions">
                                <button type="button" class="btn btn-secondary btn-sm" id="planningScheduleReloadBtn">Ricarica</button>
                                <button type="button" class="btn btn-primary btn-sm" id="planningScheduleGenerateBtn">Genera proposta</button>
                                <button type="button" class="btn btn-primary btn-sm" id="planningScheduleConfirmBtn">Conferma (crea eventi)</button>
                            </div>
                        </div>
                        <div class="planning-muted" style="margin-top: 8px;">
                            La proposta evita conflitti sugli eventi esistenti dei consulenti selezionati. Puoi modificare gli slot prima di confermare.
                        </div>
                        <div style="height: 10px;"></div>
                        <div style="overflow-x:auto;">
                            <table class="planning-table">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Fase</th>
                                        <th>Titolo</th>
                                        <th>Inizio</th>
                                        <th>Fine</th>
                                        <th>Consulente</th>
                                        <th>Reason</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody id="planningScheduleTbody">
                                    <tr><td colspan="8" class="planning-muted">Seleziona un piano…</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- TAB: Compliance -->
                    <div class="planning-tab-panel" id="planningTabPanelCompliance" style="display:none;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap: 12px; flex-wrap:wrap;">
                            <div class="planning-card-title">Sistema documentale (IMS)</div>
                            <div class="planning-actions">
                                <button type="button" class="btn btn-primary btn-sm" id="planningDocProvisionBtn">Provisiona /IMS</button>
                                <a class="btn btn-secondary btn-sm" id="planningDocOpenComplianceLink" href="compliance.php" target="_blank" rel="noopener">Apri Compliance</a>
                                <a class="btn btn-secondary btn-sm" id="planningDocOpenFilesLink" href="files.php" target="_blank" rel="noopener">Apri File Manager</a>
                                <button type="button" class="btn btn-secondary btn-sm" id="planningDocOpenAiHubBtn">AI Hub</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="planningOpenImsTemplateCatalogBtn">Catalogo template IMS</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="planningOpenImsModulesBtn">Moduli IMS</button>
                            </div>
                        </div>
                        <div class="planning-muted" style="margin-top: 10px;">
                            Qui gestisci il provisioning della struttura <code>/IMS</code> e i cataloghi (template/moduli). La compilazione dei deliverable e l’AI operano nella pagina <strong>Compliance</strong> e nell’<strong>AI Hub</strong> del tenant cliente.
                        </div>

                        <div id="planningProvisionLastSummary" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
                    </div>

                    <!-- TAB: Checklist SGQ (add-on, opt-in) -->
                    <div class="planning-tab-panel" id="planningTabPanelChecklist" style="display:none;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap: 12px; flex-wrap:wrap;">
                            <div class="planning-card-title">Checklist (Progetto SGQ)</div>
                            <div class="planning-actions" style="flex-wrap:wrap;">
                                <button type="button" class="btn btn-secondary btn-sm" id="planningChecklistReloadBtn">Ricarica</button>
                                <button type="button" class="btn btn-primary btn-sm" id="planningChecklistCreateBtn">Crea checklist da template</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="planningChecklistAutofillBtn">Precompila checklist (AI)</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="planningChecklistAssistantBtn">Raccolta dati / Gap analysis (AI)</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="planningChecklistDocsReindexBtn">Reindicizza</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="planningChecklistDocsAnalyzeBtn">Analizza documenti</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="planningChecklistExportBtn">Export Excel</button>
                            </div>
                        </div>
                        <div class="planning-muted" style="margin-top: 10px;">
                            Checklist operativa per raccogliere informazioni ed evidenze (senza testo ISO/UNI). È un layer <strong>additivo</strong>: non modifica automaticamente stime/attività/provisioning.
                        </div>
                        <div style="display:flex; gap: 12px; flex-wrap:wrap; align-items:flex-end; margin-top: 12px;">
                            <div class="form-group" style="min-width: 260px; flex: 1;">
                                <label>Template checklist</label>
                                <select id="planningChecklistTemplateSelect" class="form-control"></select>
                            </div>
                            <div class="form-group" style="min-width: 220px;">
                                <label>Filtro fase</label>
                                <select id="planningChecklistFilterPhase" class="form-control">
                                    <option value="">Tutte</option>
                                </select>
                            </div>
                            <div class="form-group" style="min-width: 220px;">
                                <label>Filtro stato</label>
                                <select id="planningChecklistFilterStatus" class="form-control">
                                    <option value="">Tutti</option>
                                    <option value="missing">missing</option>
                                    <option value="present">present</option>
                                    <option value="to_review">to_review</option>
                                    <option value="done">done</option>
                                    <option value="not_applicable">not_applicable</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: 12px;">
                            <label>Obiettivo della checklist <span style="color: rgb(185, 28, 28);">*</span></label>
                            <textarea id="planningChecklistObjectiveText" class="form-control" rows="2" placeholder="Obiettivo (obbligatorio) — es. Raccolta dati e assessment iniziale per SGQ Sanità (ISO 9001 + ISO 7101)"></textarea>
                            <div id="planningChecklistObjectiveMsg" class="planning-muted" style="margin-top: 6px; display:none;"></div>
                        </div>
                        <div id="planningChecklistStatusBox" style="margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
                        <div id="planningChecklistDocSummaryBox" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
                        <div id="planningChecklistSections" style="margin-top: 12px;"></div>
                    </div>

                    <!-- TAB: Consulenti -->
                    <div class="planning-tab-panel" id="planningTabPanelConsultants" style="display:none;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap: 12px; flex-wrap:wrap;">
                            <div class="planning-card-title">Assegnazione consulenti (per servizio)</div>
                            <div class="planning-actions">
                                <button type="button" class="btn btn-secondary btn-sm" id="planningConsultantsTabSelectBtn">Seleziona consulenti</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="planningAllocationSuggestBtn">Proponi allocazione (AI)</button>
                                <button type="button" class="btn btn-primary btn-sm" id="planningAllocationApplyBtn">Applica allocazione</button>
                            </div>
                        </div>
                        <div class="planning-muted" style="margin-top: 8px;">
                            Step consigliato: prima genera la stima e le attività, poi dividi le giornate tra consulenti per ciascun servizio. L’assegnazione verrà applicata alle righe del piano.
                        </div>
                        <div id="planningAllocationMsg" style="display:none; margin-top: 10px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
                        <div id="planningAllocationMatrixWrap" style="margin-top: 10px; overflow:auto;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Compliance Provision Modal -->
    <div class="modal" id="planningComplianceProvisionModal" style="display:none;">
        <div class="modal-content" style="max-width: 720px;">
            <div class="modal-header">
                <h2 id="planningComplianceProvisionTitle">Provisiona Compliance (IMS)</h2>
                <button class="modal-close" id="planningComplianceProvisionModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-muted" style="margin-bottom:10px;">
                    Questa azione crea nel <strong>tenant cliente</strong> la struttura <code>/IMS</code> e i documenti (da <strong>template modello</strong> se configurati, altrimenti placeholder OnlyOffice — sempre senza testo ISO).
                    È <strong>idempotente</strong>: se rilanciata, riusa cartelle/documenti esistenti.
                </div>

                <div id="planningProvisionCompanyProfileBox" style="margin-bottom:12px; padding:10px; border:1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap: 12px; flex-wrap:wrap;">
                        <div style="flex:1; min-width: 240px;">
                            <div style="font-weight:700; margin-bottom:6px;">Dati azienda (consigliati)</div>
                            <div class="planning-muted" id="planningProvisionCompanyProfileStatus">Caricamento…</div>
                        </div>
                        <div class="planning-actions">
                            <button type="button" class="btn btn-secondary btn-sm" id="planningProvisionEditCompanyProfileBtn">Compila</button>
                        </div>
                    </div>
                    <div class="planning-muted" style="margin-top:8px; font-size:12px;">
                        Servono a dare contesto al sistema documentale e alla compilazione guidata (nessun testo ISO/UNI). Puoi anche procedere con soli placeholder.
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-checkbox-label">
                        <input type="checkbox" id="planningProvisionCreateDocuments" class="form-checkbox" checked>
                        <span>Crea documenti placeholder (OnlyOffice)</span>
                    </label>
                </div>

                <div class="form-group" id="planningProvisionCreateTasksGroup">
                    <label class="form-checkbox-label">
                        <input type="checkbox" id="planningProvisionCreateTasks" class="form-checkbox" checked>
                        <span>Crea task di compilazione (deliverable IMS) dopo il provisioning</span>
                    </label>
                    <div class="form-text" id="planningProvisionCreateTasksHelp">Non crea le attività consulenza del piano (già presenti). Crea solo task/checklist in Compliance.</div>
                </div>

                <div class="form-group">
                    <label class="form-checkbox-label">
                        <input type="checkbox" id="planningProvisionCreateMilestones" class="form-checkbox">
                        <span>Crea milestone nel calendario cliente (opzionale)</span>
                    </label>
                    <div class="form-text">Crea eventi tipo Kickoff/Audit interno/Riesame/Certificazione (best-effort).</div>
                </div>

                <div class="form-group" style="margin-top: 12px;">
                    <label>Step 1 — Moduli IMS (opzionale)</label>
                    <select id="planningProvisionModulesSelect" class="form-control" multiple size="6"></select>
                    <div class="planning-actions" style="margin-top:8px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" id="planningProvisionSelectIso9001SgqCompletoBtn" title="Provisiona ISO9001_SGQ_COMPLETO (Manuale qualità + 33 procedure + registri; senza testo ISO/UNI)">Seleziona solo ISO9001_SGQ_COMPLETO</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="planningProvisionDeselectAllModulesBtn">Deseleziona tutti</button>
                    </div>
                    <div class="form-text">
                        Se selezioni uno o più moduli, verranno provisionati solo i template contenuti nei moduli selezionati.
                        Se lasci vuoto, verranno usati i template applicabili per standard (comportamento attuale).
                    </div>
                </div>

                <div id="planningProvisionResult" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningComplianceProvisionModalCancel">Annulla</button>
                <button type="button" class="btn btn-primary" id="planningComplianceProvisionRunBtn">Esegui provisioning</button>
            </div>
        </div>
    </div>

    <!-- Company Profile Modal (pre-provisioning, tenant 28 -> client tenant) -->
    <div class="modal" id="planningCompanyProfileModal" style="display:none;">
        <div class="modal-content" style="max-width: 860px;">
            <div class="modal-header">
                <h2>Dati azienda (consigliati)</h2>
                <button class="modal-close" id="planningCompanyProfileModalClose" aria-label="Chiudi">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-muted" style="margin-bottom:10px;">
                    Queste informazioni aiutano a generare e compilare il sistema documentale. Puoi procedere anche senza: verranno creati solo placeholder.
                </div>
                <div class="planning-form-row">
                    <div class="form-group" style="flex:2;">
                        <label>Ragione sociale</label>
                        <input id="planningCompanyProfileCompanyName" class="form-control" placeholder="Es. Azienda Ospedaliera ..." />
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Siti / sedi (1 per riga)</label>
                        <textarea id="planningCompanyProfileSites" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label>Prodotti / servizi (1 per riga)</label>
                        <textarea id="planningCompanyProfileProducts" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Processi principali (1 per riga)</label>
                        <textarea id="planningCompanyProfileProcesses" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label>Ruoli / responsabilità (1 per riga)</label>
                        <textarea id="planningCompanyProfileRoles" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Note (opzionale)</label>
                        <textarea id="planningCompanyProfileNotes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div id="planningCompanyProfileMsg" class="planning-muted" style="display:none; margin-top:10px; padding:10px; border:1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningCompanyProfileSkipBtn">Procedi comunque (placeholder)</button>
                <button type="button" class="btn btn-primary" id="planningCompanyProfileSaveContinueBtn">Salva e continua</button>
            </div>
        </div>
    </div>

    <!-- Plan Create Modal -->
    <div class="modal" id="planningPlanModal" style="display:none;">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header">
                <h2>Crea Piano</h2>
                <button class="modal-close" id="planningPlanModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <form id="planningPlanForm">
                    <div class="form-group">
                        <label>Azienda cliente *</label>
                        <select id="planningPlanClient" class="form-control" required></select>
                    </div>
                    <div class="form-group">
                        <label>Titolo *</label>
                        <input id="planningPlanTitle" class="form-control" required placeholder="Es. Piano Q1 - Avviamento + formazione" />
                    </div>
                    <div class="planning-form-row">
                        <div class="form-group">
                            <label>Stato</label>
                            <select id="planningPlanStatus" class="form-control">
                                <option value="draft">Bozza</option>
                                <option value="proposed">Proposto</option>
                                <option value="approved">Approvato</option>
                                <option value="scheduled">Schedulato</option>
                                <option value="done">Completato</option>
                                <option value="cancelled">Annullato</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Note</label>
                            <input id="planningPlanNotes" class="form-control" placeholder="Note sintetiche (opzionale)" />
                        </div>
                    </div>
                    <div class="planning-form-row">
                        <div class="form-group">
                            <label>Periodo start</label>
                            <input id="planningPlanStart" type="date" class="form-control" />
                        </div>
                        <div class="form-group">
                            <label>Periodo end</label>
                            <input id="planningPlanEnd" type="date" class="form-control" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Consulenti S.CO (per disponibilità/calendario)</label>
                        <select id="planningPlanConsultants" class="form-control" multiple size="6"></select>
                        <div class="form-text">Seleziona 1+ consulenti. La proposta calendario eviterà i conflitti sugli eventi esistenti di questi utenti.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="planningPlanModalCancel">Annulla</button>
                        <button type="submit" class="btn btn-primary">Crea</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Consultants Modal -->
    <div class="modal" id="planningConsultantsModal" style="display:none;">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header">
                <h2>Consulenti S.CO (piano)</h2>
                <button class="modal-close" id="planningConsultantsModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-muted" style="margin-bottom: 10px;">
                    Seleziona i consulenti S.CO da considerare per disponibilità e assegnazione degli slot.
                </div>
                <select id="planningConsultantsSelect" class="form-control" multiple size="10"></select>
                <div id="planningConsultantsHomeCityWrap" style="margin-top: 14px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningConsultantsModalCancel">Annulla</button>
                <button type="button" class="btn btn-primary" id="planningConsultantsModalSave">Salva</button>
            </div>
        </div>
    </div>

    <!-- Activity Catalog Modal -->
    <div class="modal" id="planningActivityCatalogModal" style="display:none;">
        <div class="modal-content planning-activity-catalog-modal">
            <div class="modal-header">
                <h2>Catalogo servizi / norme (parametri)</h2>
                <button class="modal-close" id="planningActivityCatalogModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-toolbar" style="margin-bottom: 12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input id="planningServiceCatalogSearch" class="form-control" style="min-width: 220px;" placeholder="Cerca servizio/norma/alias..." />
                    <select id="planningServiceCatalogCategory" class="form-control" style="min-width: 160px;">
                        <option value="">Tutte le categorie</option>
                    </select>
                    <label class="form-checkbox-label">
                        <input type="checkbox" id="planningServiceCatalogActiveOnly" class="form-checkbox" checked>
                        <span>Solo attivi</span>
                    </label>
                    <div style="flex:1;"></div>
                    <button type="button" class="btn btn-secondary btn-sm" id="planningServiceCatalogSeedBtn">Seed servizi</button>
                    <button type="button" class="btn btn-primary btn-sm" id="planningActivityTypeAddBtn">+ Nuovo servizio</button>
                </div>
                <div class="planning-activity-catalog-table-wrap">
                    <table class="planning-table planning-activity-catalog-table" id="planningActivityCatalogTable">
                            <thead>
                            <tr>
                                <th>Codice</th>
                                <th>Nome</th>
                                <th>Categoria</th>
                                <th>Norme/Standard</th>
                                <th>Range gg base</th>
                                <th>Tariffa (€/g)</th>
                                <th>Call</th>
                                <th>Stato</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="planningActivityCatalogTbody">
                            <tr><td colspan="9" class="planning-muted">Caricamento...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningActivityCatalogModalCancel">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- Activity Type Edit Modal -->
    <div class="modal" id="planningActivityTypeModal" style="display:none;">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header">
                <h2 id="planningActivityTypeModalTitle">Nuovo servizio</h2>
                <button class="modal-close" id="planningActivityTypeModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="planningActivityTypeId" value="">
                <div class="form-group">
                    <label>Nome *</label>
                    <input id="planningActivityTypeName" class="form-control" required />
                </div>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label>Codice servizio (opzionale)</label>
                        <input id="planningActivityTypeServiceCode" class="form-control" placeholder="Es. ISO9001" />
                        <div class="form-text">Se presente, viene usato per dedup/seed e per etichettare rapidamente i servizi.</div>
                    </div>
                    <div class="form-group">
                        <label>Categoria (opzionale)</label>
                        <input id="planningActivityTypeCategory" class="form-control" placeholder="Es. ISO, Food, Privacy..." />
                    </div>
                    <div class="form-group">
                        <label>Scheme type (opzionale)</label>
                        <select id="planningActivityTypeSchemeType" class="form-control">
                            <option value="">—</option>
                            <option value="MSS">MSS</option>
                            <option value="FSMS">FSMS</option>
                            <option value="GFSI">GFSI</option>
                            <option value="LAB">LAB</option>
                            <option value="PRIVACY">PRIVACY</option>
                            <option value="231">231</option>
                            <option value="REGULATORY">REGULATORY</option>
                            <option value="SAFETY_SERVICE">SAFETY_SERVICE</option>
                            <option value="GENERIC_NORM">GENERIC_NORM</option>
                        </select>
                        <div class="form-text">Usato per sinergie e driver condizionali nel wizard stima.</div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Alias (1 per riga)</label>
                    <textarea id="planningActivityTypeAliases" class="form-control" rows="3" placeholder="Es. 9001&#10;Qualità&#10;SGQ"></textarea>
                </div>
                <div class="form-group">
                    <label>Norme/Standard (1 per riga)</label>
                    <textarea id="planningActivityTypeStandardCodes" class="form-control" rows="2" placeholder="Es. ISO9001&#10;HACCP"></textarea>
                    <div class="form-text">Per servizi non-norma lascia vuoto. Per servizi norma inserisci i codici standard coperti (solo codici, niente testo ISO).</div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label>Giornate base min (opzionale)</label>
                        <input id="planningActivityTypeBaseDaysMin" type="number" step="1" min="0" class="form-control" placeholder="Es. 6" />
                    </div>
                    <div class="form-group">
                        <label>Giornate base max (opzionale)</label>
                        <input id="planningActivityTypeBaseDaysMax" type="number" step="1" min="0" class="form-control" placeholder="Es. 14" />
                    </div>
                    <div class="form-group">
                        <label>Complessità (1-5)</label>
                        <select id="planningActivityTypeComplexityScore" class="form-control">
                            <option value="">—</option>
                            <option value="1">1 (bassa)</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5 (alta)</option>
                        </select>
                    </div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label>Peso</label>
                        <input id="planningActivityTypeWeight" type="number" step="0.01" min="0" class="form-control" placeholder="Default (da complessità): 1.00–1.50" />
                    </div>
                    <div class="form-group">
                        <label>Attiva</label>
                        <select id="planningActivityTypeActive" class="form-control">
                            <option value="1">Sì</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label>Tariffa default (€/giorno)</label>
                        <input id="planningActivityTypeDefaultDayRate" type="number" step="0.01" min="0" class="form-control" placeholder="Default: 0.00" />
                        <div class="form-text">Viene precompilata sulle righe del piano quando selezioni questo tipo consulenza (modificabile per riga).</div>
                    </div>
                    <div class="form-group">
                        <label>Costo fisso default (€)</label>
                        <input id="planningActivityTypeDefaultFixedAmount" type="number" step="0.01" min="0" class="form-control" placeholder="Default: 0.00" />
                        <div class="form-text">Costo aggiuntivo per attività (si somma a giornate/km/extra). Modificabile per riga.</div>
                    </div>
                </div>
                <details style="margin-top: 10px;">
                    <summary style="cursor:pointer; font-weight:600;">Avanzate: fasi/deliverable (JSON)</summary>
                    <div style="margin-top: 10px;">
                        <div class="form-group">
                            <label>Fasi di default (JSON)</label>
                            <textarea id="planningActivityTypeDefaultPhasesJson" class="form-control" rows="6" placeholder='[{"phase_key":"kickoff","label":"Kickoff","default_activity_type":"call","share_of_total":0.1}]'></textarea>
                            <div class="form-text">Usato per generare automaticamente le righe del piano da una stima (share_of_total 0-1).</div>
                        </div>
                        <div class="form-group">
                            <label>Deliverable di default (JSON)</label>
                            <textarea id="planningActivityTypeDefaultDeliverablesJson" class="form-control" rows="4" placeholder='[]'></textarea>
                        </div>
                    </div>
                </details>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label>Call ogni (giorni)</label>
                        <input id="planningActivityTypeCallEvery" type="number" step="1" min="1" class="form-control" placeholder="Default (da complessità): 90..30" />
                    </div>
                    <div class="form-group">
                        <label>Durata call (min)</label>
                        <input id="planningActivityTypeCallDur" type="number" step="5" min="5" class="form-control" placeholder="Default: 30" />
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningActivityTypeModalCancel">Annulla</button>
                <button type="button" class="btn btn-primary" id="planningActivityTypeModalSave">Salva</button>
            </div>
        </div>
    </div>

    <!-- Consultant Capacity Modal -->
    <div class="modal" id="planningConsultantCapacityModal" style="display:none;">
        <div class="modal-content" style="max-width: 860px;">
            <div class="modal-header">
                <h2>Disponibilità consulenti (default)</h2>
                <button class="modal-close" id="planningConsultantCapacityModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-muted" style="margin-bottom: 10px;">
                    Imposta le <strong>giornate disponibili</strong> (default) per ciascun consulente S.CO. Il wizard precompilerà automaticamente lo Step “Disponibilità”.
                </div>
                <div class="form-text" style="margin-bottom: 10px;">
                    Le giornate inserite qui sono il <strong>default</strong>. Nel wizard puoi sempre fare override per un piano specifico (e ripristinare il default con un click).
                </div>
                <div id="planningConsultantCapacityStorageWarning" style="display:none; margin-bottom: 10px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                    <strong>Storage non disponibile</strong>: migrazione 37 non applicata. Puoi comunque inserire le giornate manualmente nel wizard.
                </div>
                <div style="overflow-x:auto;">
                    <table class="planning-table">
                        <thead>
                            <tr>
                                <th>Consulente</th>
                                <th style="width: 160px;">Giornate disponibili</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody id="planningConsultantCapacityTbody">
                            <tr><td colspan="3" class="planning-muted">Caricamento...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningConsultantCapacityModalCancel">Chiudi</button>
                <button type="button" class="btn btn-primary" id="planningConsultantCapacitySaveBtn">Salva</button>
            </div>
        </div>
    </div>

    <!-- Calendar Event Modal -->
    <div class="modal" id="planningCalendarModal" style="display:none;">
        <div class="modal-content" style="max-width: 720px;">
            <div class="modal-header">
                <h2>Crea evento in Calendario</h2>
                <button class="modal-close" id="planningCalendarModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <form id="planningCalendarForm">
                    <input type="hidden" id="planningCalendarItemId" value="">
                    <div class="form-group">
                        <label>Azienda (Calendario) *</label>
                        <select id="planningCalendarTenant" class="form-control"></select>
                        <div class="form-text">L’evento verrà creato nel calendario del tenant selezionato.</div>
                    </div>
                    <div class="form-group">
                        <label>Titolo *</label>
                        <input id="planningCalendarTitle" class="form-control" required placeholder="Es. Visita in sede / Call consulenza" />
                    </div>
                    <div class="planning-form-row">
                        <div class="form-group">
                            <label>Inizio *</label>
                            <input id="planningCalendarStart" type="datetime-local" class="form-control" required />
                        </div>
                        <div class="form-group">
                            <label>Fine *</label>
                            <input id="planningCalendarEnd" type="datetime-local" class="form-control" required />
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Descrizione</label>
                        <textarea id="planningCalendarDesc" class="form-control" rows="5" placeholder="Dettagli..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="planningCalendarModalCancel">Annulla</button>
                        <button type="submit" class="btn btn-primary">Crea evento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Legacy wizard “Proposta Piano (AI)” removed: keep only “Stima giornate (Servizi / Norme)” for coherence -->

    <!-- Estimate Wizard Modal (Services/Norms -> Estimate days -> Generate items) -->
    <div class="modal" id="planningEstimateWizardModal" style="display:none;">
        <div class="modal-content planning-wizard-modal">
            <div class="modal-header">
                <h2>Procedura guidata: Stima giornate (Servizi / Norme)</h2>
                <button class="modal-close" id="planningEstimateWizardModalClose" aria-label="Chiudi">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-muted" style="margin-bottom: 12px;">
                    Seleziona 1+ servizi/norme, inserisci (anche parzialmente) il profilo azienda e ottieni una stima best-effort. Poi genera automaticamente le attività per fasi.
                </div>

                <div id="planningEstimateWizardStepIndicator" class="planning-muted" style="margin-bottom: 10px;"></div>
                <div id="planningEstimateWizardDraftBanner" class="planning-muted" style="display:none; margin-bottom: 10px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>

                <div id="planningEstimateWizardStep1" class="planning-wizard-step">
                    <div class="form-group">
                        <label>Azienda cliente *</label>
                        <select id="planningEstimateWizardClient" class="form-control" required></select>
                    </div>
                    <div class="form-group" id="planningEstimateWizardLocationsGroup" style="display:none;">
                        <label>Sede/i per attività on-site (consigliato)</label>
                        <div class="form-text">Se l’azienda ha più sedi, seleziona dove si svolgeranno le attività on-site (puoi selezionarne più di una). Serve per ottimizzare le trasferte.</div>
                        <div id="planningEstimateWizardLocationsWrap" style="margin-top: 8px;"></div>
                    </div>
                    <div class="planning-form-row">
                        <div class="form-group">
                            <label>Titolo piano (opzionale)</label>
                            <input id="planningEstimateWizardTitle" class="form-control" placeholder="Es. ISO 9001 + 14001 — Avvio + implementazione" />
                        </div>
                        <div class="form-group">
                            <label>Scadenza / fine periodo (opzionale)</label>
                            <input id="planningEstimateWizardPeriodEnd" type="date" class="form-control" />
                            <div class="form-text">Se vuoto, verrà usata una finestra bozza calendario di <strong>30 giorni</strong> (best-effort) per generare una timeline compatta.</div>
                        </div>
                    </div>
                </div>

                <div id="planningEstimateWizardStep2" class="planning-wizard-step" style="display:none;">
                    <div class="planning-form-row">
                        <div class="form-group" style="flex:1;">
                            <label>Categoria</label>
                            <select id="planningEstimateWizardServiceCategory" class="form-control"></select>
                        </div>
                        <div class="form-group" style="flex:2;">
                            <label>Cerca</label>
                            <input id="planningEstimateWizardServiceSearch" class="form-control" placeholder="Cerca servizio/norma/alias..." />
                        </div>
                    </div>
                    <label class="form-checkbox-label" style="margin-top: 4px;">
                        <input type="checkbox" id="planningEstimateWizardShowLegacy" class="form-checkbox">
                        <span>Mostra servizi <strong>LEGACY</strong> (non consigliato)</span>
                    </label>
                    <div class="form-group">
                        <label>Servizi / norme *</label>
                        <select id="planningEstimateWizardServices" class="form-control" multiple size="10"></select>
                        <div class="form-text">Suggerimento: puoi selezionare più voci con semplici click (non serve Ctrl).</div>
                    </div>
                    <div id="planningEstimateWizardServiceWarnings" class="planning-muted" style="display:none; margin-top: 10px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
                </div>

                <div id="planningEstimateWizardStep3" class="planning-wizard-step" style="display:none;">
                    <div class="planning-form-row">
                        <div class="form-group">
                            <label>Settore (opzionale)</label>
                            <input id="planningEstimateWizardSector" class="form-control" placeholder="Es. sanità, alimentare, servizi, manifatturiero..." />
                        </div>
                        <div class="form-group">
                            <label>Fascia dipendenti (opzionale)</label>
                            <select id="planningEstimateWizardEmployeesRange" class="form-control">
                                <option value="">—</option>
                                <option value="1-10">1-10</option>
                                <option value="11-50">11-50</option>
                                <option value="51-200">51-200</option>
                                <option value="201-500">201-500</option>
                                <option value="500+">500+</option>
                            </select>
                        </div>
                    </div>
                    <div class="planning-form-row">
                        <div class="form-group">
                            <label>Numero sedi/siti (opzionale)</label>
                            <input id="planningEstimateWizardSitesCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 1" />
                        </div>
                        <div class="form-group">
                            <label class="form-checkbox-label" style="margin-top: 28px;">
                                <input type="checkbox" id="planningEstimateWizardRegulated" class="form-checkbox">
                                <span>Settore/perimetro regolamentato</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Sito web (opzionale)</label>
                        <input id="planningEstimateWizardWebsite" class="form-control" placeholder="https://..." />
                        <div class="form-text">Se presente, il sistema proverà a leggere una piccola parte del sito (best-effort) per migliorare la stima.</div>
                    </div>
                    <div class="form-group">
                        <label>Note (opzionale)</label>
                        <textarea id="planningEstimateWizardNotes" class="form-control" rows="4" placeholder="Dettagli utili (prodotti/servizi, sedi incluse, particolarità, vincoli)..."></textarea>
                    </div>

                    <div id="planningEstimateWizardDocsBox" style="margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap: 12px; flex-wrap:wrap;">
                            <div style="flex:1; min-width: 240px;">
                                <div style="font-weight:700; margin-bottom:6px;">Documenti del cliente (IMS/Knowledge)</div>
                                <div class="planning-muted" id="planningEstimateWizardDocsStatus">Caricamento…</div>
                            </div>
                            <div class="planning-actions" style="flex-wrap:wrap;">
                                <button type="button" class="btn btn-secondary btn-sm" id="planningEstimateWizardDocsReindexBtn">Reindicizza ora</button>
                                <button type="button" class="btn btn-primary btn-sm" id="planningEstimateWizardDocsAnalyzeBtn">Analizza documenti</button>
                            </div>
                        </div>
                        <div id="planningEstimateWizardDocsAnalysisWrap" style="display:none; margin-top:10px;"></div>
                        <label class="form-checkbox-label" style="margin-top: 10px;">
                            <input type="checkbox" id="planningEstimateWizardUseDocEvidence" class="form-checkbox" checked>
                            <span>Usa risultati per migliorare stima (best-effort)</span>
                        </label>
                        <div class="form-text">Se l’indice non è aggiornato, la stima non considera (o considera parzialmente) le evidenze documentali.</div>
                    </div>

                    <details id="planningEstimateWizardAdvancedDetails" style="margin-top: 12px;">
                        <summary style="cursor:pointer; font-weight:700;">Avanzato (consigliato) — migliora la confidenza della stima</summary>
                        <div style="margin-top: 10px;">
                            <div class="planning-form-row">
                                <div class="form-group" style="flex:1;">
                                    <label>Tipo intervento (consigliato)</label>
                                    <select id="planningEstimateWizardInterventionType" class="form-control">
                                        <option value="">—</option>
                                        <option value="new_implementation">Nuova implementazione</option>
                                        <option value="maintenance">Mantenimento</option>
                                        <option value="recertification">Rinnovo / Ricertificazione</option>
                                        <option value="scope_extension">Estensione scopo</option>
                                        <option value="transition_update">Transizione / aggiornamento</option>
                                    </select>
                                    <div class="form-text">Se non compilato la stima si calcola comunque, ma con confidenza ridotta.</div>
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label>Maturità SGQ/IMS (consigliato)</label>
                                    <select id="planningEstimateWizardQmsMaturity" class="form-control">
                                        <option value="">—</option>
                                        <option value="none">Nessun sistema strutturato</option>
                                        <option value="partial_informal">Parziale / informale</option>
                                        <option value="structured_not_certified">Strutturato ma non certificato</option>
                                        <option value="already_certified">Già certificato</option>
                                        <option value="integrated_system_existing">Sistema integrato già presente</option>
                                    </select>
                                    <div class="form-text">Serve per distinguere implementazione vs mantenimento.</div>
                                </div>
                            </div>

                            <div class="planning-form-row">
                                <div class="form-group">
                                    <label>Processi core (n.)</label>
                                    <input id="planningEstimateWizardCoreProcessCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 6" />
                                </div>
                                <div class="form-group">
                                    <label>Reparti/unità (n.)</label>
                                    <input id="planningEstimateWizardDepartmentsCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 4" />
                                </div>
                                <div class="form-group">
                                    <label>Linee prodotto/servizio (n.)</label>
                                    <input id="planningEstimateWizardProductLinesCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 3" />
                                </div>
                                <div class="form-group">
                                    <label>Fornitori critici (n.)</label>
                                    <input id="planningEstimateWizardCriticalSuppliersCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 8" />
                                </div>
                            </div>

                            <div class="planning-form-row">
                                <div class="form-group" style="flex:1;">
                                    <label>Progettazione/sviluppo applicabile (ISO 9001)</label>
                                    <select id="planningEstimateWizardDesignApplicability" class="form-control">
                                        <option value="unknown">Non so / da valutare</option>
                                        <option value="yes">Sì</option>
                                        <option value="no">No</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label>Outsourcing</label>
                                    <select id="planningEstimateWizardOutsourcingLevel" class="form-control">
                                        <option value="">—</option>
                                        <option value="low">Basso</option>
                                        <option value="medium">Medio</option>
                                        <option value="high">Alto</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex:1;">
                                    <label>Maturità IT</label>
                                    <select id="planningEstimateWizardItMaturity" class="form-control">
                                        <option value="">—</option>
                                        <option value="low">Bassa</option>
                                        <option value="medium">Media</option>
                                        <option value="high">Alta</option>
                                    </select>
                                </div>
                            </div>

                            <div class="planning-form-row">
                                <div class="form-group">
                                    <label>Scadenza desiderata consegna (opzionale)</label>
                                    <input id="planningEstimateWizardPreferredDeliveryDeadline" type="date" class="form-control" />
                                </div>
                                <div class="form-group">
                                    <label>Preferenza on-site (%)</label>
                                    <input id="planningEstimateWizardOnSitePreferenceRatio" type="number" min="0" max="100" step="5" class="form-control" placeholder="Es. 60" />
                                    <div class="form-text">Serve per stimare ripartizione on-site vs remoto (non blocca).</div>
                                </div>
                                <div class="form-group">
                                    <label>Lingue (opzionale)</label>
                                    <select id="planningEstimateWizardLanguagesNeeded" class="form-control" multiple size="3">
                                        <option value="IT">IT</option>
                                        <option value="EN">EN</option>
                                        <option value="OTHER">Altro</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Blackout period / vincoli calendario (opzionale)</label>
                                <textarea id="planningEstimateWizardBlackoutPeriods" class="form-control" rows="3" placeholder="Es. ferie, picchi produzione, giorni non disponibili..."></textarea>
                            </div>

                            <label class="form-checkbox-label" style="margin-top: 8px;">
                                <input type="checkbox" id="planningEstimateWizardUseAiEnrichment" class="form-checkbox">
                                <span>Usa AI per arricchire il profilo (opzionale)</span>
                            </label>
                            <div class="form-text">Best-effort: se l’AI fallisce la stima resta deterministica. Impatto massimo ±20% sui giorni suggeriti.</div>

                            <div id="planningEstimateWizardIso14001Advanced" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                                <div style="font-weight:700; margin-bottom:8px;">ISO 14001 — Avanzato</div>
                                <div class="planning-form-row">
                                    <div class="form-group" style="flex:1;">
                                        <label>Complessità aspetti ambientali</label>
                                        <select id="planningEstimateWizardIso14001AspectsComplexity" class="form-control">
                                            <option value="">—</option>
                                            <option value="low">Bassa</option>
                                            <option value="medium">Media</option>
                                            <option value="high">Alta</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Presenza autorizzazioni/permessi</label>
                                        <select id="planningEstimateWizardIso14001PermitsPresence" class="form-control">
                                            <option value="unknown">Non so / da valutare</option>
                                            <option value="yes">Sì</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Sostanze pericolose</label>
                                        <select id="planningEstimateWizardIso14001HazardousSubstances" class="form-control">
                                            <option value="unknown">Non so / da valutare</option>
                                            <option value="yes">Sì</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-text">Questo blocco compare solo se tra i servizi selezionati c’è ISO 14001.</div>
                            </div>

                            <div id="planningEstimateWizardFoodAdvanced" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                                <div style="font-weight:700; margin-bottom:8px;">Food (HACCP / ISO 22000 / BRC / IFS) — Avanzato</div>
                                <div class="planning-form-row">
                                    <div class="form-group">
                                        <label>N. studi HACCP</label>
                                        <input id="planningEstimateWizardFoodHaccpStudiesCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 2" />
                                    </div>
                                    <div class="form-group">
                                        <label>Turni</label>
                                        <select id="planningEstimateWizardFoodShiftsCount" class="form-control">
                                            <option value="">—</option>
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Prodotti ad alto rischio</label>
                                        <select id="planningEstimateWizardFoodHighRiskProducts" class="form-control">
                                            <option value="unknown">Non so / da valutare</option>
                                            <option value="yes">Sì</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="planning-form-row">
                                    <div class="form-group" style="flex:1;">
                                        <label>Dimensione area produzione</label>
                                        <select id="planningEstimateWizardFoodProductionAreaSizeClass" class="form-control">
                                            <option value="">—</option>
                                            <option value="small">Piccola</option>
                                            <option value="medium">Media</option>
                                            <option value="large">Grande</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Area produzione (m²) (opzionale)</label>
                                        <input id="planningEstimateWizardFoodProductionAreaM2" type="number" min="0" step="1" class="form-control" placeholder="Es. 1200" />
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Laboratorio interno</label>
                                        <select id="planningEstimateWizardFoodLabsInHouse" class="form-control">
                                            <option value="">—</option>
                                            <option value="yes">Sì</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-text">Questo blocco compare solo se tra i servizi selezionati c’è un servizio Food.</div>
                            </div>

                            <div id="planningEstimateWizard17025Advanced" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                                <div style="font-weight:700; margin-bottom:8px;">ISO/IEC 17025 — Avanzato</div>
                                <div class="planning-form-row">
                                    <div class="form-group" style="flex:1;">
                                        <label>Discipline (multi)</label>
                                        <select id="planningEstimateWizard17025Disciplines" class="form-control" multiple size="4">
                                            <option value="chimica">Chimica</option>
                                            <option value="microbiologia">Microbiologia</option>
                                            <option value="tarature">Tarature</option>
                                            <option value="altro">Altro</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>N. metodi</label>
                                        <input id="planningEstimateWizard17025MethodsCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 40" />
                                    </div>
                                </div>
                                <div class="planning-form-row">
                                    <div class="form-group" style="flex:1;">
                                        <label>Campionamento nel perimetro</label>
                                        <select id="planningEstimateWizard17025SamplingInScope" class="form-control">
                                            <option value="unknown">Non so / da valutare</option>
                                            <option value="yes">Sì</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Laboratorio multi-sede</label>
                                        <select id="planningEstimateWizard17025MultiSiteLab" class="form-control">
                                            <option value="">—</option>
                                            <option value="yes">Sì</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-text">Questo blocco compare solo se tra i servizi selezionati c’è ISO/IEC 17025.</div>
                            </div>

                            <div id="planningEstimateWizardCeAdvanced" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                                <div style="font-weight:700; margin-bottom:8px;">Marcatura CE — Avanzato</div>
                                <div class="planning-form-row">
                                    <div class="form-group" style="flex:1;">
                                        <label>Regolamento/Directive (opzionale)</label>
                                        <input id="planningEstimateWizardCeRegulation" class="form-control" placeholder="Es. MDR 2017/745" />
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Classe rischio (opzionale)</label>
                                        <input id="planningEstimateWizardCeRiskClass" class="form-control" placeholder="Es. IIa" />
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Notified Body richiesto</label>
                                    <select id="planningEstimateWizardCeNotifiedBodyRequired" class="form-control">
                                        <option value="unknown">Non so / da valutare</option>
                                        <option value="yes">Sì</option>
                                        <option value="no">No</option>
                                    </select>
                                </div>
                                <div class="form-text">Questo blocco compare solo se tra i servizi selezionati c’è CE.</div>
                            </div>

                            <div id="planningEstimateWizardGdprAdvanced" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                                <div style="font-weight:700; margin-bottom:8px;">Privacy / GDPR — Avanzato</div>
                                <div class="planning-form-row">
                                    <div class="form-group">
                                        <label>N. trattamenti</label>
                                        <input id="planningEstimateWizardGdprProcessingActivitiesCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 25" />
                                    </div>
                                    <div class="form-group">
                                        <label>Sistemi IT principali (n.)</label>
                                        <input id="planningEstimateWizardGdprMainItSystemsCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 6" />
                                    </div>
                                </div>
                                <div class="planning-form-row">
                                    <div class="form-group" style="flex:1;">
                                        <label>Dati categorie particolari</label>
                                        <select id="planningEstimateWizardGdprSpecialCategoriesData" class="form-control">
                                            <option value="unknown">Non so / da valutare</option>
                                            <option value="yes">Sì</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Trasferimenti extra-UE</label>
                                        <select id="planningEstimateWizardGdprExtraEuTransfers" class="form-control">
                                            <option value="unknown">Non so / da valutare</option>
                                            <option value="yes">Sì</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-text">Questo blocco compare solo se tra i servizi selezionati c’è Privacy / GDPR.</div>
                            </div>

                            <div id="planningEstimateWizard231Advanced" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                                <div style="font-weight:700; margin-bottom:8px;">ODV / 231 — Avanzato</div>
                                <div class="planning-form-row">
                                    <div class="form-group" style="flex:1;">
                                        <label>MOG già esistente</label>
                                        <select id="planningEstimateWizard231MogExisting" class="form-control">
                                            <option value="unknown">Non so / da valutare</option>
                                            <option value="yes">Sì</option>
                                            <option value="no">No</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Aree di rischio (n.)</label>
                                        <input id="planningEstimateWizard231RiskAreasCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 10" />
                                    </div>
                                    <div class="form-group" style="flex:1;">
                                        <label>Società controllate (n.)</label>
                                        <input id="planningEstimateWizard231SubsidiariesCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 2" />
                                    </div>
                                </div>
                                <div class="form-text">Questo blocco compare solo se tra i servizi selezionati c’è ODV/231.</div>
                            </div>

                            <div id="planningEstimateWizardAccredAdvanced" style="display:none; margin-top: 12px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
                                <div style="font-weight:700; margin-bottom:8px;">Accreditamenti (istituzionale) — Avanzato</div>
                                <div class="planning-form-row">
                                    <div class="form-group">
                                        <label>Unità/Strutture (n.)</label>
                                        <input id="planningEstimateWizardAccredFacilitiesUnitsCount" type="number" min="0" step="1" class="form-control" placeholder="Es. 3" />
                                    </div>
                                    <div class="form-group">
                                        <label>Complessità requisiti regionali</label>
                                        <select id="planningEstimateWizardAccredRegionalRequirementsComplexity" class="form-control">
                                            <option value="">—</option>
                                            <option value="low">Bassa</option>
                                            <option value="medium">Media</option>
                                            <option value="high">Alta</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-text">Questo blocco compare solo se tra i servizi selezionati c’è ACCRED.</div>
                            </div>
                        </div>
                    </details>
                </div>

                <div id="planningEstimateWizardStep4" class="planning-wizard-step" style="display:none;">
                    <div class="planning-actions" style="margin-bottom: 10px;">
                        <button type="button" class="btn btn-primary btn-sm" id="planningEstimateWizardRunEstimateBtn">Calcola stima</button>
                    </div>
                    <div id="planningEstimateWizardEstimateBox" style="display:none; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
                </div>

                <div id="planningEstimateWizardStep5" class="planning-wizard-step" style="display:none;">
                    <div class="planning-muted" style="margin-bottom: 10px;">
                        Verrà creato il piano in bozza e verranno generate automaticamente le attività per fasi. Poi potrai assegnare i consulenti per servizio e creare il calendario (bozza).
                    </div>
                    <div id="planningEstimateWizardSummary" style="display:none; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningEstimateWizardBackBtn">Indietro</button>
                <button type="button" class="btn btn-secondary" id="planningEstimateWizardCancelBtn">Annulla</button>
                <button type="button" class="btn btn-primary" id="planningEstimateWizardNextBtn">Avanti</button>
            </div>
        </div>
    </div>

    <!-- IMS Template Catalog Modal (tenant 28) -->
    <div class="modal" id="planningImsTemplateCatalogModal" style="display:none;">
        <div class="modal-content planning-ims-catalog-modal">
            <div class="modal-header">
                <h2>Catalogo template IMS</h2>
                <button class="modal-close" id="planningImsTemplateCatalogModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-toolbar" style="margin-bottom: 12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                    <div class="planning-actions">
                        <button type="button" class="btn btn-primary btn-sm" id="planningImsTemplateAddBtn">+ Nuovo template</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="planningImsTemplateReloadBtn">Ricarica</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="planningImsInstallIso9001PackBtn" title="Crea/aggiorna i master in /Templates/IMS e installa il pack ISO9001_SGQ_COMPLETO (Manuale + 33 procedure + registri)">Installa pack ISO 9001</button>
                    </div>
                    <div class="planning-actions" style="gap:8px;">
                        <input id="planningImsTemplateSearch" class="form-control" placeholder="Cerca key/titolo/tipo/percorso..." style="min-width: 280px;" />
                        <select id="planningImsTemplatePageSize" class="form-control" style="width: 120px;">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                        </select>
                        <div class="planning-actions" style="gap:6px; align-items:center;">
                            <button type="button" class="btn btn-secondary btn-sm" id="planningImsTemplatePrevPage">‹</button>
                            <span class="planning-muted" id="planningImsTemplatePageInfo" style="font-size:12px; min-width: 120px; text-align:center;">—</span>
                            <button type="button" class="btn btn-secondary btn-sm" id="planningImsTemplateNextPage">›</button>
                        </div>
                        <label class="form-checkbox-label" style="margin:0;">
                            <input type="checkbox" id="planningImsTemplateActiveOnly" class="form-checkbox" checked>
                            <span>Solo attivi</span>
                        </label>
                    </div>
                </div>
                <div id="planningImsTemplateCatalogWarning" class="planning-muted" style="display:none; margin-bottom:10px; padding:10px; border:1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
                <div class="planning-ims-catalog-table-wrap">
                    <table class="planning-table" id="planningImsTemplateCatalogTable">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Titolo</th>
                                <th>Tipo</th>
                                <th>File</th>
                                <th>Sorgente</th>
                                <th>Compilazione</th>
                                <th>Percorso</th>
                                <th>HLS</th>
                                <th>Attivo</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="planningImsTemplateCatalogTbody">
                            <tr><td colspan="10" class="planning-muted">Caricamento...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="planning-muted" style="margin-top:10px; font-size:12px;">
                    Nota: il catalogo contiene solo <strong>metadati</strong> e <strong>clause_refs</strong> (riferimenti), senza testo delle norme ISO/UNI.
                    Per impostare <strong>Schema compilazione</strong> e <strong>AI hint</strong> apri <em>Modifica</em> su un template.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningImsTemplateCatalogModalCancel">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- IMS Template Edit Modal -->
    <div class="modal" id="planningImsTemplateEditModal" style="display:none;">
        <div class="modal-content" style="max-width: 860px;">
            <div class="modal-header">
                <h2 id="planningImsTemplateEditTitle">Template IMS</h2>
                <button class="modal-close" id="planningImsTemplateEditModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-form-row">
                    <div class="form-group" style="flex:1;">
                        <label>template_key *</label>
                        <input id="planningImsTplKey" class="form-control" placeholder="ES: PROC_DOC_CONTROL" />
                        <div class="form-text">Slug stabile (usato per idempotenza e mapping).</div>
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label>Titolo *</label>
                        <input id="planningImsTplTitle" class="form-control" placeholder="Es. Procedura gestione informazioni documentate" />
                    </div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label>doc_type *</label>
                        <select id="planningImsTplDocType" class="form-control">
                            <option value="manual">manual</option>
                            <option value="policy">policy</option>
                            <option value="procedure">procedure</option>
                            <option value="instruction">instruction</option>
                            <option value="form">form</option>
                            <option value="register">register</option>
                            <option value="record">record</option>
                            <option value="plan">plan</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>file_kind *</label>
                        <select id="planningImsTplFileKind" class="form-control">
                            <option value="docx">docx</option>
                            <option value="xlsx">xlsx</option>
                            <option value="pptx">pptx</option>
                            <option value="txt">txt</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>folder_path *</label>
                        <input id="planningImsTplFolderPath" class="form-control" placeholder="/IMS/04-Support/01-Documents" />
                    </div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group" style="flex:1;">
                        <label>filename_template *</label>
                        <input id="planningImsTplFilename" class="form-control" placeholder="Es. Procedura Gestione Documenti" />
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>tags (comma)</label>
                        <input id="planningImsTplTags" class="form-control" placeholder="IMS,HLS,Core" />
                    </div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group" style="flex:1;">
                        <label>doc_code_template (opzionale)</label>
                        <input id="planningImsTplDocCodeTemplate" class="form-control" placeholder="Es. PROC-{{SEQ}} o IMS-PROC-001" />
                        <div class="form-text">Opzionale: codifica documento. Non inserire testo ISO/UNI.</div>
                    </div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label class="form-checkbox-label">
                            <input type="checkbox" id="planningImsTplIsCommon" class="form-checkbox">
                            <span>Documento comune HLS (riusabile multi-norma)</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-checkbox-label">
                            <input type="checkbox" id="planningImsTplIsActive" class="form-checkbox" checked>
                            <span>Attivo</span>
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-checkbox-label">
                        <input type="checkbox" id="planningImsTplUseCopySource" class="form-checkbox">
                        <span>Usa copia modello (tenant 28) invece del placeholder</span>
                    </label>
                    <div class="form-text">Se attivo, in provisioning verrà copiata una copia del file master nel tenant cliente (idempotente).</div>
                </div>
                <input type="hidden" id="planningImsTplSourceFileId" value="">
                <div class="form-group">
                    <label>File modello (tenant 28) (opzionale)</label>
                    <div class="planning-muted" id="planningImsTplSourceFileLabel">Nessun modello selezionato</div>
                    <div class="planning-actions" style="margin-top:8px; flex-wrap:wrap;">
                        <input id="planningImsTplSourceSearch" class="form-control" style="min-width:220px;" placeholder="Cerca file modello..." />
                        <button type="button" class="btn btn-secondary btn-sm" id="planningImsTplSourceSearchBtn">Cerca</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="planningImsTplEnsureMasterFolderBtn">Crea cartella /Templates/IMS</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="planningImsTplOpenSourceBtn" style="display:none;">Apri modello</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="planningImsTplClearSourceBtn">Rimuovi</button>
                    </div>
                    <div id="planningImsTplSourceSearchResults" style="margin-top:10px;"></div>
                    <div class="form-text">La ricerca avviene nella cartella master <code>/Templates/IMS</code> del tenant 28.</div>
                </div>
                <div class="form-group">
                    <label>clause_refs_json (object per standard)</label>
                    <textarea id="planningImsTplClauseRefs" class="form-control" rows="6" placeholder='{"ISO9001":["7.5"],"ISO14001":["7.5"]}'></textarea>
                    <div class="form-text">Solo riferimenti clausole, nessun testo norma.</div>
                </div>
                <div class="form-group">
                    <label>Schema compilazione (JSON) (opzionale)</label>
                    <textarea id="planningImsTplInputSchema" class="form-control" rows="8" placeholder='{"sections":[{"title":"Dati base","fields":[{"key":"company_name","label":"Ragione sociale","type":"text","required":true,"ai":false}]}]}'></textarea>
                    <div class="form-text">
                        Definisce i campi della “Compilazione guidata”. Regola placeholder: <code>{{key}}</code>.
                        Nessun testo ISO/UNI.
                    </div>
                </div>
                <div class="form-group">
                    <label>AI hint (opzionale)</label>
                    <textarea id="planningImsTplAiHint" class="form-control" rows="4" placeholder="Indicazioni per l'AI su stile/contesto del documento..."></textarea>
                    <div class="form-text">Solo istruzioni operative generiche. Non inserire testo ISO/UNI.</div>
                </div>
                <div id="planningImsTplEditMsg" class="planning-muted" style="display:none; margin-top:10px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningImsTemplateEditCancelBtn">Annulla</button>
                <button type="button" class="btn btn-primary" id="planningImsTemplateEditSaveBtn">Salva</button>
            </div>
        </div>
    </div>

    <!-- IMS Modules Modal (tenant 28) -->
    <div class="modal" id="planningImsModulesModal" style="display:none;">
        <div class="modal-content" style="max-width: 1100px;">
            <div class="modal-header">
                <h2>Moduli IMS</h2>
                <button class="modal-close" id="planningImsModulesModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-toolbar" style="margin-bottom: 12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                    <div class="planning-actions">
                        <button type="button" class="btn btn-primary btn-sm" id="planningImsModuleAddBtn">+ Nuovo modulo</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="planningImsModulesReloadBtn">Ricarica</button>
                    </div>
                    <div class="planning-actions" style="gap:8px;">
                        <label class="form-checkbox-label" style="margin:0;">
                            <input type="checkbox" id="planningImsModulesActiveOnly" class="form-checkbox" checked>
                            <span>Solo attivi</span>
                        </label>
                    </div>
                </div>
                <div id="planningImsModulesWarning" class="planning-muted" style="display:none; margin-bottom:10px; padding:10px; border:1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);"></div>
                <div style="overflow-x:auto;">
                    <table class="planning-table">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Titolo</th>
                                <th>Standards</th>
                                <th>Attivo</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="planningImsModulesTbody">
                            <tr><td colspan="5" class="planning-muted">Caricamento...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningImsModulesModalCancel">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- IMS Module Edit Modal -->
    <div class="modal" id="planningImsModuleEditModal" style="display:none;">
        <div class="modal-content" style="max-width: 860px;">
            <div class="modal-header">
                <h2 id="planningImsModuleEditTitle">Modulo IMS</h2>
                <button class="modal-close" id="planningImsModuleEditModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="planningImsModuleEditingKey" value="">
                <div class="planning-form-row">
                    <div class="form-group" style="flex:1;">
                        <label>module_key *</label>
                        <input id="planningImsModuleKey" class="form-control" placeholder="ES: IMS_CORE_HLS" />
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label>Titolo *</label>
                        <input id="planningImsModuleTitle" class="form-control" placeholder="Es. IMS Core (HLS)" />
                    </div>
                </div>
                <div class="form-group">
                    <label>Descrizione (opzionale)</label>
                    <textarea id="planningImsModuleDesc" class="form-control" rows="3" placeholder="Descrizione sintetica..."></textarea>
                </div>
                <div class="planning-form-row">
                    <div class="form-group" style="flex:1;">
                        <label>Standards (comma)</label>
                        <input id="planningImsModuleStandards" class="form-control" placeholder="ISO9001,ISO14001" />
                        <div class="form-text">Codici standard (ISO9001, ISO14001...).</div>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Tags (comma)</label>
                        <input id="planningImsModuleTags" class="form-control" placeholder="IMS,HLS,Core" />
                    </div>
                </div>
                <div class="planning-form-row">
                    <div class="form-group">
                        <label class="form-checkbox-label">
                            <input type="checkbox" id="planningImsModuleIsActive" class="form-checkbox" checked>
                            <span>Attivo</span>
                        </label>
                    </div>
                </div>

                <div class="section-divider" style="margin: 14px 0; border-top: 1px solid var(--color-gray-200);"></div>
                <div style="display:flex; align-items:center; justify-content:space-between; gap: 12px; flex-wrap:wrap;">
                    <div style="font-weight:600;">Template nel modulo</div>
                    <div class="planning-actions" style="gap:8px;">
                        <input id="planningImsModuleTplSearch" class="form-control" style="min-width:220px;" placeholder="Cerca template (key/titolo)..." />
                        <button type="button" class="btn btn-secondary btn-sm" id="planningImsModuleTplSearchBtn">Cerca</button>
                    </div>
                </div>
                <div id="planningImsModuleTplSearchResults" style="margin-top:10px;"></div>
                <div style="overflow-x:auto; margin-top:10px;">
                    <table class="planning-table">
                        <thead>
                            <tr>
                                <th>Key</th>
                                <th>Titolo</th>
                                <th>Required</th>
                                <th>Ordine</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="planningImsModuleItemsTbody">
                            <tr><td colspan="5" class="planning-muted">Caricamento...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="planningImsModuleEditMsg" class="planning-muted" style="display:none; margin-top:10px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="planningImsModuleEditCancelBtn">Annulla</button>
                <button type="button" class="btn btn-primary" id="planningImsModuleEditSaveBtn">Salva</button>
            </div>
        </div>
    </div>

    <!-- AI Data Collection Assistant Modal -->
    <div class="modal" id="planningAssistantModal" style="display:none;">
        <div class="modal-content planning-assistant-modal" style="max-width: 1280px;">
            <div class="modal-header">
                <h2>Raccolta dati / Gap analysis (AI)</h2>
                <button class="modal-close" id="planningAssistantModalClose">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
            <div class="modal-body">
                <div class="planning-toolbar" style="margin-bottom: 12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; justify-content:space-between;">
                    <div class="planning-actions">
                        <button type="button" class="btn btn-secondary btn-sm" id="planningAssistantDocsReindexBtn">Aggiorna indicizzazione</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="planningAssistantDocsAnalyzeBtn">Analizza documenti</button>
                        <button type="button" class="btn btn-primary btn-sm" id="planningAssistantStartBtn">Avvia intervista</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="planningAssistantExportBtn">Esporta XLSX</button>
                    </div>
                    <div class="planning-muted" id="planningAssistantStatusMsg">Seleziona un piano e avvia l’intervista.</div>
                </div>

                <div class="planning-assistant-layout">
                    <div class="planning-assistant-checklist">
                        <div id="planningAssistantDocSummaryBox" class="planning-muted" style="margin-bottom:10px; padding:10px; border:1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50); display:none;"></div>
                        <div id="planningAssistantChecklistTable" style="overflow:auto;"></div>
                    </div>
                    <div class="planning-assistant-chat">
                        <div id="planningAssistantChatMessages" class="planning-assistant-chat-messages"></div>
                        <div class="planning-assistant-chat-input">
                            <textarea id="planningAssistantChatInput" class="form-control" rows="3" placeholder="Rispondi o aggiungi dettagli..."></textarea>
                            <div class="planning-actions" style="margin-top:8px; justify-content:flex-end; gap:8px;">
                                <button type="button" class="btn btn-secondary btn-sm" id="planningAssistantNextQuestionBtn">Prossima domanda</button>
                                <button type="button" class="btn btn-primary btn-sm" id="planningAssistantChatSendBtn">Invia</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="planningToast" class="toast"></div>

    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" id="planningCurrentUserId" value="<?php echo htmlspecialchars((string)($currentUser['id'] ?? '')); ?>">
    <input type="hidden" id="planningLoginNonce" value="<?php echo htmlspecialchars((string)($_SESSION['login_nonce'] ?? '')); ?>">
    <script src="assets/js/planning.js?v=<?php echo htmlspecialchars((string)$planningJsVersion); ?>"></script>

    <!-- Progress overlay for long operations (AI generation / schedule) -->
    <div id="planningProgressOverlay" class="planning-progress-overlay" style="display:none;">
        <div class="planning-progress-box">
            <div id="planningProgressText" class="planning-progress-title">Operazione in corso…</div>
            <div class="planning-progress-track">
                <div id="planningProgressBar" class="planning-progress-bar" style="width:0%"></div>
            </div>
            <div class="planning-progress-meta">
                <div id="planningProgressPct">0%</div>
                <div>Non chiudere la pagina</div>
            </div>
        </div>
    </div>
<?php require __DIR__ . '/includes/layout_end.php'; ?>


