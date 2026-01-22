<?php
// Compliance dashboard (tenant-scoped; consultants can use CompanyFilter)
declare(strict_types=1);

require_once __DIR__ . '/includes/session_init.php';
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';

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
requireTenantAccess($currentUser['id'], $currentUser['role']);

require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('compliance');

require_once __DIR__ . '/includes/audit_page_access.php';
trackPageAccess('compliance');

$companyFilter = new CompanyFilter($currentUser);
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Sistema documentale - Nexio';
    $pageCss = [
        'assets/css/compliance.css?v=' . (string)filemtime(__DIR__ . '/assets/css/compliance.css'),
    ];
    require __DIR__ . '/includes/layout_head.php';
?>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars((string)$csrfToken); ?>">
    <input type="hidden" id="currentUserId" value="<?php echo htmlspecialchars((string)($currentUser['id'] ?? '')); ?>">
    <input type="hidden" id="userRole" value="<?php echo htmlspecialchars((string)($currentUser['role'] ?? '')); ?>">
    <input type="hidden" id="currentTenantId" value="<?php echo htmlspecialchars((string)($currentUser['tenant_id'] ?? '')); ?>">

    <header class="header">
        <div class="header-left">
            <button class="sidebar-toggle" id="sidebarToggle">☰</button>
            <h1 class="header-title">Sistema documentale</h1>
            <?php if ($companyFilter->canUseCompanyFilter()): ?>
                <?php echo $companyFilter->renderDropdown(['no_styles' => true]); ?>
            <?php endif; ?>
        </div>
    </header>

    <main class="compliance-wrap">
        <div class="compliance-card">
            <div class="compliance-card-title">Programmi</div>
            <div class="compliance-row">
                <div class="compliance-field">
                    <label>Programma</label>
                    <select id="complianceProgramSelect" class="compliance-select">
                        <option value="">Caricamento…</option>
                    </select>
                </div>
                <div class="compliance-field" style="display:flex; align-items:flex-end;">
                    <label style="margin-right:10px; opacity:0;">.</label>
                    <button type="button" class="btn btn-secondary btn-sm" id="complianceProgramProfileBtn" disabled>Profilo azienda</button>
                </div>
                <div class="compliance-kpis" id="complianceKpis" style="display:none;">
                    <div class="compliance-kpi"><div class="kpi-label">Coverage</div><div class="kpi-value" id="kpiCoverage">—</div></div>
                    <div class="compliance-kpi"><div class="kpi-label">Deliverable con doc</div><div class="kpi-value" id="kpiDocs">—</div></div>
                    <div class="compliance-kpi"><div class="kpi-label">Task aperti</div><div class="kpi-value" id="kpiOpenTasks">—</div></div>
                </div>
            </div>
            <div id="complianceStorageWarning" class="compliance-warning" style="display:none;"></div>
        </div>

        <div class="compliance-card">
            <div class="compliance-card-title">Assistente AI (Raccolta dati &amp; Gap)</div>
            <div class="compliance-muted" style="margin-bottom:10px;">
                Chat guidata + checklist tabellare con evidenze (best-effort, nessun testo ISO/UNI).
            </div>
            <div class="planning-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="complianceOpenAiOnboardingBtn">Apri assistente</button>
                <button type="button" class="btn btn-secondary btn-sm" id="complianceAiReindexBtn">Reindicizza</button>
                <button type="button" class="btn btn-secondary btn-sm" id="complianceAiAnalyzeBtn">Analizza documenti</button>
            </div>
            <div id="complianceAiIndexStatus" class="compliance-muted" style="margin-top:8px;"></div>
        </div>

        <div class="compliance-card">
            <div class="compliance-card-title">Raccolta dati / Gap analysis (AI)</div>
            <div class="compliance-muted" style="margin-bottom:10px;">
                Accesso guidato all’AI Hub per consultare fonti e raccogliere informazioni (nessun testo ISO/UNI).
            </div>
            <div class="planning-actions">
                <a class="btn btn-secondary btn-sm" href="ai.php?context=compliance" target="_blank" rel="noopener">Apri AI Hub</a>
            </div>
        </div>

        <div class="compliance-grid">
            <div class="compliance-card">
                <div class="compliance-card-title">Deliverable</div>
                <div style="overflow-x:auto;">
                    <table class="compliance-table">
                        <thead>
                            <tr>
                                <th>Titolo</th>
                                <th>Tipo</th>
                                <th>Norme</th>
                                <th>Clausole</th>
                                <th>Stato</th>
                                <th>Doc</th>
                                <th>Compila</th>
                                <th>Task</th>
                            </tr>
                        </thead>
                        <tbody id="complianceArtifactsTbody">
                            <tr><td colspan="8" class="compliance-muted">Seleziona un programma…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="compliance-card">
                <div class="compliance-card-title">Requisiti / Coverage</div>
                <div class="compliance-row" style="margin-bottom:10px;">
                    <div class="compliance-field">
                        <label>Norma</label>
                        <select id="complianceCoverageStandardSelect" class="compliance-select" disabled>
                            <option value="">—</option>
                        </select>
                    </div>
                    <div class="compliance-field">
                        <label>Filtro clausola</label>
                        <input id="complianceCoverageClauseFilter" class="compliance-select" placeholder="Es. 7.5" />
                    </div>
                    <div class="compliance-field" style="display:flex; align-items:flex-end;">
                        <label style="margin-right:10px; opacity:0;">.</label>
                        <label style="display:flex; gap:8px; align-items:center; font-size:12px;">
                            <input type="checkbox" id="complianceCoverageOnlyUncovered" />
                            <span>Solo uncovered</span>
                        </label>
                    </div>
                </div>
                <div id="complianceCoverageBox" class="compliance-muted">Seleziona un programma…</div>
            </div>
        </div>
    </main>

    <!-- Modal: Profilo azienda -->
    <div class="compliance-modal" id="complianceProfileModal" style="display:none;">
        <div class="compliance-modal-content" style="max-width: 860px;">
            <div class="compliance-modal-header">
                <h2>Profilo azienda</h2>
                <button type="button" class="compliance-modal-close" data-close="complianceProfileModal" aria-label="Chiudi">×</button>
            </div>
            <div class="compliance-modal-body">
                <div class="compliance-muted" style="margin-bottom:10px;">
                    Dati organizzazione usati per compilazione guidata. Nessun testo ISO/UNI.
                </div>
                <div class="compliance-form-grid">
                    <div class="compliance-form-group">
                        <label>Ragione sociale</label>
                        <input id="complianceProfileCompanyName" class="compliance-input" placeholder="Es. Azienda Ospedaliera ..." />
                    </div>
                    <div class="compliance-form-group">
                        <label>Sedi / siti (1 per riga)</label>
                        <textarea id="complianceProfileSites" class="compliance-input" rows="3"></textarea>
                    </div>
                    <div class="compliance-form-group">
                        <label>Prodotti / servizi (1 per riga)</label>
                        <textarea id="complianceProfileProducts" class="compliance-input" rows="3"></textarea>
                    </div>
                    <div class="compliance-form-group">
                        <label>Processi principali (1 per riga)</label>
                        <textarea id="complianceProfileProcesses" class="compliance-input" rows="3"></textarea>
                    </div>
                    <div class="compliance-form-group">
                        <label>Ruoli / responsabilità (1 per riga)</label>
                        <textarea id="complianceProfileRoles" class="compliance-input" rows="3"></textarea>
                    </div>
                    <div class="compliance-form-group">
                        <label>Note (opzionale)</label>
                        <textarea id="complianceProfileNotes" class="compliance-input" rows="3"></textarea>
                    </div>
                </div>
                <div id="complianceProfileMsg" class="compliance-warning" style="display:none; margin-top:10px;"></div>
            </div>
            <div class="compliance-modal-footer">
                <button type="button" class="btn btn-secondary" data-close="complianceProfileModal">Chiudi</button>
                <button type="button" class="btn btn-primary" id="complianceProfileSaveBtn">Salva</button>
            </div>
        </div>
    </div>

    <!-- Modal: Compilazione guidata documento -->
    <div class="compliance-modal" id="complianceWizardModal" style="display:none;">
        <div class="compliance-modal-content compliance-wizard-modal">
            <div class="compliance-modal-header">
                <h2 id="complianceWizardTitle">Compilazione guidata documento</h2>
                <button type="button" class="compliance-modal-close" data-close="complianceWizardModal" aria-label="Chiudi">×</button>
            </div>
            <div class="compliance-modal-body">
                <div class="compliance-muted" id="complianceWizardMeta" style="margin-bottom:10px;"></div>
                <div id="complianceWizardWarning" class="compliance-warning" style="display:none;"></div>
                <div style="display:flex; justify-content:flex-end; gap:8px; margin-bottom:10px;">
                    <button type="button" class="btn btn-secondary btn-sm" id="complianceWizardChatOpenBtnInline">Chat AI</button>
                </div>
                <div id="complianceWizardFields"></div>

                <div class="section-divider" style="margin: 14px 0; border-top: 1px solid var(--compliance-border);"></div>
                <div id="complianceWizardVersionsBox" style="display:none;">
                    <div style="font-weight:700; margin-bottom:8px;">Versioni (ultime 3)</div>
                    <div id="complianceWizardVersions" class="compliance-muted">Caricamento…</div>
                </div>
            </div>
            <div class="compliance-modal-footer">
                <button type="button" class="btn btn-secondary" data-close="complianceWizardModal">Chiudi</button>
                <button type="button" class="btn btn-secondary" id="complianceWizardAiDraftBtn">Bozza AI</button>
                <button type="button" class="btn btn-secondary" id="complianceWizardSaveBtn">Salva dati</button>
                <button type="button" class="btn btn-primary" id="complianceWizardApplyBtn">Applica al documento</button>
            </div>
        </div>
    </div>

    <!-- AI Onboarding Modal -->
    <div class="compliance-modal" id="complianceAiOnboardingModal" style="display:none;">
        <div class="compliance-modal-content" style="max-width: 1200px;">
            <div class="compliance-modal-header">
                <h2>Assistente AI — Raccolta dati &amp; Gap</h2>
                <button type="button" class="compliance-modal-close" data-close="complianceAiOnboardingModal" aria-label="Chiudi">×</button>
            </div>
            <div class="compliance-modal-body">
                <div class="compliance-row" style="margin-bottom:12px; align-items:flex-end;">
                    <div class="compliance-field" style="min-width:220px;">
                        <label>Template checklist</label>
                        <select id="complianceAiChecklistTemplate" class="compliance-select"></select>
                    </div>
                    <div class="compliance-field" style="display:flex; gap:8px; align-items:flex-end;">
                        <button type="button" class="btn btn-secondary btn-sm" id="complianceAiChecklistLoadBtn">Crea/Apri checklist</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="complianceAiChecklistExportBtn">Esporta XLSX</button>
                    </div>
                </div>

                <div class="compliance-ai-layout">
                    <div class="compliance-ai-chat">
                        <div id="complianceAiChatMessages" class="compliance-ai-chat-messages"></div>
                        <div class="compliance-ai-chat-input">
                            <textarea id="complianceAiChatInput" class="compliance-input" rows="3" placeholder="Scrivi una risposta..."></textarea>
                            <div class="planning-actions" style="margin-top:8px; justify-content:flex-end;">
                                <button type="button" class="btn btn-primary btn-sm" id="complianceAiChatSendBtn">Invia</button>
                            </div>
                        </div>
                    </div>
                    <div class="compliance-ai-checklist">
                        <div id="complianceAiDocSummary" class="compliance-muted" style="margin-bottom:10px; padding:10px; border:1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50); display:none;"></div>
                        <div id="complianceAiChecklistTable" style="overflow:auto;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/compliance.js?v=<?php echo (string)filemtime(__DIR__ . '/assets/js/compliance.js'); ?>"></script>
    <script src="assets/js/compliance_onboarding.js?v=<?php echo (string)filemtime(__DIR__ . '/assets/js/compliance_onboarding.js'); ?>"></script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>

