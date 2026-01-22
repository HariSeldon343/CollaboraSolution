class PlanningApp {
  constructor() {
    this.csrfToken = document.getElementById('csrfToken')?.value || '';
    this.apiBase = window.CN_API_BASE || '/CollaboraNexio/api/';
    if (!this.apiBase.endsWith('/')) this.apiBase += '/';

    this.clients = [];
    this.plans = [];
    this.activePlanId = null;
    this.items = [];
    this.calendarTargets = [];

    this.activityTypes = []; // resolved per active plan client (effective)
    this.consultants = [];
    this.selectedConsultantIds = [];
    this.consultantCapacityCache = null; // { storage_available:boolean, consultants:[] }
    this.consultantCapacityLoadedAt = 0;
    this.blueprintResult = null; // last blueprint response (for export)
    this.blueprintPlanId = null;
    this.lastProvisionProgramId = null;
    this.lastProvisioningSummary = null;
    this.provisionPlanItemsCount = null; // integer when known
    this.provisionPlanItemsCountPlanId = null;
    this.imsTemplates = [];
    this.imsTemplatesLoadedAt = 0;
    this.imsTemplateEditingKey = null;
    this.imsTemplateSearchQuery = '';
    this.imsTemplatePage = 1;
    this.imsTemplatePageSize = 25;
    this.pendingCompanyProfile = null; // draft object from planning modal (sent to provisioning)
    this._bypassCompanyProfilePromptOnce = false;

    this.kmRateDefault = 0.50;
    this.dayRateDefault = 360;
    this.activePlanClientLocations = []; // tenant_locations rows for current plan client (id-based only)
    this.activePlanClientLocationsLoadedForTenant = 0;

    this.scheduleDrafts = [];
    this.sessionExpiredShown = false;
    this.lastAuthError = '';
    this.baseActivitySeedAttempted = false;
    this.activityCatalogTypes = []; // cached list from activity_types.php (base catalog)
    this.toastTimer = null;

    // Guided wizard (AI proposal) state
    this.wizardStep = 1;
    this.wizardCreatedPlanId = null;
    this.wizardProposal = null; // { proposal_title, timeline, confidence, risks, items[] }
    this.wizardProposalItems = []; // editable copy of proposal.items
    this.wizardClientActivityTypes = []; // [{base,effective,has_override}]
    this.wizardClientActivityTypeById = new Map(); // id -> {base,effective}
    this.wizardClientActivityTypeIdByName = new Map(); // lower(name) -> id
    this.wizardConsultantEffortDays = {}; // { [userId]: days }
    this.wizardConsultantEffortDirty = {}; // { [userId]: true } user manually edited value in wizard
    this.wizardLoadedClientTenantId = 0;

    // Estimate wizard (services/norms -> estimate days -> generate items)
    this.estimateWizardStep = 1;
    this.estimateWizardCatalog = []; // base catalog entries (types[])
    this.estimateWizardCatalogLoadedAt = 0;
    this.estimateWizardEstimateJson = null; // object persisted into consulting_plans.estimate_json when available
    this.estimateWizardClientLocations = []; // [{...,_key}]
    this.estimateWizardSelectedLocationKeys = []; // ['id:123', 'legacy:sede_operativa_0', ...]
    this.estimateWizardLocationsLoadedForClient = 0;

    // Estimate wizard sessions (optional server storage + localStorage fallback)
    this.estimateWizardSessionId = 0;
    this.estimateWizardSessionsStorageAvailable = null; // null=unknown, true/false
    this.estimateWizardDraftLastSavedAt = '';
    this.estimateWizardDraftLastSource = '';
    this.estimateWizardDraftLastError = '';
    this._estimateWizardAutoSaveFn = null;
    this._estimateWizardLastServerSaveTs = 0;
    this._estimateWizardLastServerEstimateTs = 0;
    this.estimateWizardDocsLoadedForClient = 0;
    this.estimateWizardDocsSnapshot = null;
    this.estimateWizardDocProfileId = 0;
    this.estimateWizardDocProfile = null;

    // Checklist SGQ (add-on, opt-in)
    this.checklistTemplateKey = ''; // empty = auto-pick by intervention_type
    this.checklistTemplates = [];
    this.checklistTemplatesLoadedAt = 0;
    this.checklistStorageAvailable = null; // null=unknown
    this.checklistObjectiveSupported = null;
    this.checklistEvidenceTableSupported = null;
    this.checklistFilterPhase = '';
    this.checklistFilterStatus = '';
    this.activeChecklist = null;
    this.activeChecklistItems = [];
    this.activeChecklistTemplate = null;
    this.activeChecklistDocProfile = null;
    this.activeChecklistLoadedForPlanId = null;
    this._checklistDebounceTimers = new Map();
    this._checklistObjectiveDebounceTimer = null;

    // AI Data Collection Assistant
    this.assistantSessionId = 0;
    this.assistantMessages = [];
    this.assistantNextQuestion = '';
    this.assistantProviders = [];
    this.assistantProvider = '';
    this.assistantModel = '';
    this.assistantLoading = false;

    // Plan scopes (multi-service / multi-norma)
    this.planScopes = []; // [{activity_type_id, estimated_days, planned_days_override, notes, sort_order}]
    this.planScopeServiceIds = []; // [activity_type_id]
    this.planScopesStorageAvailable = false;
    this.planScopesDerivedFromEstimate = false;

    // Allocation UI draft (per service x consultant)
    this.allocationDraftPlanId = null;
    this.allocationDraft = null; // { [serviceId]: { [userId]: days } }

    // Onboarding / tour
    this.tour = { active: false, stepIdx: 0, steps: [] };

    this.bind();
    this.init();
  }

  setAddItemEnabled(enabled) {
    const btn = document.getElementById('planningAddItemBtn');
    if (!btn) return;
    btn.disabled = !enabled;
    btn.style.opacity = enabled ? '1' : '0.6';
    btn.style.cursor = enabled ? 'pointer' : 'not-allowed';
  }

  autoGrowTextarea(el) {
    try {
      if (!el || String(el.tagName || '').toLowerCase() !== 'textarea') return;
      el.style.height = 'auto';
      const min = 90;
      const max = 280;
      const next = Math.min(max, Math.max(min, el.scrollHeight || 0));
      el.style.height = `${next}px`;
    } catch (_) {}
  }

  bind() {
    // Force plan creation via guided Estimate Wizard (avoid legacy "simple create" modal)
    document.getElementById('planningCreatePlanBtn')?.addEventListener('click', () => this.openEstimateWizard());
    document.getElementById('planningOpenGuidedWizardBtn')?.addEventListener('click', () => this.openEstimateWizard());
    document.getElementById('planningPlanModalClose')?.addEventListener('click', () => this.closePlanModal());
    document.getElementById('planningPlanModalCancel')?.addEventListener('click', () => this.closePlanModal());
    document.getElementById('planningPlanForm')?.addEventListener('submit', (e) => {
      e.preventDefault();
      this.createPlanFromModal();
    });

    document.getElementById('planningClientFilter')?.addEventListener('change', () => this.loadPlans());
    document.getElementById('planningStatusFilter')?.addEventListener('change', () => this.loadPlans());
    document.getElementById('planningSearch')?.addEventListener('input', this.debounce(() => this.loadPlans(), 250));

    document.getElementById('planningAddItemBtn')?.addEventListener('click', () => this.addEmptyItemRow());
    document.getElementById('planningSaveAllBtn')?.addEventListener('click', () => this.saveAllItems());

    // Catalog + consultants modals
    document.getElementById('planningOpenActivityCatalogBtn')?.addEventListener('click', () => this.openActivityCatalogModal());
    document.getElementById('planningWizardOpenCatalogBtn')?.addEventListener('click', () => this.openActivityCatalogModal());
    document.getElementById('planningActivityCatalogModalClose')?.addEventListener('click', () => this.closeModal('planningActivityCatalogModal'));
    document.getElementById('planningActivityCatalogModalCancel')?.addEventListener('click', () => this.closeModal('planningActivityCatalogModal'));
    document.getElementById('planningServiceCatalogSeedBtn')?.addEventListener('click', async () => {
      await this.seedServiceCatalog({ showToast: true });
      await this.loadActivityCatalog();
    });
    document.getElementById('planningServiceCatalogSearch')?.addEventListener('input', this.debounce(() => this.renderActivityCatalog(this.activityCatalogTypes || []), 120));
    document.getElementById('planningServiceCatalogCategory')?.addEventListener('change', () => this.renderActivityCatalog(this.activityCatalogTypes || []));
    document.getElementById('planningServiceCatalogActiveOnly')?.addEventListener('change', () => this.renderActivityCatalog(this.activityCatalogTypes || []));
    document.getElementById('planningActivityTypeAddBtn')?.addEventListener('click', () => this.openActivityTypeModal());
    document.getElementById('planningActivityTypeModalClose')?.addEventListener('click', () => this.closeModal('planningActivityTypeModal'));
    document.getElementById('planningActivityTypeModalCancel')?.addEventListener('click', () => this.closeModal('planningActivityTypeModal'));
    document.getElementById('planningActivityTypeModalSave')?.addEventListener('click', () => this.saveActivityTypeFromModal());
    // Derive weight + call cadence from complexity (catalog)
    const cxSel = document.getElementById('planningActivityTypeComplexityScore');
    if (cxSel && cxSel.dataset?.cnxBound !== '1') {
      cxSel.addEventListener('change', () => {
        const cx = parseInt(String(cxSel.value || '0'), 10) || 0;
        if (cx < 1 || cx > 5) return;
        const weight = 1 + (cx - 1) * (0.5 / 4);
        const callEvery = 90 - (cx - 1) * 15;
        const wEl = document.getElementById('planningActivityTypeWeight');
        const cEl = document.getElementById('planningActivityTypeCallEvery');
        if (wEl) wEl.value = Number.isFinite(weight) ? weight.toFixed(2) : '';
        if (cEl) cEl.value = String(callEvery);
      });
      cxSel.dataset.cnxBound = '1';
    }

    document.getElementById('planningEditConsultantsBtn')?.addEventListener('click', () => this.openConsultantsModal());
    document.getElementById('planningConsultantsModalClose')?.addEventListener('click', () => this.closeModal('planningConsultantsModal'));
    document.getElementById('planningConsultantsModalCancel')?.addEventListener('click', () => this.closeModal('planningConsultantsModal'));
    document.getElementById('planningConsultantsModalSave')?.addEventListener('click', () => this.saveConsultantsForActivePlan());
    document.getElementById('planningConsultantsTabSelectBtn')?.addEventListener('click', () => this.openConsultantsModal());
    document.getElementById('planningConsultantsSelect')?.addEventListener('change', () => this.renderConsultantsHomeCityForm());
    document.getElementById('planningAllocationSuggestBtn')?.addEventListener('click', () => this.suggestAllocationForActivePlan());
    document.getElementById('planningAllocationApplyBtn')?.addEventListener('click', () => this.applyAllocationFromUI());

    // Consultant capacity modal (default availability)
    document.getElementById('planningOpenConsultantCapacityBtn')?.addEventListener('click', () => this.openConsultantCapacityModal());
    document.getElementById('planningConsultantCapacityModalClose')?.addEventListener('click', () => this.closeModal('planningConsultantCapacityModal'));
    document.getElementById('planningConsultantCapacityModalCancel')?.addEventListener('click', () => this.closeModal('planningConsultantCapacityModal'));
    document.getElementById('planningConsultantCapacitySaveBtn')?.addEventListener('click', () => this.saveConsultantCapacitiesFromModal());

    // Schedule proposal
    document.getElementById('planningScheduleReloadBtn')?.addEventListener('click', () => this.loadScheduleDrafts());
    document.getElementById('planningScheduleGenerateBtn')?.addEventListener('click', () => this.generateScheduleDrafts());
    document.getElementById('planningScheduleConfirmBtn')?.addEventListener('click', () => this.confirmScheduleDrafts());

    // Sistema documentale (IMS) — provisioning + link rapidi
    document.getElementById('planningDocProvisionBtn')?.addEventListener('click', () => this.openComplianceProvisionModal());
    document.getElementById('planningDocOpenAiHubBtn')?.addEventListener('click', () => {
      const plan = this.getActivePlan();
      const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
      const url = clientTenantId > 0
        ? (`ai.php?tenant_id=${encodeURIComponent(String(clientTenantId))}&context=planning`)
        : 'ai.php';
      window.open(url, '_blank', 'noopener');
    });
    document.getElementById('planningOpenImsTemplateCatalogBtn')?.addEventListener('click', () => this.openImsTemplateCatalogModal());
    // NOTE: legacy blueprint modal handlers intentionally kept out of UI (feature deprecated in planning)

    // Checklist SGQ (add-on)
    document.getElementById('planningChecklistReloadBtn')?.addEventListener('click', () => this.loadChecklistForActivePlan({ force: true }));
    document.getElementById('planningChecklistCreateBtn')?.addEventListener('click', () => this.createChecklistFromTemplateForActivePlan());
    document.getElementById('planningChecklistAutofillBtn')?.addEventListener('click', () => this.autofillChecklistFromDocsForActivePlan());
    document.getElementById('planningChecklistAssistantBtn')?.addEventListener('click', () => this.openAssistantModal());
    document.getElementById('planningChecklistDocsReindexBtn')?.addEventListener('click', () => this.checklistReindexClientDocs());
    document.getElementById('planningChecklistDocsAnalyzeBtn')?.addEventListener('click', () => this.checklistAnalyzeClientDocs());
    document.getElementById('planningChecklistExportBtn')?.addEventListener('click', () => this.exportChecklistXlsxForActivePlan?.());

    // Checklist UI controls (template/objective/filters)
    document.getElementById('planningChecklistTemplateSelect')?.addEventListener('change', () => this.onChecklistTemplateSelectChanged?.());
    document.getElementById('planningChecklistFilterPhase')?.addEventListener('change', () => {
      this.checklistFilterPhase = String(document.getElementById('planningChecklistFilterPhase')?.value || '').trim();
      this.renderChecklistTab();
    });
    document.getElementById('planningChecklistFilterStatus')?.addEventListener('change', () => {
      this.checklistFilterStatus = String(document.getElementById('planningChecklistFilterStatus')?.value || '').trim();
      this.renderChecklistTab();
    });
    const objEl = document.getElementById('planningChecklistObjectiveText');
    if (objEl && objEl.dataset?.cnxBound !== '1') {
      objEl.addEventListener('input', () => this.onChecklistObjectiveInput?.({ flush: false }));
      objEl.addEventListener('blur', () => this.onChecklistObjectiveInput?.({ flush: true }));
      objEl.dataset.cnxBound = '1';
    }

    // Assistant modal controls
    document.getElementById('planningAssistantModalClose')?.addEventListener('click', () => this.closeAssistantModal());
    document.getElementById('planningAssistantDocsReindexBtn')?.addEventListener('click', () => this.checklistReindexClientDocs());
    document.getElementById('planningAssistantDocsAnalyzeBtn')?.addEventListener('click', () => this.loadAssistantDocContext({ force: true }));
    document.getElementById('planningAssistantStartBtn')?.addEventListener('click', () => this.startAssistantSession());
    document.getElementById('planningAssistantChatSendBtn')?.addEventListener('click', () => this.sendAssistantMessage());
    document.getElementById('planningAssistantNextQuestionBtn')?.addEventListener('click', () => this.sendAssistantMessage({ useNextQuestion: true }));
    document.getElementById('planningAssistantExportBtn')?.addEventListener('click', () => this.exportChecklistXlsxForActivePlan?.());

    // Compliance Provision
    document.getElementById('planningComplianceProvisionModalClose')?.addEventListener('click', () => this.closeModal('planningComplianceProvisionModal'));
    document.getElementById('planningComplianceProvisionModalCancel')?.addEventListener('click', () => this.closeModal('planningComplianceProvisionModal'));
    document.getElementById('planningComplianceProvisionRunBtn')?.addEventListener('click', () => this.runComplianceProvisioningFromModal());
    document.getElementById('planningProvisionEditCompanyProfileBtn')?.addEventListener('click', () => this.openCompanyProfileModal());

    // Company profile modal (pre-provisioning)
    document.getElementById('planningCompanyProfileModalClose')?.addEventListener('click', () => this.closeModal('planningCompanyProfileModal'));
    document.getElementById('planningCompanyProfileSkipBtn')?.addEventListener('click', () => this.skipCompanyProfileAndContinueProvisioning());
    document.getElementById('planningCompanyProfileSaveContinueBtn')?.addEventListener('click', () => this.saveCompanyProfileAndContinueProvisioning());

    // IMS Template catalog (tenant 28)
    document.getElementById('planningImsTemplateCatalogModalClose')?.addEventListener('click', () => this.closeModal('planningImsTemplateCatalogModal'));
    document.getElementById('planningImsTemplateCatalogModalCancel')?.addEventListener('click', () => this.closeModal('planningImsTemplateCatalogModal'));
    document.getElementById('planningImsTemplateReloadBtn')?.addEventListener('click', () => this.loadImsTemplateCatalog({ force: true }));
    document.getElementById('planningImsTemplateAddBtn')?.addEventListener('click', () => this.openImsTemplateEditModal(null));
    document.getElementById('planningImsTemplateActiveOnly')?.addEventListener('change', () => this.loadImsTemplateCatalog({ force: true }));
    document.getElementById('planningImsInstallIso9001PackBtn')?.addEventListener('click', () => this.installIso9001TemplatePack());
    document.getElementById('planningImsTemplateSearch')?.addEventListener('input', this.debounce(() => {
      this.imsTemplateSearchQuery = String(document.getElementById('planningImsTemplateSearch')?.value || '').trim();
      this.imsTemplatePage = 1;
      this.renderImsTemplateCatalog();
    }, 180));
    document.getElementById('planningImsTemplatePageSize')?.addEventListener('change', () => {
      const v = parseInt(String(document.getElementById('planningImsTemplatePageSize')?.value || '25'), 10) || 25;
      this.imsTemplatePageSize = Math.max(5, Math.min(200, v));
      this.imsTemplatePage = 1;
      this.renderImsTemplateCatalog();
    });
    document.getElementById('planningImsTemplatePrevPage')?.addEventListener('click', () => {
      this.imsTemplatePage = Math.max(1, (this.imsTemplatePage || 1) - 1);
      this.renderImsTemplateCatalog();
    });
    document.getElementById('planningImsTemplateNextPage')?.addEventListener('click', () => {
      this.imsTemplatePage = (this.imsTemplatePage || 1) + 1;
      this.renderImsTemplateCatalog();
    });

    document.getElementById('planningImsTemplateEditModalClose')?.addEventListener('click', () => this.closeModal('planningImsTemplateEditModal'));
    document.getElementById('planningImsTemplateEditCancelBtn')?.addEventListener('click', () => this.closeModal('planningImsTemplateEditModal'));
    document.getElementById('planningImsTemplateEditSaveBtn')?.addEventListener('click', () => this.saveImsTemplateFromModal());

    // IMS Modules (tenant 28)
    document.getElementById('planningOpenImsModulesBtn')?.addEventListener('click', () => this.openImsModulesModal());
    document.getElementById('planningImsModulesModalClose')?.addEventListener('click', () => this.closeModal('planningImsModulesModal'));
    document.getElementById('planningImsModulesModalCancel')?.addEventListener('click', () => this.closeModal('planningImsModulesModal'));
    document.getElementById('planningImsModulesReloadBtn')?.addEventListener('click', () => this.loadImsModules({ force: true }));
    document.getElementById('planningImsModulesActiveOnly')?.addEventListener('change', () => this.loadImsModules({ force: true }));
    document.getElementById('planningImsModuleAddBtn')?.addEventListener('click', () => this.openImsModuleEditModal(null));

    document.getElementById('planningImsModuleEditModalClose')?.addEventListener('click', () => this.closeModal('planningImsModuleEditModal'));
    document.getElementById('planningImsModuleEditCancelBtn')?.addEventListener('click', () => this.closeModal('planningImsModuleEditModal'));
    document.getElementById('planningImsModuleEditSaveBtn')?.addEventListener('click', () => this.saveImsModuleFromModal());
    document.getElementById('planningImsModuleTplSearchBtn')?.addEventListener('click', () => this.searchTemplatesForModule());

    // IMS source file selection (tenant 28)
    document.getElementById('planningImsTplSourceSearchBtn')?.addEventListener('click', () => this.searchImsSourceFiles());
    document.getElementById('planningImsTplEnsureMasterFolderBtn')?.addEventListener('click', () => this.ensureImsMasterFolder());
    document.getElementById('planningImsTplClearSourceBtn')?.addEventListener('click', () => this.clearImsSourceFileSelection());
    document.getElementById('planningImsTplOpenSourceBtn')?.addEventListener('click', () => this.openSelectedImsSourceFile());

    // Onboarding actions
    document.getElementById('planningShowTourBtn')?.addEventListener('click', () => this.startPlanningTour({ force: true }));
    document.getElementById('planningOnboardingTourBtn')?.addEventListener('click', () => this.startPlanningTour({ force: true }));
    document.getElementById('planningOnboardingWizardBtn')?.addEventListener('click', () => this.openEstimateWizard());
    document.getElementById('planningOnboardingCapacityBtn')?.addEventListener('click', () => this.openConsultantCapacityModal());
    document.getElementById('planningOnboardingOpenImsTemplateCatalogBtn')?.addEventListener('click', () => document.getElementById('planningOpenImsTemplateCatalogBtn')?.click());
    document.getElementById('planningOnboardingOpenImsModulesBtn')?.addEventListener('click', () => document.getElementById('planningOpenImsModulesBtn')?.click());
    document.getElementById('planningOnboardingProvisionBtn')?.addEventListener('click', () => this.openComplianceProvisionModal());

    // Next steps shortcuts
    document.getElementById('planningNextStepsTourBtn')?.addEventListener('click', () => this.startPlanningTour({ force: true }));
    document.getElementById('planningNextStepsEditConsultantsBtn')?.addEventListener('click', () => document.getElementById('planningEditConsultantsBtn')?.click());
    document.getElementById('planningNextStepsCapacityBtn')?.addEventListener('click', () => document.getElementById('planningOpenConsultantCapacityBtn')?.click());
    document.getElementById('planningNextStepsAddItemBtn')?.addEventListener('click', () => document.getElementById('planningAddItemBtn')?.click());
    document.getElementById('planningNextStepsSaveBtn')?.addEventListener('click', () => document.getElementById('planningSaveAllBtn')?.click());
    document.getElementById('planningNextStepsScheduleBtn')?.addEventListener('click', () => document.getElementById('planningScheduleGenerateBtn')?.click());
    document.getElementById('planningNextStepsProvisionBtn')?.addEventListener('click', () => this.openComplianceProvisionModal());
    document.getElementById('planningNextStepsOpenImsTemplateCatalogBtn')?.addEventListener('click', () => document.getElementById('planningOpenImsTemplateCatalogBtn')?.click());
    document.getElementById('planningNextStepsOpenImsModulesBtn')?.addEventListener('click', () => document.getElementById('planningOpenImsModulesBtn')?.click());
    document.getElementById('planningNextStepsOpenComplianceBtn')?.addEventListener('click', () => {
      const plan = this.getActivePlan();
      const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
      const url = clientTenantId > 0
        ? (`compliance.php?tenant_id=${encodeURIComponent(String(clientTenantId))}`)
        : 'compliance.php';
      window.open(url, '_blank', 'noopener');
    });
    document.getElementById('planningNextStepsOpenAiHubBtn')?.addEventListener('click', () => {
      const plan = this.getActivePlan();
      const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
      const url = clientTenantId > 0
        ? (`ai.php?tenant_id=${encodeURIComponent(String(clientTenantId))}&context=planning`)
        : 'ai.php';
      window.open(url, '_blank', 'noopener');
    });

    // Progress panel (best-effort)
    document.getElementById('planningPlanProgressRefreshBtn')?.addEventListener('click', () => this.refreshPlanProgress({ force: true }));

    // Guided wizard
    document.getElementById('planningGuidedWizardModalClose')?.addEventListener('click', () => this.closeGuidedWizard());
    document.getElementById('planningWizardCancelBtn')?.addEventListener('click', () => this.closeGuidedWizard());
    document.getElementById('planningWizardBackBtn')?.addEventListener('click', () => this.wizardPrev());
    document.getElementById('planningWizardNextBtn')?.addEventListener('click', () => this.wizardNext());
    document.getElementById('planningWizardGenerateBtn')?.addEventListener('click', () => this.wizardGenerateProposal());
    document.getElementById('planningWizardAddRowBtn')?.addEventListener('click', () => this.wizardAddProposalRow());

    document.getElementById('planningWizardClient')?.addEventListener('change', async () => {
      const clientId = parseInt(document.getElementById('planningWizardClient')?.value || '0', 10) || 0;
      await this.wizardLoadActivityTypesForClient(clientId);
      this.wizardResetObjectiveOverrides();
      this.wizardRenderObjectiveOptions();
    });
    document.getElementById('planningWizardObjectiveType')?.addEventListener('change', () => this.wizardApplyObjectiveDefaultsFromSelection());
    document.getElementById('planningWizardConsultants')?.addEventListener('change', async () => {
      try {
        await this.wizardMaybePrefillConsultantCapacity();
      } catch (e) {
        // best-effort
      }
      this.wizardRenderConsultantEfforts();
    });

    // Estimate wizard (services/norms -> estimate -> generate items)
    document.getElementById('planningEstimateWizardModalClose')?.addEventListener('click', () => this.closeEstimateWizard());
    document.getElementById('planningEstimateWizardCancelBtn')?.addEventListener('click', () => this.closeEstimateWizard());
    document.getElementById('planningEstimateWizardBackBtn')?.addEventListener('click', () => this.estimateWizardPrev());
    document.getElementById('planningEstimateWizardNextBtn')?.addEventListener('click', () => this.estimateWizardNext());
    document.getElementById('planningEstimateWizardRunEstimateBtn')?.addEventListener('click', () => this.estimateWizardRunEstimate());
    document.getElementById('planningEstimateWizardDocsReindexBtn')?.addEventListener('click', () => this.estimateWizardReindexClientDocs());
    document.getElementById('planningEstimateWizardDocsAnalyzeBtn')?.addEventListener('click', () => this.estimateWizardAnalyzeClientDocs());
  }

  enableClickToggleMultiSelect(selectEl) {
    if (!selectEl || selectEl.dataset?.cnxClickToggle === '1') return;
    if (!selectEl.multiple) return;
    // Allow multi-select with simple clicks (no Ctrl) by toggling option selection on mousedown.
    selectEl.addEventListener('mousedown', (e) => {
      const opt = e.target && e.target.tagName === 'OPTION' ? e.target : null;
      if (!opt) return;
      e.preventDefault();
      opt.selected = !opt.selected;
      // Dispatch change so existing listeners run
      selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    });
    selectEl.dataset.cnxClickToggle = '1';
  }

  async loadConsultantCapacityList(options = {}) {
    const force = !!options.force;
    const maxAgeMs = 60_000; // 1 minute cache
    if (!force && this.consultantCapacityCache && (Date.now() - this.consultantCapacityLoadedAt) < maxAgeMs) {
      return this.consultantCapacityCache;
    }
    const res = await this.apiFetch(`consulting_plans/consultant_capacity.php?action=list`, {
      method: 'GET',
      json: true,
    });
    const storageAvailable = !!res.data?.storage_available;
    const consultants = Array.isArray(res.data?.consultants) ? res.data.consultants : [];
    this.consultantCapacityCache = { storage_available: storageAvailable, consultants };
    this.consultantCapacityLoadedAt = Date.now();
    return this.consultantCapacityCache;
  }

  async openConsultantCapacityModal() {
    this.openModal('planningConsultantCapacityModal');
    await this.renderConsultantCapacityModal();
  }

  async renderConsultantCapacityModal() {
    const tbody = document.getElementById('planningConsultantCapacityTbody');
    const warn = document.getElementById('planningConsultantCapacityStorageWarning');
    const saveBtn = document.getElementById('planningConsultantCapacitySaveBtn');
    if (tbody) tbody.innerHTML = `<tr><td colspan="3" class="planning-muted">Caricamento...</td></tr>`;
    if (warn) warn.style.display = 'none';
    if (saveBtn) saveBtn.disabled = true;

    try {
      const data = await this.loadConsultantCapacityList({ force: true });
      const storageAvailable = !!data.storage_available;
      const consultants = Array.isArray(data.consultants) ? data.consultants : [];

      if (warn) warn.style.display = storageAvailable ? 'none' : 'block';
      if (saveBtn) saveBtn.disabled = !storageAvailable;

      if (!tbody) return;
      if (!consultants.length) {
        tbody.innerHTML = `<tr><td colspan="3" class="planning-muted">Nessun consulente trovato</td></tr>`;
        return;
      }

      tbody.innerHTML = consultants.map(c => {
        const id = parseInt(c.id || '0', 10) || 0;
        const name = String(c.name || c.email || `#${id}`);
        const days = (c.available_days === null || c.available_days === undefined) ? '' : String(c.available_days);
        const notes = (c.notes === null || c.notes === undefined) ? '' : String(c.notes);
        const canEdit = !!c.can_edit;
        const disabled = (!storageAvailable || !canEdit) ? 'disabled' : '';
        return `
          <tr data-user-id="${id}">
            <td>
              <div style="font-weight:600;">${this.escapeHtml(name)}</div>
              <div class="planning-muted" style="margin-top:2px;">${this.escapeHtml(String(c.email || ''))}</div>
            </td>
            <td>
              <input class="form-control" type="number" min="0" step="0.5" data-field="available_days" value="${this.escapeAttr(days)}" ${disabled} />
            </td>
            <td>
              <input class="form-control" type="text" maxlength="255" data-field="notes" value="${this.escapeAttr(notes)}" ${disabled} />
            </td>
          </tr>
        `;
      }).join('');
    } catch (e) {
      console.error('[ConsultantCapacity] render error:', e);
      if (tbody) tbody.innerHTML = `<tr><td colspan="3" class="planning-muted">Errore caricamento</td></tr>`;
      if (warn) warn.style.display = 'none';
      if (saveBtn) saveBtn.disabled = true;
      this.toast('Errore caricamento disponibilità consulenti', 'error');
    }
  }

  async saveConsultantCapacitiesFromModal() {
    const tbody = document.getElementById('planningConsultantCapacityTbody');
    if (!tbody) return;
    const rows = [...tbody.querySelectorAll('tr[data-user-id]')];
    if (!rows.length) return;

    // Only collect editable inputs (non-disabled)
    const entries = [];
    for (const tr of rows) {
      const uid = parseInt(tr.getAttribute('data-user-id') || '0', 10) || 0;
      if (!uid) continue;
      const daysEl = tr.querySelector('input[data-field="available_days"]');
      const notesEl = tr.querySelector('input[data-field="notes"]');
      if (!daysEl || !notesEl) continue;
      if (daysEl.disabled && notesEl.disabled) continue;

      const days = parseFloat(daysEl.value || '0') || 0;
      const notes = (notesEl.value || '').trim();
      entries.push({ user_id: uid, available_days: days, notes });
    }

    if (!entries.length) {
      this.toast('Nessuna riga modificabile da salvare', 'info');
      return;
    }

    try {
      const res = await this.apiFetch(`consulting_plans/consultant_capacity.php?action=save`, {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, entries }),
      });
      const storageAvailable = !!res.data?.storage_available;
      if (!storageAvailable) {
        this.toast('Storage non disponibile: disponibilità non salvata', 'warning');
      } else {
        this.toast('Disponibilità salvata', 'success');
      }
      // Refresh cache + modal view
      this.consultantCapacityCache = null;
      await this.renderConsultantCapacityModal();
    } catch (e) {
      console.error('[ConsultantCapacity] save error:', e);
      this.toast('Errore salvataggio disponibilità', 'error');
    }
  }

  async wizardMaybePrefillConsultantCapacity() {
    const sel = document.getElementById('planningWizardConsultants');
    const ids = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];
    if (!ids.length) return;

    let data;
    try {
      data = await this.loadConsultantCapacityList();
    } catch (e) {
      return; // fallback to manual
    }
    if (!data || !data.storage_available) return;
    const consultants = Array.isArray(data.consultants) ? data.consultants : [];
    const byId = new Map(consultants.map(c => [parseInt(c.id || '0', 10) || 0, c]));

    for (const id of ids) {
      // Only override if user has NOT manually changed value in wizard
      if (this.wizardConsultantEffortDirty[id]) continue;
      const c = byId.get(id) || null;
      const v = c && c.available_days !== null && c.available_days !== undefined ? parseFloat(String(c.available_days)) : NaN;
      if (Number.isFinite(v)) {
        this.wizardConsultantEffortDays[id] = v;
      }
    }
  }

  async init() {
    await this.loadClients();
    await this.loadCalendarTargets();
    await this.loadPlans();
    // Preload consultants list for "create plan" modal
    await this.loadConsultantsListForCreate();

    // Onboarding: show on first access and start tour once
    this.updateOnboardingVisibility();
    this.startPlanningTour({ force: false });

    // Tabs (Activities / Schedule / Compliance)
    try { this.initPlanningTabs?.(); } catch (_) {}
  }

  updateOnboardingVisibility() {
    const wrap = document.getElementById('planningOnboarding');
    if (!wrap) return;
    const noPlans = !Array.isArray(this.plans) || this.plans.length === 0;
    const noActive = !this.activePlanId;
    const show = noPlans || noActive;
    wrap.style.display = show ? 'block' : 'none';

    // Provision CTA in onboarding is enabled only if a plan is selected
    const btn = document.getElementById('planningOnboardingProvisionBtn');
    const hint = document.getElementById('planningOnboardingProvisionHint');
    if (btn) btn.disabled = !this.activePlanId;
    if (hint) hint.textContent = this.activePlanId ? 'Provisioning sul tenant cliente del piano selezionato.' : 'Seleziona prima un piano.';

    // Next steps appear only when a plan is selected
    const next = document.getElementById('planningNextSteps');
    if (next) next.style.display = this.activePlanId ? 'block' : 'none';
    const consHint = document.getElementById('planningNextStepsConsultantsHint');
    if (consHint) {
      if (!this.selectedConsultantIds || !this.selectedConsultantIds.length) {
        consHint.innerHTML = 'Seleziona i consulenti per calendario e assegnazioni.';
      } else {
        consHint.innerHTML = `Consulenti selezionati: <strong>${this.escapeHtml(String(this.selectedConsultantIds.length))}</strong>`;
      }
    }
  }

  getTourStorageKey() {
    const uid = (document.getElementById('planningCurrentUserId')?.value || '').trim() || '0';
    const nonce = (document.getElementById('planningLoginNonce')?.value || '').trim() || '';
    const base = `cnx_planning_tour_seen_v1_${uid}`;
    // If nonce exists, scope to login_nonce to avoid cross-login weirdness
    return nonce ? `${base}_${nonce}` : base;
  }

  getTourDisableKey() {
    const uid = (document.getElementById('planningCurrentUserId')?.value || '').trim() || '0';
    return `cnx_planning_tour_disabled_v1_${uid}`;
  }

  startPlanningTour({ force = false } = {}) {
    try {
      const disableKey = this.getTourDisableKey();
      const disabled = localStorage.getItem(disableKey) === '1';
      if (!force && disabled) return;

      const key = this.getTourStorageKey();
      const seen = localStorage.getItem(key) === '1';
      if (!force && seen) return;

      // Build steps lazily to ensure DOM is ready
      this.tour.steps = [
        {
          title: '1) Piani',
          body: 'Qui trovi i piani esistenti. Se è vuoto, crea un nuovo piano per iniziare.',
          selector: '#planningCreatePlanBtn',
        },
        {
          title: '2) Crea piano (stima)',
          body: 'Wizard stima guidata: scegli cliente, servizi/norme e profilo azienda. Ottieni una stima giornate e genera le attività per fasi.',
          selector: '#planningOpenGuidedWizardBtn',
        },
        {
          title: '3) Disponibilità consulenti',
          body: 'Imposta le giornate disponibili “default”. Nel wizard Step 3 puoi fare override per piano e ripristinare il default.',
          selector: '#planningOpenConsultantCapacityBtn',
        },
        {
          title: '4) Sviluppo sistema documentale',
          body: 'Provisiona la struttura /IMS e i documenti nel tenant cliente. Template e moduli si gestiscono da catalogo IMS.',
          selector: '#planningDocProvisionBtn',
        },
      ];

      this.tour.active = true;
      this.tour.stepIdx = 0;
      this.renderTourOverlay();

      // Mark seen if not forced
      if (!force) localStorage.setItem(key, '1');
    } catch (e) {
      // If localStorage is blocked, just skip the tour silently
    }
  }

  endPlanningTour() {
    this.tour.active = false;
    const el = document.getElementById('planningTourOverlay');
    if (el) el.remove();
  }

  renderTourOverlay() {
    if (!this.tour.active) return;
    const existing = document.getElementById('planningTourOverlay');
    if (existing) existing.remove();

    const step = this.tour.steps[this.tour.stepIdx] || null;
    if (!step) return this.endPlanningTour();

    const target = document.querySelector(step.selector);
    const rect = target ? target.getBoundingClientRect() : null;

    const overlay = document.createElement('div');
    overlay.id = 'planningTourOverlay';
    overlay.className = 'planning-tour-overlay';

    const highlight = document.createElement('div');
    highlight.className = 'planning-tour-highlight';
    if (rect) {
      const pad = 6;
      highlight.style.left = `${Math.max(0, rect.left - pad)}px`;
      highlight.style.top = `${Math.max(0, rect.top - pad)}px`;
      highlight.style.width = `${Math.max(0, rect.width + pad * 2)}px`;
      highlight.style.height = `${Math.max(0, rect.height + pad * 2)}px`;
    } else {
      highlight.style.display = 'none';
    }

    const box = document.createElement('div');
    box.className = 'planning-tour-box';
    const total = this.tour.steps.length;
    box.innerHTML = `
      <div class="planning-tour-top">
        <div class="planning-tour-title">${this.escapeHtml(step.title || '')}</div>
        <button type="button" class="planning-tour-close" aria-label="Chiudi">×</button>
      </div>
      <div class="planning-tour-body">${this.escapeHtml(step.body || '')}</div>
      <div class="planning-tour-footer">
        <div class="planning-muted">Step ${this.tour.stepIdx + 1}/${total}</div>
        <div class="planning-tour-actions">
          <button type="button" class="btn btn-secondary btn-sm" data-action="disable">Non mostrare più</button>
          <button type="button" class="btn btn-secondary btn-sm" data-action="back" ${this.tour.stepIdx === 0 ? 'disabled' : ''}>Indietro</button>
          <button type="button" class="btn btn-primary btn-sm" data-action="next">${this.tour.stepIdx === total - 1 ? 'Fine' : 'Avanti'}</button>
        </div>
      </div>
    `;

    // Position box near highlight (fallback to center)
    if (rect) {
      const margin = 12;
      const preferredTop = rect.bottom + margin;
      const maxTop = window.innerHeight - 220;
      const top = Math.min(preferredTop, maxTop);
      const left = Math.min(Math.max(margin, rect.left), window.innerWidth - 420);
      box.style.top = `${Math.max(margin, top)}px`;
      box.style.left = `${Math.max(margin, left)}px`;
    }

    overlay.appendChild(highlight);
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    // Events
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) this.endPlanningTour();
    });
    box.querySelector('.planning-tour-close')?.addEventListener('click', () => this.endPlanningTour());
    box.querySelector('[data-action="disable"]')?.addEventListener('click', () => {
      try {
        localStorage.setItem(this.getTourDisableKey(), '1');
      } catch (_) {}
      this.endPlanningTour();
      this.toast('Guida rapida disattivata', 'info');
    });
    box.querySelector('[data-action="back"]')?.addEventListener('click', () => {
      this.tour.stepIdx = Math.max(0, this.tour.stepIdx - 1);
      this.renderTourOverlay();
    });
    box.querySelector('[data-action="next"]')?.addEventListener('click', () => {
      if (this.tour.stepIdx >= this.tour.steps.length - 1) return this.endPlanningTour();
      this.tour.stepIdx += 1;
      this.renderTourOverlay();
    });

    // Keep overlay aligned on resize/scroll
    const onMove = () => {
      if (!this.tour.active) return;
      this.renderTourOverlay();
    };
    window.addEventListener('resize', onMove, { once: true });
    window.addEventListener('scroll', onMove, { once: true, passive: true });
  }

  resetBlueprintView(message = '') {
    const content = document.getElementById('planningBlueprintContent');
    const warn = document.getElementById('planningBlueprintStorageWarning');
    const exportBtn = document.getElementById('planningBlueprintExportBtn');
    const openBtn = document.getElementById('planningBlueprintOpenBtn');
    const editBtn = document.getElementById('planningBlueprintEditInputsBtn');
    const provisionBtn = document.getElementById('planningBlueprintProvisionBtn');
    const tasksBtn = document.getElementById('planningBlueprintTasksBtn');
    this.blueprintResult = null;
    this.blueprintPlanId = null;
    this.lastProvisioningSummary = null;
    try { this.renderLastProvisioningSummary?.(); } catch (_) {}
    if (exportBtn) exportBtn.style.display = 'none';
    if (provisionBtn) provisionBtn.style.display = 'none';
    if (tasksBtn) tasksBtn.style.display = 'none';
    if (editBtn) editBtn.style.display = 'none';
    if (warn) { warn.style.display = 'none'; warn.textContent = ''; }
    if (content) {
      content.innerHTML = message ? this.escapeHtml(message) : 'Seleziona un piano per generare la struttura documentale.';
    }
    if (openBtn) {
      openBtn.disabled = !this.activePlanId;
      openBtn.textContent = 'Genera struttura documentale';
    }
  }

  renderLastProvisioningSummary() {
    const box = document.getElementById('planningProvisionLastSummary');
    if (!box) return;
    const s = this.lastProvisioningSummary;
    if (!s) {
      box.style.display = 'none';
      box.innerHTML = '';
      return;
    }
    const created = s.created || {};
    const reused = s.reused || {};
    const warnings = Array.isArray(s.warnings) ? s.warnings : [];
    const storageAvailable = !!s.storageAvailable;
    box.style.display = 'block';
    const clientTenantId = s.clientTenantId || s.client_tenant_id || 0;
    const clientTid = parseInt(clientTenantId, 10) || 0;
    const filesHref = clientTid ? `files.php?tenant_id=${encodeURIComponent(String(clientTid))}` : 'files.php';
    const complianceHref = clientTid ? `compliance.php?tenant_id=${encodeURIComponent(String(clientTid))}` : 'compliance.php';

    box.innerHTML = `
      <div style="font-weight:700; margin-bottom:6px;">Ultimo provisioning</div>
      <div class="planning-muted">Cartelle: +${this.escapeHtml(String(created.folders ?? 0))} (esistenti: ${this.escapeHtml(String(reused.folders ?? 0))})</div>
      <div class="planning-muted">Documenti: +${this.escapeHtml(String(created.documents ?? 0))} (esistenti: ${this.escapeHtml(String(reused.documents ?? 0))})</div>
      <div class="planning-muted" style="margin-top:6px;">Task: +${this.escapeHtml(String(created.tasks ?? 0))} (esistenti: ${this.escapeHtml(String(reused.tasks ?? 0))})</div>
      <div class="planning-muted">Milestone: +${this.escapeHtml(String(created.events ?? 0))} (esistenti: ${this.escapeHtml(String(reused.events ?? 0))})</div>
      ${storageAvailable ? '' : `<div style="margin-top:10px;" class="planning-muted"><strong>Nota</strong>: storage mapping non disponibile (migrazione 44 non applicata) — dedup best-effort.</div>`}
      ${warnings.length ? `<div style="margin-top:10px;"><div style="font-weight:700;">Warning</div><div class="planning-muted">${this.escapeHtml(warnings.slice(0, 6).join(' | '))}</div></div>` : ''}
      <div style="margin-top:10px;" class="planning-actions">
        <a class="btn btn-secondary btn-sm" href="${this.escapeHtml(filesHref)}" target="_blank" rel="noopener">Apri File Manager</a>
        <a class="btn btn-secondary btn-sm" href="${this.escapeHtml(complianceHref)}" target="_blank" rel="noopener">Apri Dashboard Compliance</a>
      </div>
    `;
  }

  updateDocumentPanelLinks() {
    const plan = this.getActivePlan();
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    const filesHref = clientTenantId > 0 ? `files.php?tenant_id=${encodeURIComponent(String(clientTenantId))}` : 'files.php';
    const complianceHref = clientTenantId > 0 ? `compliance.php?tenant_id=${encodeURIComponent(String(clientTenantId))}` : 'compliance.php';

    const l1 = document.getElementById('planningDocOpenFilesLink');
    if (l1) l1.href = filesHref;
    const l2 = document.getElementById('planningDocOpenComplianceLink');
    if (l2) l2.href = complianceHref;
  }

  getTabsStorageKey() {
    const uid = (document.getElementById('planningCurrentUserId')?.value || '').trim() || '0';
    return `cnx_planning_active_tab_v1_${uid}`;
  }

  initPlanningTabs() {
    const tabs = document.getElementById('planningTabs');
    if (!tabs) return;

    // Tabs are useful only when a plan is selected (otherwise page is mostly onboarding)
    tabs.style.display = this.activePlanId ? 'inline-flex' : 'none';

    const btns = [...tabs.querySelectorAll('[data-planning-tab]')];
    btns.forEach(b => {
      if (b.dataset?.cnxBound === '1') return;
      b.addEventListener('click', () => this.setPlanningActiveTab(String(b.getAttribute('data-planning-tab') || 'activities')));
      b.dataset.cnxBound = '1';
    });

    let initial = 'activities';
    try {
      const saved = localStorage.getItem(this.getTabsStorageKey());
      if (saved) initial = saved;
    } catch (_) {}
    this.setPlanningActiveTab(initial, { persist: false });
  }

  setPlanningActiveTab(tab, { persist = true } = {}) {
    const tabs = document.getElementById('planningTabs');
    if (!tabs) return;
    const t = (tab === 'schedule' || tab === 'compliance' || tab === 'consultants' || tab === 'checklist') ? tab : 'activities';

    const panels = {
      activities: document.getElementById('planningTabPanelActivities'),
      consultants: document.getElementById('planningTabPanelConsultants'),
      schedule: document.getElementById('planningTabPanelSchedule'),
      compliance: document.getElementById('planningTabPanelCompliance'),
      checklist: document.getElementById('planningTabPanelChecklist'),
    };
    Object.entries(panels).forEach(([k, el]) => {
      if (!el) return;
      el.style.display = (k === t) ? 'block' : 'none';
    });

    [...tabs.querySelectorAll('.planning-tab-btn')].forEach(b => {
      const isActive = String(b.getAttribute('data-planning-tab') || '') === t;
      b.classList.toggle('active', isActive);
    });

    if (persist) {
      try { localStorage.setItem(this.getTabsStorageKey(), t); } catch (_) {}
    }

    // Keep Schedule tab always fresh: reload drafts when user enters the tab
    if (t === 'schedule') {
      try { this.loadScheduleDrafts(); } catch (_) {}
    }

    if (t === 'consultants') {
      try { this.renderAllocationPanel?.(); } catch (_) {}
    }

    if (t === 'checklist') {
      try { this.loadChecklistForActivePlan?.({ force: false }); } catch (_) {}
    }
  }

  // ------------------------------
  // Checklist (Progetto SGQ) — add-on, opt-in
  // ------------------------------

  getChecklistTemplateKey() {
    const explicit = String(this.checklistTemplateKey || '').trim();
    if (explicit) return explicit;

    // Best-effort: select Cefalù Sanità template when ISO7101 is involved (or sector suggests healthcare).
    try {
      const std = this.getProvisioningStandardsForUi().map(s => String(s || '').trim().toUpperCase()).filter(Boolean);
      const has7101 = std.includes('ISO7101');
      const sector = String(this.getActivePlanSectorBestEffort() || '').toLowerCase();
      const isSanita = sector.includes('sanit');
      const clientName = String(this.getActivePlan()?.client_name || '').toLowerCase();
      const hintsCefalu = clientName.includes('cefalu') || clientName.includes('cefalù');
      if (has7101 || isSanita || hintsCefalu) return 'CEFALU_SGQ_ISO9001_ISO7101';
    } catch (_) {}

    // Choose a slimmer template for recertification when no special template applies.
    const it = String(this.getActivePlanInterventionTypeBestEffort() || '').trim().toLowerCase();
    if (it === 'recertification') return 'ISO9001_RECERTIFICATION_MINI';

    return 'SGQ_ISO9001_BANDO';
  }

  getActivePlanInterventionTypeBestEffort() {
    const plan = this.getActivePlan();
    if (!plan) return '';
    const est = this.parseJsonBestEffort(plan.estimate_json);
    const p = est?.input_company_profile || est?.company_profile || null;
    const it = String(p?.intervention_type || '').trim();
    return it;
  }

  getActivePlanSectorBestEffort() {
    try {
      const plan = this.getActivePlan();
      if (!plan) return '';
      const est = this.parseJsonBestEffort(plan.estimate_json);
      const p = est?.input_company_profile || est?.company_profile || null;
      const sector = String(p?.sector || '').trim();
      return sector;
    } catch (_) {
      return '';
    }
  }

  parseJsonArrayBestEffort(v) {
    const o = this.parseJsonBestEffort(v);
    return Array.isArray(o) ? o : [];
  }

  getChecklistTemplateStorageKey(planId) {
    return `cnx_checklist_tpl_plan_${String(planId || '')}`;
  }

  getChecklistObjectiveDraftStorageKey(planId) {
    return `cnx_checklist_objective_plan_${String(planId || '')}`;
  }

  loadChecklistTemplateSelectionForPlan(planId) {
    const pid = parseInt(String(planId || '0'), 10) || 0;
    if (!pid) return '';
    try {
      const v = String(localStorage.getItem(this.getChecklistTemplateStorageKey(pid)) || '').trim();
      return v;
    } catch (_) {
      return '';
    }
  }

  saveChecklistTemplateSelectionForPlan(planId, templateKey) {
    const pid = parseInt(String(planId || '0'), 10) || 0;
    if (!pid) return;
    try {
      const v = String(templateKey || '').trim();
      if (v) localStorage.setItem(this.getChecklistTemplateStorageKey(pid), v);
      else localStorage.removeItem(this.getChecklistTemplateStorageKey(pid));
    } catch (_) {}
  }

  loadChecklistObjectiveDraftForPlan(planId) {
    const pid = parseInt(String(planId || '0'), 10) || 0;
    if (!pid) return '';
    try {
      return String(localStorage.getItem(this.getChecklistObjectiveDraftStorageKey(pid)) || '');
    } catch (_) {
      return '';
    }
  }

  saveChecklistObjectiveDraftForPlan(planId, objectiveText) {
    const pid = parseInt(String(planId || '0'), 10) || 0;
    if (!pid) return;
    try {
      const v = String(objectiveText || '');
      if (v.trim()) localStorage.setItem(this.getChecklistObjectiveDraftStorageKey(pid), v);
      else localStorage.removeItem(this.getChecklistObjectiveDraftStorageKey(pid));
    } catch (_) {}
  }

  async loadChecklistTemplates({ force } = {}) {
    if (!force && this.checklistTemplatesLoadedAt && (Date.now() - this.checklistTemplatesLoadedAt) < 5 * 60 * 1000) {
      return this.checklistTemplates;
    }
    try {
      const res = await this.apiFetch(`consulting_plans/checklists/templates_list.php?csrf_token=${encodeURIComponent(String(this.csrfToken || ''))}`, { method: 'GET', json: true });
      const data = res?.data || {};
      this.checklistTemplates = Array.isArray(data.templates) ? data.templates : [];
      this.checklistTemplatesLoadedAt = Date.now();
      this.renderChecklistTemplateSelect();
      return this.checklistTemplates;
    } catch (_) {
      // non-blocking
      this.checklistTemplatesLoadedAt = Date.now();
      this.renderChecklistTemplateSelect();
      return this.checklistTemplates;
    }
  }

  renderChecklistTemplateSelect() {
    const sel = document.getElementById('planningChecklistTemplateSelect');
    if (!sel) return;
    const templates = Array.isArray(this.checklistTemplates) ? this.checklistTemplates : [];
    const active = this.getChecklistTemplateKey();
    const hadValue = String(sel.value || '').trim();

    sel.innerHTML = '';
    const addOpt = (val, label) => {
      const o = document.createElement('option');
      o.value = String(val || '');
      o.textContent = String(label || val || '');
      sel.appendChild(o);
    };

    if (!templates.length) {
      addOpt(active, active || '—');
      sel.value = active;
      return;
    }

    templates.forEach(t => {
      const k = String(t?.template_key || '').trim();
      if (!k) return;
      const title = String(t?.title || k).trim();
      const std = Array.isArray(t?.standards) ? t.standards.map(x => String(x || '').trim()).filter(Boolean) : [];
      const suffix = std.length ? ` — ${std.join(', ')}` : '';
      addOpt(k, `${k} — ${title}${suffix}`);
    });

    // Keep current selection if it exists; otherwise select best-effort active.
    const next = hadValue && [...sel.options].some(o => String(o.value) === String(hadValue)) ? hadValue : active;
    if (next) sel.value = next;
  }

  onChecklistTemplateSelectChanged() {
    const planId = parseInt(String(this.activePlanId || '0'), 10) || 0;
    const sel = document.getElementById('planningChecklistTemplateSelect');
    const tpl = sel ? String(sel.value || '').trim() : '';
    if (!planId || !tpl) return;
    this.checklistTemplateKey = tpl;
    this.saveChecklistTemplateSelectionForPlan(planId, tpl);
    // Force reload: different template_key means a different instance.
    this.loadChecklistForActivePlan({ force: true });
  }

  showChecklistObjectiveMsg(text, tone = 'muted') {
    const el = document.getElementById('planningChecklistObjectiveMsg');
    if (!el) return;
    const t = String(text || '').trim();
    if (!t) {
      el.style.display = 'none';
      el.textContent = '';
      return;
    }
    el.style.display = 'block';
    el.textContent = t;
    if (tone === 'error') el.style.color = 'rgb(185, 28, 28)';
    else if (tone === 'success') el.style.color = 'rgb(21, 128, 61)';
    else el.style.color = '';
  }

  async onChecklistObjectiveInput({ flush } = {}) {
    const plan = this.getActivePlan();
    const planId = plan ? (parseInt(plan.id, 10) || 0) : 0;
    const objEl = document.getElementById('planningChecklistObjectiveText');
    if (!planId || !objEl) return;
    const objectiveText = String(objEl.value || '');
    this.saveChecklistObjectiveDraftForPlan(planId, objectiveText);

    // If checklist not created yet, draft is enough (sent on create).
    if (!this.activeChecklist || !this.activeChecklist.id) {
      this.showChecklistObjectiveMsg('Compila l’obiettivo e poi crea la checklist da template.', 'muted');
      return;
    }

    // If DB does not support objective_text, show a clear hint.
    if (this.checklistObjectiveSupported === false) {
      this.showChecklistObjectiveMsg('DB non aggiornato: applica la migrazione 65 per salvare l’obiettivo su database.', 'error');
      return;
    }

    // Debounced save to server
    const doSave = async () => {
      try {
        const cid = parseInt(String(this.activeChecklist?.id || '0'), 10) || 0;
        if (!cid) return;
        const obj = String(objectiveText || '').trim();
        if (!obj) {
          this.showChecklistObjectiveMsg('Obiettivo obbligatorio.', 'error');
          return;
        }
        await this.apiFetch('consulting_plans/checklists/checklist_update.php', {
          method: 'POST',
          json: true,
          body: JSON.stringify({ csrf_token: this.csrfToken, checklist_id: cid, objective_text: obj }),
        });
        // Update local state (best-effort)
        this.activeChecklist = { ...(this.activeChecklist || {}), objective_text: obj };
        this.showChecklistObjectiveMsg('Obiettivo salvato.', 'success');
      } catch (e) {
        const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
        let msg = e?.message || 'Errore salvataggio obiettivo';
        if (errId) msg += ` (ref: ${errId})`;
        this.showChecklistObjectiveMsg(msg, 'error');
      }
    };

    try {
      if (this._checklistObjectiveDebounceTimer) {
        clearTimeout(this._checklistObjectiveDebounceTimer);
        this._checklistObjectiveDebounceTimer = null;
      }
    } catch (_) {}

    if (flush) {
      return doSave();
    }
    this._checklistObjectiveDebounceTimer = setTimeout(() => { doSave(); }, 600);
  }

  async loadChecklistForActivePlan({ force } = {}) {
    const planId = parseInt(String(this.activePlanId || '0'), 10) || 0;
    if (!planId) return;

    // Best-effort: load available templates (for selection UI).
    try { await this.loadChecklistTemplates({ force: false }); } catch (_) {}

    // Restore explicit template selection per-plan (if any).
    try {
      const savedTpl = this.loadChecklistTemplateSelectionForPlan(planId);
      if (savedTpl) {
        this.checklistTemplateKey = savedTpl;
      } else {
        const loadedKey = String(this.activeChecklistLoadedForPlanId || '');
        if (loadedKey && !loadedKey.startsWith(String(planId) + '|')) {
          // Switching plan: reset to auto-pick unless user chose explicitly.
          this.checklistTemplateKey = '';
        }
      }
    } catch (_) {}

    // Cache key must include template_key (a plan can have multiple checklist instances).
    const tplKey = this.getChecklistTemplateKey();
    const loadKey = `${String(planId)}|${String(tplKey)}`;

    if (!force && String(this.activeChecklistLoadedForPlanId || '') === loadKey && (this.checklistStorageAvailable === false || this.activeChecklist || this.activeChecklist === null)) {
      // already loaded for this plan; just render
      this.renderChecklistTab();
      return;
    }

    const statusBox = document.getElementById('planningChecklistStatusBox');
    if (statusBox) statusBox.innerHTML = `<div class="planning-muted">Caricamento…</div>`;
    const docBox = document.getElementById('planningChecklistDocSummaryBox');
    if (docBox) { docBox.style.display = 'none'; docBox.innerHTML = ''; }
    const sectionsWrap = document.getElementById('planningChecklistSections');
    if (sectionsWrap) sectionsWrap.innerHTML = '';

    try {
      const tpl = tplKey;
      const res = await this.apiFetch(`consulting_plans/checklists/checklist_get.php?plan_id=${encodeURIComponent(String(planId))}&template_key=${encodeURIComponent(tpl)}&csrf_token=${encodeURIComponent(String(this.csrfToken || ''))}`, { method: 'GET', json: true });
      const data = res?.data || {};
      this.checklistStorageAvailable = !!data.storage_available;
      this.checklistObjectiveSupported = (data?.features && typeof data.features === 'object') ? !!data.features.objective_supported : null;
      this.checklistEvidenceTableSupported = (data?.features && typeof data.features === 'object') ? !!data.features.evidence_table_supported : null;
      this.activeChecklist = data.checklist || null;
      this.activeChecklistItems = Array.isArray(data.items) ? data.items : [];
      this.activeChecklistTemplate = (data.template_full && typeof data.template_full === 'object') ? data.template_full : (data.template || null);
      this.activeChecklistLoadedForPlanId = loadKey;
      this.activeChecklistDocProfile = null;
    } catch (e) {
      // If endpoint missing in partial deploy, show a clear message but don't crash other tabs
      this.checklistStorageAvailable = false;
      this.checklistObjectiveSupported = null;
      this.checklistEvidenceTableSupported = null;
      this.activeChecklist = null;
      this.activeChecklistItems = [];
      this.activeChecklistTemplate = null;
      this.activeChecklistLoadedForPlanId = loadKey;
    }

    this.renderChecklistTab();
  }

  renderChecklistTab() {
    const statusBox = document.getElementById('planningChecklistStatusBox');
    const docBox = document.getElementById('planningChecklistDocSummaryBox');
    const sectionsWrap = document.getElementById('planningChecklistSections');
    if (!statusBox || !sectionsWrap) return;

    const plan = this.getActivePlan();
    const planId = plan ? (parseInt(plan.id, 10) || 0) : 0;
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    if (!planId || !clientTenantId) {
      statusBox.innerHTML = `<div class="planning-muted">Seleziona un piano…</div>`;
      if (docBox) { docBox.style.display = 'none'; docBox.innerHTML = ''; }
      sectionsWrap.innerHTML = '';
      return;
    }

    // Keep template select always in sync with current best-effort selection.
    try { this.renderChecklistTemplateSelect(); } catch (_) {}

    // Objective field (mandatory)
    const objEl = document.getElementById('planningChecklistObjectiveText');
    const objDb = (this.activeChecklist && Object.prototype.hasOwnProperty.call(this.activeChecklist, 'objective_text'))
      ? String(this.activeChecklist.objective_text || '')
      : '';
    const objDraft = this.loadChecklistObjectiveDraftForPlan(planId);
    const objValue = (objDb.trim() ? objDb : objDraft);
    if (objEl && document.activeElement !== objEl) {
      objEl.value = String(objValue || '');
    }
    const objSupported = (this.checklistObjectiveSupported === true) ? true : (this.checklistObjectiveSupported === false ? false : null);
    if (objSupported === false) {
      this.showChecklistObjectiveMsg('DB non aggiornato: applica la migrazione 65 per salvare l’obiettivo su database.', 'error');
    } else if (!String(objValue || '').trim()) {
      this.showChecklistObjectiveMsg('Obiettivo obbligatorio.', 'error');
    } else {
      this.showChecklistObjectiveMsg('', 'muted');
    }
    const createBtn = document.getElementById('planningChecklistCreateBtn');
    if (createBtn) {
      if (objSupported === false) createBtn.disabled = true;
      else createBtn.disabled = !String(objValue || '').trim();
    }

    if (this.checklistStorageAvailable === false) {
      statusBox.innerHTML = `
        <div style="font-weight:700; margin-bottom:6px;">Checklist non disponibile</div>
        <div class="planning-muted">Schema DB non aggiornato o endpoint non disponibile. Applica la migrazione <code>database/migrations/64_consulting_project_checklists.sql</code>.</div>
      `;
      if (docBox) { docBox.style.display = 'none'; docBox.innerHTML = ''; }
      sectionsWrap.innerHTML = '';
      return;
    }

    const chk = this.activeChecklist;
    const itemsAll = Array.isArray(this.activeChecklistItems) ? this.activeChecklistItems : [];
    const total = itemsAll.length;
    const done = itemsAll.filter(i => ['done', 'not_applicable'].includes(String(i?.status || '').toLowerCase())).length;
    const present = itemsAll.filter(i => String(i?.status || '').toLowerCase() === 'present').length;
    const requiredTotal = itemsAll.filter(i => !!i?.required).length;
    const requiredMissing = itemsAll.filter(i => !!i?.required && ['missing','to_review'].includes(String(i?.status || '').toLowerCase())).length;
    const pct = total ? Math.round((done / total) * 100) : 0;

    if (!chk) {
      const tplTitle = String(this.activeChecklistTemplate?.title || this.getChecklistTemplateKey() || 'Checklist');
      statusBox.innerHTML = `
        <div style="font-weight:700; margin-bottom:6px;">Nessuna checklist creata</div>
        <div class="planning-muted">Piano: <strong>${this.escapeHtml(String(plan?.title || ''))}</strong> • Cliente: <strong>${this.escapeHtml(String(plan?.client_name || `Tenant #${clientTenantId}`))}</strong></div>
        <div class="planning-muted" style="margin-top:6px;">Template selezionato: <strong>${this.escapeHtml(tplTitle)}</strong>. Compila l’obiettivo e premi “Crea checklist da template”.</div>
      `;
      if (docBox) { docBox.style.display = 'none'; docBox.innerHTML = ''; }
      sectionsWrap.innerHTML = '';
      return;
    }

    statusBox.innerHTML = `
      <div style="display:flex; align-items:center; justify-content:space-between; gap: 12px; flex-wrap:wrap;">
        <div>
          <div style="font-weight:700;">${this.escapeHtml(String(chk.title || 'Checklist'))}</div>
          <div class="planning-muted">Piano: <strong>${this.escapeHtml(String(plan?.title || ''))}</strong> • Cliente: <strong>${this.escapeHtml(String(plan?.client_name || `Tenant #${clientTenantId}`))}</strong></div>
          <div class="planning-muted" style="margin-top:4px;">Stato: <strong>${this.escapeHtml(String(chk.status || 'draft'))}</strong> • Richiesti: ${requiredTotal} (da completare: ${requiredMissing})</div>
        </div>
        <div style="min-width: 220px; flex:1; max-width: 360px;">
          <div class="planning-muted" style="font-size:12px; margin-bottom:6px;">Progresso: ${done}/${total} (${pct}%) • Evidenze trovate: ${present}</div>
          <div style="height:10px; background: var(--color-gray-200); border-radius: 999px; overflow:hidden;">
            <div style="height:10px; width:${pct}%; background: var(--color-primary);"></div>
          </div>
        </div>
      </div>
    `;

    if (docBox) {
      const prof = this.activeChecklistDocProfile;
      if (prof && typeof prof === 'object') {
        const maturity = String(prof.maturity_suggested || '').trim() || '—';
        const conf = parseInt(String(prof.confidence || '0'), 10) || 0;
        const det = (prof.detected && typeof prof.detected === 'object') ? prof.detected : {};
        const detManual = (det.manual === true) ? 'sì' : (det.manual === false ? 'no' : '—');
        const detProc = (det.procedures_count_est !== undefined && det.procedures_count_est !== null) ? String(det.procedures_count_est) : '—';
        const detRec = (det.records_count_est !== undefined && det.records_count_est !== null) ? String(det.records_count_est) : '—';
        const detCert = (det.evidence_of_certification === true) ? 'sì' : (det.evidence_of_certification === false ? 'no' : '—');
        const notes = String(prof?.planning_adjustments?.notes || '').trim();
        const gaps = Array.isArray(prof.gaps) ? prof.gaps : [];
        const gapsTop = gaps.slice(0, 4).map(g => {
          const area = String(g?.area || '').trim();
          const sev = String(g?.severity || '').trim();
          const det = String(g?.detail || '').trim();
          const head = [area, sev].filter(Boolean).join(' / ');
          return (head ? (head + ': ') : '') + det;
        }).filter(Boolean);
        docBox.style.display = 'block';
        docBox.innerHTML = `
          <div style="font-weight:700; margin-bottom:6px;">Sommario documenti cliente (best-effort)</div>
          <div class="planning-muted">Maturità: <strong>${this.escapeHtml(maturity)}</strong> • Confidenza: <strong>${this.escapeHtml(String(conf))}%</strong></div>
          <div class="planning-muted" style="margin-top:6px;">Manuale: <strong>${this.escapeHtml(detManual)}</strong> • Procedure (stima): <strong>${this.escapeHtml(detProc)}</strong> • Registri (stima): <strong>${this.escapeHtml(detRec)}</strong> • Evidenza certificazione: <strong>${this.escapeHtml(detCert)}</strong></div>
          ${notes ? `<div class="planning-muted" style="margin-top:6px;">${this.escapeHtml(notes)}</div>` : ``}
          ${gapsTop.length ? `<div style="margin-top:10px;"><div style="font-weight:700;">Gap (sintesi)</div><div class="planning-muted">${this.escapeHtml(gapsTop.join(' | '))}</div></div>` : ``}
        `;
      } else {
        docBox.style.display = 'none';
        docBox.innerHTML = '';
      }
    }

    // Build section title + order maps from template_full
    const sectionTitleByKey = new Map();
    const sectionOrderByKey = new Map();
    try {
      const sections = Array.isArray(this.activeChecklistTemplate?.sections) ? this.activeChecklistTemplate.sections : [];
      sections.forEach((s, idx) => {
        const k = String(s?.section_key || '').trim();
        const t = String(s?.title || '').trim();
        if (!k) return;
        if (t) sectionTitleByKey.set(k, t);
        sectionOrderByKey.set(k, idx * 10);
      });
    } catch (_) {}

    // Sync filter selects (phase + status)
    const phaseSel = document.getElementById('planningChecklistFilterPhase');
    if (phaseSel) {
      const chosen = String(phaseSel.value || this.checklistFilterPhase || '').trim();
      const keys = Array.from(sectionOrderByKey.keys());
      const ordered = keys.slice(0).sort((a, b) => (Number(sectionOrderByKey.get(a) ?? 0) - Number(sectionOrderByKey.get(b) ?? 0)) || (a < b ? -1 : 1));
      phaseSel.innerHTML = `<option value="">Tutte</option>` + ordered.map(k => {
        const title = sectionTitleByKey.get(k) || k;
        return `<option value="${this.escapeAttr(k)}">${this.escapeHtml(title)}</option>`;
      }).join('');
      phaseSel.value = ordered.includes(chosen) ? chosen : '';
      this.checklistFilterPhase = String(phaseSel.value || '').trim();
    }
    const statusSel = document.getElementById('planningChecklistFilterStatus');
    if (statusSel) {
      const chosen = String(statusSel.value || this.checklistFilterStatus || '').trim().toLowerCase();
      const allowed = ['', 'missing', 'present', 'to_review', 'done', 'not_applicable'];
      statusSel.value = allowed.includes(chosen) ? chosen : '';
      this.checklistFilterStatus = String(statusSel.value || '').trim();
    }

    const phaseFilter = String(this.checklistFilterPhase || '').trim();
    const statusFilter = String(this.checklistFilterStatus || '').trim().toLowerCase();
    const itemsView = (Array.isArray(itemsAll) ? itemsAll : []).filter(it => {
      if (!it) return false;
      if (phaseFilter && String(it?.section_key || '') !== phaseFilter) return false;
      if (statusFilter) {
        const st = String(it?.status || '').trim().toLowerCase();
        if (st !== statusFilter) return false;
      }
      return true;
    });

    const bySection = {};
    itemsView.forEach(it => {
      const sk = String(it?.section_key || 'misc').trim() || 'misc';
      if (!bySection[sk]) bySection[sk] = [];
      bySection[sk].push(it);
    });
    const sectionKeys = Object.keys(bySection);
    sectionKeys.sort((a, b) => {
      const oa = Number(sectionOrderByKey.get(a) ?? 999999);
      const ob = Number(sectionOrderByKey.get(b) ?? 999999);
      if (oa !== ob) return oa - ob;
      return a === b ? 0 : (a < b ? -1 : 1);
    });

    const statusOptions = ['missing','present','to_review','done','not_applicable'];
    const renderEvidence = (evArr, itemId) => {
      const ev = Array.isArray(evArr) ? evArr : [];
      if (!ev.length) return `<div class="planning-muted">—</div>`;
      return `<div>` + ev.map(x => {
        const fid = parseInt(String(x?.file_id || '0'), 10) || 0;
        const tid = parseInt(String(x?.tenant_id || clientTenantId || '0'), 10) || 0;
        const name = String(x?.name || '').trim() || `file #${fid}`;
        const path = String(x?.path || '').trim();
        const tags = Array.isArray(x?.tags) ? x.tags.map(t => String(t || '').trim()).filter(Boolean).slice(0, 6) : [];
        const mk = Array.isArray(x?.matched_keywords) ? x.matched_keywords.map(t => String(t || '').trim()).filter(Boolean).slice(0, 6) : [];
        const href = (clientTenantId > 0 && fid > 0)
          ? `files.php?tenant_id=${encodeURIComponent(String(clientTenantId))}&open_file_id=${encodeURIComponent(String(fid))}`
          : 'files.php';
        return `
          <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-top:6px;">
            <div style="min-width: 0;">
              <div style="font-weight:700;">${this.escapeHtml(name)}</div>
              <div class="planning-muted" style="font-size:12px;">${this.escapeHtml(path || '')}</div>
              ${(tags.length || mk.length) ? `<div class="planning-muted" style="font-size:12px; margin-top:4px;">${this.escapeHtml([...tags, ...mk].slice(0, 8).join(' • '))}</div>` : ``}
            </div>
            <div class="planning-actions" style="gap:8px; flex-wrap:wrap;">
              <a class="btn btn-secondary btn-sm" href="${this.escapeAttr(href)}" target="_blank" rel="noopener">Apri</a>
              <button type="button" class="btn btn-secondary btn-sm" data-chk-action="remove-evidence" data-chk-item-id="${parseInt(String(itemId || '0'), 10) || 0}" data-chk-file-id="${fid}" data-chk-tenant-id="${tid}">Rimuovi</button>
            </div>
          </div>
        `;
      }).join('') + `</div>`;
    };

    sectionsWrap.innerHTML = sectionKeys.map(sk => {
      const secTitle = sectionTitleByKey.get(sk) || sk;
      const rows = bySection[sk] || [];
      return `
        <div style="margin-top: 14px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: #fff;">
          <div style="font-weight:700; margin-bottom:8px;">${this.escapeHtml(secTitle)}</div>
          <div style="overflow:auto;">
            <table class="planning-table">
              <thead>
                <tr>
                  <th style="min-width:180px;">Fase</th>
                  <th>Voce</th>
                  <th style="width:110px;">Obblig.</th>
                  <th style="width:150px;">Stato</th>
                  <th style="min-width:260px;">Note/Testo</th>
                  <th style="min-width:340px;">Evidenze</th>
                  <th style="width:210px;">Azioni</th>
                </tr>
              </thead>
              <tbody>
                ${rows.map(it => {
                  const id = parseInt(String(it?.id || '0'), 10) || 0;
                  const title = String(it?.title || '').trim();
                  const desc = String(it?.description || '').trim();
                  const qType = String(it?.question_type || '').trim().toLowerCase();
                  const required = !!it?.required;
                  const st = String(it?.status || 'missing').trim().toLowerCase();
                  const ansText = String(it?.answer_text || '');
                  const ansJson = this.parseJsonBestEffort(it?.answer_json);
                  const ev = this.parseJsonArrayBestEffort(it?.evidence_json);

                  const reqBadge = required
                    ? `<span class="planning-pill" style="background: rgba(220, 38, 38, 0.12); color: rgb(185, 28, 28);">SI</span>`
                    : `<span class="planning-pill" style="background: rgba(107, 114, 128, 0.12); color: rgb(55, 65, 81);">NO</span>`;

                  const statusSel = `
                    <select class="form-control" data-chk-item-id="${id}" data-chk-field="status">
                      ${statusOptions.map(s => `<option value="${this.escapeAttr(s)}"${s === st ? ' selected' : ''}>${this.escapeHtml(s)}</option>`).join('')}
                    </select>
                  `;

                  let inputHtml = `<div class="planning-muted">—</div>`;
                  let evidenceHtml = `<div class="planning-muted">—</div>`;
                  let actionsHtml = `<div class="planning-muted">—</div>`;

                  if (qType === 'text') {
                    inputHtml = `<input class="form-control" data-chk-item-id="${id}" data-chk-field="answer_text" value="${this.escapeAttr(ansText)}" placeholder="Testo..." />`;
                  } else if (qType === 'multiline') {
                    inputHtml = `<textarea class="form-control" rows="3" data-chk-item-id="${id}" data-chk-field="answer_text" placeholder="Testo...">${this.escapeHtml(ansText)}</textarea>`;
                  } else if (qType === 'bool') {
                    const v = (ansJson === true) || String(ansText).toLowerCase() === 'true';
                    inputHtml = `
                      <div style="display:flex; flex-direction:column; gap: 8px;">
                        <label class="form-checkbox-label">
                          <input type="checkbox" class="form-checkbox" data-chk-item-id="${id}" data-chk-field="answer_bool"${v ? ' checked' : ''}>
                          <span>Confermato</span>
                        </label>
                        <textarea class="form-control" rows="2" data-chk-item-id="${id}" data-chk-field="answer_text" placeholder="Note (opzionale)">${this.escapeHtml(ansText)}</textarea>
                      </div>
                    `;
                  } else if (qType === 'select') {
                    inputHtml = `<input class="form-control" data-chk-item-id="${id}" data-chk-field="answer_text" value="${this.escapeAttr(ansText)}" placeholder="Valore..." />`;
                  } else if (qType === 'file' || qType === 'multi_file') {
                    evidenceHtml = `${renderEvidence(ev, id)}`;
                    actionsHtml = `
                      <div class="planning-actions" style="flex-wrap:wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" data-chk-action="add-evidence" data-chk-item-id="${id}" data-chk-qtype="${this.escapeAttr(qType)}">Aggiungi evidenza</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-chk-action="clear-evidence" data-chk-item-id="${id}">Svuota</button>
                      </div>
                    `;
                  }

                  return `
                    <tr>
                      <td class="planning-muted" style="font-size:12px;">${this.escapeHtml(secTitle)}</td>
                      <td style="min-width: 260px;">
                        <div style="font-weight:700;">${this.escapeHtml(title)}</div>
                        ${desc ? `<div class="planning-muted" style="margin-top:4px;">${this.escapeHtml(desc)}</div>` : ``}
                      </td>
                      <td>${reqBadge}</td>
                      <td>${statusSel}</td>
                      <td>${inputHtml}</td>
                      <td>${evidenceHtml}</td>
                      <td>${actionsHtml}</td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          </div>
        </div>
      `;
    }).join('');

    // Bind dynamic inputs
    sectionsWrap.querySelectorAll('[data-chk-field]').forEach(el => {
      if (el.dataset?.cnxBound === '1') return;
      const field = String(el.getAttribute('data-chk-field') || '');
      const itemId = parseInt(String(el.getAttribute('data-chk-item-id') || '0'), 10) || 0;
      if (!itemId || !field) return;

      const onCommit = async () => {
        try {
          if (field === 'status') {
            await this.updateChecklistItem(itemId, { status: String(el.value || '') });
          } else if (field === 'answer_text') {
            await this.updateChecklistItem(itemId, { answer_text: String(el.value || '') });
          } else if (field === 'answer_bool') {
            await this.updateChecklistItem(itemId, { answer_json: !!el.checked });
          }
        } catch (_) {}
      };

      const tag = String(el.tagName || '').toLowerCase();

      // Debounced saving for text inputs (input/textarea) for better UX.
      if (field === 'answer_text') {
        const key = `chk:${itemId}:answer_text`;
        const debounceMs = 500;
        el.addEventListener('input', () => {
          try { if (tag === 'textarea') this.autoGrowTextarea(el); } catch (_) {}
          try {
            const prev = this._checklistDebounceTimers?.get(key);
            if (prev) clearTimeout(prev);
            const t = setTimeout(() => { onCommit(); }, debounceMs);
            this._checklistDebounceTimers?.set(key, t);
          } catch (_) {}
        });
        el.addEventListener('blur', () => {
          try {
            const prev = this._checklistDebounceTimers?.get(key);
            if (prev) clearTimeout(prev);
            this._checklistDebounceTimers?.delete(key);
          } catch (_) {}
          onCommit();
        });
      } else {
        el.addEventListener('change', onCommit);
      }
      el.dataset.cnxBound = '1';
    });

    sectionsWrap.querySelectorAll('[data-chk-action]').forEach(btn => {
      if (btn.dataset?.cnxBound === '1') return;
      btn.addEventListener('click', async () => {
        const act = String(btn.getAttribute('data-chk-action') || '');
        const itemId = parseInt(String(btn.getAttribute('data-chk-item-id') || '0'), 10) || 0;
        if (!itemId) return;
        if (act === 'clear-evidence') {
          await this.clearChecklistEvidenceForItem(itemId);
          return;
        }
        if (act === 'remove-evidence') {
          const fid = parseInt(String(btn.getAttribute('data-chk-file-id') || '0'), 10) || 0;
          if (fid > 0) await this.clearChecklistEvidenceForItem(itemId, fid);
          return;
        }
        if (act === 'add-evidence') {
          const qType = String(btn.getAttribute('data-chk-qtype') || 'file');
          const raw = String(prompt(qType === 'multi_file' ? 'Inserisci file_id (anche "1,2,3"):' : 'Inserisci file_id:', '') || '').trim();
          if (!raw) return;
          const ids = raw.split(',').map(s => parseInt(String(s).trim(), 10) || 0).filter(n => n > 0);
          if (!ids.length) return;
          const use = (qType === 'multi_file') ? ids : [ids[0]];
          await this.addChecklistEvidenceForItem(itemId, use);
        }
      });
      btn.dataset.cnxBound = '1';
    });
  }

  async openAssistantModal() {
    const plan = this.getActivePlan();
    if (!plan) {
      this.toast('Seleziona un piano', 'error');
      return;
    }
    const modal = document.getElementById('planningAssistantModal');
    if (modal) modal.style.display = 'block';
    try { await this.loadChecklistForActivePlan({ force: false }); } catch (_) {}
    this.renderAssistantChecklistTable();
    this.renderAssistantChat();
    this.loadAssistantDocContext({ force: false });
  }

  closeAssistantModal() {
    const modal = document.getElementById('planningAssistantModal');
    if (modal) modal.style.display = 'none';
  }

  async ensureAssistantProviders() {
    if (this.assistantProviders && this.assistantProviders.length) return this.assistantProviders;
    try {
      const res = await this.apiFetch('ai/providers.php?action=list', { method: 'GET', json: true });
      const providers = Array.isArray(res?.data?.providers) ? res.data.providers : [];
      this.assistantProviders = providers;
      if (!this.assistantProvider && providers.length) {
        this.assistantProvider = String(providers[0].provider || 'openai');
        this.assistantModel = String(providers[0].default_model || '');
      }
      return providers;
    } catch (_) {
      this.assistantProviders = [];
      return [];
    }
  }

  renderAssistantDocSummary() {
    const box = document.getElementById('planningAssistantDocSummaryBox');
    if (!box) return;
    const prof = (this.activeChecklistDocProfile && typeof this.activeChecklistDocProfile === 'object') ? this.activeChecklistDocProfile : null;
    if (!prof) {
      box.style.display = 'none';
      box.innerHTML = '';
      return;
    }
    const maturity = String(prof.maturity_suggested || '');
    const conf = parseInt(String(prof.confidence || '0'), 10) || 0;
    const detected = (prof.detected && typeof prof.detected === 'object') ? prof.detected : {};
    const manual = parseInt(String(detected.manual || '0'), 10) || 0;
    const procedures = parseInt(String(detected.procedures || '0'), 10) || 0;
    const records = parseInt(String(detected.records || '0'), 10) || 0;
    const cert = String(detected.evidence_of_certification || '');
    box.innerHTML = `
      <div style="font-weight:700; margin-bottom:6px;">Stato documenti (AI)</div>
      <div class="planning-muted">Maturità: <strong>${this.escapeHtml(maturity || 'n/d')}</strong> · Confidenza: <strong>${conf}%</strong></div>
      <div class="planning-muted" style="margin-top:4px;">Rilevati: Manuale ${manual}, Procedure ${procedures}, Registri ${records}${cert ? ` · Certificazione: ${this.escapeHtml(cert)}` : ''}</div>
    `;
    box.style.display = 'block';
  }

  getAssistantTemplateItemByKey(sectionKey, itemKey) {
    const tpl = (this.activeChecklistTemplate && typeof this.activeChecklistTemplate === 'object') ? this.activeChecklistTemplate : null;
    if (!tpl || !Array.isArray(tpl.sections)) return null;
    const sk = String(sectionKey || '').trim();
    const ik = String(itemKey || '').trim();
    if (!sk || !ik) return null;
    for (const s of tpl.sections) {
      if (!s || String(s.section_key || '').trim() !== sk) continue;
      const items = Array.isArray(s.items) ? s.items : [];
      for (const it of items) {
        if (!it) continue;
        if (String(it.item_key || '').trim() === ik) return it;
      }
    }
    return null;
  }

  getAssistantAutoInfoForItem(item) {
    const prof = (this.activeChecklistDocProfile && typeof this.activeChecklistDocProfile === 'object') ? this.activeChecklistDocProfile : null;
    if (!prof) return null;
    const tplItem = this.getAssistantTemplateItemByKey(item?.section_key, item?.item_key);
    const kw = Array.isArray(tplItem?.autofill_keywords) ? tplItem.autofill_keywords : [];
    const keywords = kw.map(s => String(s || '').trim().toLowerCase()).filter(Boolean);
    if (!keywords.length) return null;
    const evidence = Array.isArray(prof.doc_evidence) ? prof.doc_evidence : [];
    const hits = [];
    for (const ev of evidence) {
      const name = String(ev?.name || '').toLowerCase();
      const path = String(ev?.path || '').toLowerCase();
      const tags = Array.isArray(ev?.tags) ? ev.tags.map(t => String(t || '').toLowerCase()) : [];
      const mk = Array.isArray(ev?.matched_keywords) ? ev.matched_keywords.map(t => String(t || '').toLowerCase()) : [];
      const hay = `${name} ${path} ${tags.join(' ')} ${mk.join(' ')}`;
      const matched = keywords.some(k => hay.includes(k));
      if (matched) hits.push(ev);
      if (hits.length >= 3) break;
    }
    if (!hits.length) return null;
    return {
      confidence: parseInt(String(prof.confidence || '0'), 10) || 0,
      files: hits,
    };
  }

  renderAssistantChecklistTable() {
    const wrap = document.getElementById('planningAssistantChecklistTable');
    if (!wrap) return;
    const items = Array.isArray(this.activeChecklistItems) ? this.activeChecklistItems : [];
    if (!items.length) {
      wrap.innerHTML = `<div class="planning-muted">Checklist non caricata. Apri la tab Checklist e crea/ricarica la checklist.</div>`;
      return;
    }

    const bySection = {};
    const sectionTitleByKey = new Map();
    const sectionOrderByKey = new Map();
    (this.activeChecklistTemplate?.sections || []).forEach((s, idx) => {
      const key = String(s?.section_key || '').trim();
      if (!key) return;
      sectionTitleByKey.set(key, String(s?.title || key));
      sectionOrderByKey.set(key, Number(s?.order ?? idx));
    });

    items.forEach(it => {
      const k = String(it?.section_key || '').trim();
      if (!bySection[k]) bySection[k] = [];
      bySection[k].push(it);
    });

    const sectionKeys = Object.keys(bySection).sort((a, b) => {
      const oa = Number(sectionOrderByKey.get(a) ?? 999999);
      const ob = Number(sectionOrderByKey.get(b) ?? 999999);
      if (oa !== ob) return oa - ob;
      return a === b ? 0 : (a < b ? -1 : 1);
    });

    const statusOptions = ['missing','present','to_review','done','not_applicable'];
    const renderEvidence = (evArr, itemId, clientTenantId) => {
      const ev = Array.isArray(evArr) ? evArr : [];
      if (!ev.length) return `<div class="planning-muted">—</div>`;
      return `<div>` + ev.map(x => {
        const fid = parseInt(String(x?.file_id || '0'), 10) || 0;
        const tid = parseInt(String(x?.tenant_id || clientTenantId || '0'), 10) || 0;
        const name = String(x?.name || '').trim() || `file #${fid}`;
        const path = String(x?.path || '').trim();
        const href = (clientTenantId > 0 && fid > 0)
          ? `files.php?tenant_id=${encodeURIComponent(String(clientTenantId))}&open_file_id=${encodeURIComponent(String(fid))}`
          : 'files.php';
        return `
          <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-top:6px;">
            <div style="min-width: 0;">
              <div style="font-weight:700;">${this.escapeHtml(name)}</div>
              <div class="planning-muted" style="font-size:12px;">${this.escapeHtml(path || '')}</div>
            </div>
            <div class="planning-actions" style="gap:8px; flex-wrap:wrap;">
              <a class="btn btn-secondary btn-sm" href="${this.escapeAttr(href)}" target="_blank" rel="noopener">Apri</a>
              <button type="button" class="btn btn-secondary btn-sm" data-chk-action="remove-evidence" data-chk-item-id="${parseInt(String(itemId || '0'), 10) || 0}" data-chk-file-id="${fid}" data-chk-tenant-id="${tid}">Rimuovi</button>
            </div>
          </div>
        `;
      }).join('') + `</div>`;
    };

    const clientTenantId = parseInt(String(this.getActivePlan()?.client_tenant_id || '0'), 10) || 0;

    wrap.innerHTML = sectionKeys.map(sk => {
      const secTitle = sectionTitleByKey.get(sk) || sk;
      const rows = bySection[sk] || [];
      return `
        <div style="margin-top: 14px; padding: 10px; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md); background: #fff;">
          <div style="font-weight:700; margin-bottom:8px;">${this.escapeHtml(secTitle)}</div>
          <div style="overflow:auto;">
            <table class="planning-table">
              <thead>
                <tr>
                  <th style="min-width:180px;">Fase</th>
                  <th>Voce</th>
                  <th style="width:110px;">Obblig.</th>
                  <th style="width:150px;">Stato</th>
                  <th style="min-width:240px;">Note/Testo</th>
                  <th style="min-width:320px;">Evidenze</th>
                  <th style="min-width:220px;">Auto-detected</th>
                  <th style="width:210px;">Azioni</th>
                </tr>
              </thead>
              <tbody>
                ${rows.map(it => {
                  const id = parseInt(String(it?.id || '0'), 10) || 0;
                  const title = String(it?.title || '').trim();
                  const desc = String(it?.description || '').trim();
                  const qType = String(it?.question_type || '').trim().toLowerCase();
                  const required = !!it?.required;
                  const st = String(it?.status || 'missing').trim().toLowerCase();
                  const ansText = String(it?.answer_text || '');
                  const ansJson = this.parseJsonBestEffort(it?.answer_json);
                  const ev = this.parseJsonArrayBestEffort(it?.evidence_json);
                  const autoInfo = this.getAssistantAutoInfoForItem(it);

                  const reqBadge = required
                    ? `<span class="planning-pill" style="background: rgba(220, 38, 38, 0.12); color: rgb(185, 28, 28);">SI</span>`
                    : `<span class="planning-pill" style="background: rgba(107, 114, 128, 0.12); color: rgb(55, 65, 81);">NO</span>`;

                  const statusSel = `
                    <select class="form-control" data-chk-item-id="${id}" data-chk-field="status">
                      ${statusOptions.map(s => `<option value="${this.escapeAttr(s)}"${s === st ? ' selected' : ''}>${this.escapeHtml(s)}</option>`).join('')}
                    </select>
                  `;

                  let inputHtml = `<div class="planning-muted">—</div>`;
                  let evidenceHtml = `<div class="planning-muted">—</div>`;
                  let actionsHtml = `<div class="planning-muted">—</div>`;

                  if (qType === 'text') {
                    inputHtml = `<input class="form-control" data-chk-item-id="${id}" data-chk-field="answer_text" value="${this.escapeAttr(ansText)}" placeholder="Testo..." />`;
                  } else if (qType === 'multiline') {
                    inputHtml = `<textarea class="form-control" rows="3" data-chk-item-id="${id}" data-chk-field="answer_text" placeholder="Testo...">${this.escapeHtml(ansText)}</textarea>`;
                  } else if (qType === 'bool') {
                    const v = (ansJson === true) || String(ansText).toLowerCase() === 'true';
                    inputHtml = `
                      <div style="display:flex; flex-direction:column; gap: 8px;">
                        <label class="form-checkbox-label">
                          <input type="checkbox" class="form-checkbox" data-chk-item-id="${id}" data-chk-field="answer_bool"${v ? ' checked' : ''}>
                          <span>Confermato</span>
                        </label>
                        <textarea class="form-control" rows="2" data-chk-item-id="${id}" data-chk-field="answer_text" placeholder="Note (opzionale)">${this.escapeHtml(ansText)}</textarea>
                      </div>
                    `;
                  } else if (qType === 'select') {
                    inputHtml = `<input class="form-control" data-chk-item-id="${id}" data-chk-field="answer_text" value="${this.escapeAttr(ansText)}" placeholder="Valore..." />`;
                  } else if (qType === 'file' || qType === 'multi_file') {
                    evidenceHtml = `${renderEvidence(ev, id, clientTenantId)}`;
                    actionsHtml = `
                      <div class="planning-actions" style="flex-wrap:wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" data-chk-action="add-evidence" data-chk-item-id="${id}" data-chk-qtype="${this.escapeAttr(qType)}">Aggiungi evidenza</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-chk-action="clear-evidence" data-chk-item-id="${id}">Svuota</button>
                      </div>
                    `;
                  }

                  const autoHtml = autoInfo ? `
                    <div>
                      <div style="font-weight:700;">Confidenza ${autoInfo.confidence}%</div>
                      <div class="planning-muted" style="font-size:12px;">File: ${autoInfo.files.map(x => `#${x.file_id}`).join(', ')}</div>
                    </div>
                  ` : `<div class="planning-muted">—</div>`;

                  return `
                    <tr>
                      <td class="planning-muted" style="font-size:12px;">${this.escapeHtml(secTitle)}</td>
                      <td style="min-width: 240px;">
                        <div style="font-weight:700;">${this.escapeHtml(title)}</div>
                        ${desc ? `<div class="planning-muted" style="margin-top:4px;">${this.escapeHtml(desc)}</div>` : ``}
                      </td>
                      <td>${reqBadge}</td>
                      <td>${statusSel}</td>
                      <td>${inputHtml}</td>
                      <td>${evidenceHtml}</td>
                      <td>${autoHtml}</td>
                      <td>${actionsHtml}</td>
                    </tr>
                  `;
                }).join('')}
              </tbody>
            </table>
          </div>
        </div>
      `;
    }).join('');

    // Bind dynamic inputs within assistant table
    wrap.querySelectorAll('[data-chk-field]').forEach(el => {
      if (el.dataset?.cnxBound === '1') return;
      const field = String(el.getAttribute('data-chk-field') || '');
      const itemId = parseInt(String(el.getAttribute('data-chk-item-id') || '0'), 10) || 0;
      if (!itemId || !field) return;

      const onCommit = async () => {
        try {
          if (field === 'status') {
            await this.updateChecklistItem(itemId, { status: String(el.value || '') });
          } else if (field === 'answer_text') {
            await this.updateChecklistItem(itemId, { answer_text: String(el.value || '') });
          } else if (field === 'answer_bool') {
            await this.updateChecklistItem(itemId, { answer_json: !!el.checked });
          }
        } catch (_) {}
      };

      const tag = String(el.tagName || '').toLowerCase();
      if (field === 'answer_text') {
        const key = `chk:${itemId}:answer_text:assistant`;
        const debounceMs = 500;
        el.addEventListener('input', () => {
          try { if (tag === 'textarea') this.autoGrowTextarea(el); } catch (_) {}
          try {
            const prev = this._checklistDebounceTimers?.get(key);
            if (prev) clearTimeout(prev);
            const t = setTimeout(() => { onCommit(); }, debounceMs);
            this._checklistDebounceTimers?.set(key, t);
          } catch (_) {}
        });
        el.addEventListener('blur', () => {
          try {
            const prev = this._checklistDebounceTimers?.get(key);
            if (prev) clearTimeout(prev);
            this._checklistDebounceTimers?.delete(key);
          } catch (_) {}
          onCommit();
        });
      } else {
        el.addEventListener('change', onCommit);
      }
      el.dataset.cnxBound = '1';
    });

    wrap.querySelectorAll('[data-chk-action]').forEach(btn => {
      if (btn.dataset?.cnxBound === '1') return;
      btn.addEventListener('click', async () => {
        const act = String(btn.getAttribute('data-chk-action') || '');
        const itemId = parseInt(String(btn.getAttribute('data-chk-item-id') || '0'), 10) || 0;
        if (!itemId) return;
        if (act === 'clear-evidence') {
          await this.clearChecklistEvidenceForItem(itemId);
          return;
        }
        if (act === 'remove-evidence') {
          const fid = parseInt(String(btn.getAttribute('data-chk-file-id') || '0'), 10) || 0;
          if (fid > 0) await this.clearChecklistEvidenceForItem(itemId, fid);
          return;
        }
        if (act === 'add-evidence') {
          const qType = String(btn.getAttribute('data-chk-qtype') || 'file');
          const raw = String(prompt(qType === 'multi_file' ? 'Inserisci file_id (anche "1,2,3"):' : 'Inserisci file_id:', '') || '').trim();
          if (!raw) return;
          const ids = raw.split(',').map(s => parseInt(String(s).trim(), 10) || 0).filter(n => n > 0);
          if (!ids.length) return;
          const use = (qType === 'multi_file') ? ids : [ids[0]];
          await this.addChecklistEvidenceForItem(itemId, use);
        }
      });
      btn.dataset.cnxBound = '1';
    });
  }

  renderAssistantChat() {
    const wrap = document.getElementById('planningAssistantChatMessages');
    if (!wrap) return;
    const msgs = Array.isArray(this.assistantMessages) ? this.assistantMessages : [];
    if (!msgs.length) {
      wrap.innerHTML = `<div class="planning-muted">Nessun messaggio. Avvia l’intervista per iniziare.</div>`;
      return;
    }
    wrap.innerHTML = msgs.map(m => {
      const role = String(m.role || 'assistant');
      const meta = m.meta || null;
      const citations = Array.isArray(meta?.citations) ? meta.citations : [];
      return `
        <div class="planning-assistant-chat-bubble ${role === 'assistant' ? 'assistant' : ''}">
          <div>${this.escapeHtml(String(m.content || ''))}</div>
          ${citations.length ? `<div class="planning-assistant-chat-meta">Fonti: ${citations.map(c => `#${c.file_id}`).join(', ')}</div>` : ''}
        </div>
      `;
    }).join('');
    wrap.scrollTop = wrap.scrollHeight + 100;
  }

  async loadAssistantDocContext({ force } = {}) {
    try {
      await this.checklistAnalyzeClientDocs();
    } catch (_) {}
    this.renderAssistantDocSummary();
    this.renderAssistantChecklistTable();
  }

  async startAssistantSession() {
    const plan = this.getActivePlan();
    const planId = plan ? (parseInt(plan.id, 10) || 0) : 0;
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    if (!planId || !clientTenantId) return this.toast('Seleziona un piano valido', 'error');
    if (!this.activeChecklist) return this.toast('Crea prima la checklist da template', 'error');

    try {
      this.startProgress('Avvio intervista AI…');
      await this.ensureAssistantProviders();
      const res = await this.apiFetch('ai/assistant_checklist.php?action=start_session', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_tenant_id: clientTenantId,
          plan_id: planId,
          checklist_id: parseInt(String(this.activeChecklist?.id || '0'), 10) || 0,
          template_key: this.getChecklistTemplateKey(),
        }),
      });
      this.assistantSessionId = parseInt(String(res?.data?.session_id || '0'), 10) || 0;
      this.assistantMessages = [];
      this.assistantNextQuestion = '';
      this.renderAssistantChat();
      const statusEl = document.getElementById('planningAssistantStatusMsg');
      if (statusEl) statusEl.textContent = 'Intervista avviata. Puoi rispondere alle domande.';
      await this.sendAssistantMessage({ message: 'Avvia intervista guidata per la raccolta dati.' });
    } catch (e) {
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Errore avvio intervista';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.finishProgress();
    }
  }

  async sendAssistantMessage({ message, useNextQuestion } = {}) {
    const inputEl = document.getElementById('planningAssistantChatInput');
    let text = message;
    if (!text && useNextQuestion) text = this.assistantNextQuestion;
    if (!text && inputEl) text = String(inputEl.value || '').trim();
    text = String(text || '').trim();
    if (!text) return this.toast('Inserisci un messaggio', 'error');
    if (!this.assistantSessionId) return this.toast('Avvia prima l’intervista', 'error');

    this.assistantMessages.push({ role: 'user', content: text });
    if (inputEl) inputEl.value = '';
    this.renderAssistantChat();

    try {
      this.assistantLoading = true;
      await this.ensureAssistantProviders();
      const res = await this.apiFetch('ai/assistant_checklist.php?action=send', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          session_id: this.assistantSessionId,
          message: text,
          provider: this.assistantProvider || 'openai',
          model: this.assistantModel || '',
        }),
      });
      const data = res?.data || {};
      const assistantMsg = String(data.assistant_message || '').trim();
      const nextQ = String(data.next_question || '').trim();
      this.assistantNextQuestion = nextQ;
      const citations = Array.isArray(data.citations) ? data.citations : [];
      if (assistantMsg) {
        this.assistantMessages.push({ role: 'assistant', content: assistantMsg, meta: { citations } });
      }

      const updatedItems = Array.isArray(data.updated_items) ? data.updated_items : [];
      if (updatedItems.length) {
        updatedItems.forEach(u => {
          const idx = (this.activeChecklistItems || []).findIndex(x => String(x.id) === String(u.id));
          if (idx >= 0) this.activeChecklistItems[idx] = { ...this.activeChecklistItems[idx], ...u };
        });
        this.renderAssistantChecklistTable();
      }

      this.renderAssistantChat();
    } catch (e) {
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Errore invio messaggio AI';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.assistantLoading = false;
    }
  }

  async updateChecklistItem(itemId, patch) {
    const id = parseInt(String(itemId || '0'), 10) || 0;
    if (!id) return;
    const payload = { csrf_token: this.csrfToken, item_id: id, ...patch };
    const res = await this.apiFetch('consulting_plans/checklists/checklist_item_update.php', {
      method: 'POST',
      json: true,
      body: JSON.stringify(payload),
    });
    const updated = res?.data?.item || null;
    if (updated) {
      const idx = (this.activeChecklistItems || []).findIndex(x => String(x.id) === String(id));
      if (idx >= 0) this.activeChecklistItems[idx] = { ...this.activeChecklistItems[idx], ...updated };
    }
    this.renderChecklistTab();
  }

  async addChecklistEvidenceForItem(itemId, fileIds) {
    const id = parseInt(String(itemId || '0'), 10) || 0;
    if (!id) return;
    const plan = this.getActivePlan();
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    if (!clientTenantId) return;
    const ids = Array.isArray(fileIds) ? fileIds.map(x => (parseInt(String(x || '0'), 10) || 0)).filter(n => n > 0) : [];
    if (!ids.length) return;

    const useEvidenceApi = (this.checklistEvidenceTableSupported !== false);
    for (const fid of ids) {
      if (useEvidenceApi) {
        try {
          const res = await this.apiFetch('consulting_plans/checklists/checklist_evidence_add.php', {
            method: 'POST',
            json: true,
            body: JSON.stringify({ csrf_token: this.csrfToken, item_id: id, file_id: fid }),
          });
          const updated = res?.data?.item || null;
          if (updated) {
            const idx = (this.activeChecklistItems || []).findIndex(x => String(x.id) === String(id));
            if (idx >= 0) this.activeChecklistItems[idx] = { ...this.activeChecklistItems[idx], ...updated };
          }
          continue;
        } catch (e) {
          // fall back to legacy evidence_json update
        }
      }

      // Legacy fallback: update evidence_json directly (best-effort; no file validation)
      const cur = (this.activeChecklistItems || []).find(x => String(x.id) === String(id)) || null;
      const qType = String(cur?.question_type || 'file').trim().toLowerCase();
      const evCur = this.parseJsonArrayBestEffort(cur?.evidence_json);
      const next = (qType === 'multi_file') ? evCur.slice(0) : [];
      next.unshift({ tenant_id: clientTenantId, file_id: fid, name: '', path: '' });
      const seen = new Set();
      const ded = [];
      next.forEach(x => {
        const tid = parseInt(String(x?.tenant_id || '0'), 10) || 0;
        const fid2 = parseInt(String(x?.file_id || '0'), 10) || 0;
        const k = `${tid}:${fid2}`;
        if (!tid || !fid2 || seen.has(k)) return;
        seen.add(k);
        ded.push(x);
      });
      await this.updateChecklistItem(id, { evidence_json: ded, status: 'present' });
      return;
    }
    this.renderChecklistTab();
  }

  async clearChecklistEvidenceForItem(itemId, fileId = 0) {
    const id = parseInt(String(itemId || '0'), 10) || 0;
    if (!id) return;
    const fid = parseInt(String(fileId || '0'), 10) || 0;

    const useEvidenceApi = (this.checklistEvidenceTableSupported !== false);
    if (useEvidenceApi) {
      try {
        const body = { csrf_token: this.csrfToken, item_id: id };
        if (fid > 0) body.file_id = fid;
        const res = await this.apiFetch('consulting_plans/checklists/checklist_evidence_clear.php', {
          method: 'POST',
          json: true,
          body: JSON.stringify(body),
        });
        const updated = res?.data?.item || null;
        if (updated) {
          const idx = (this.activeChecklistItems || []).findIndex(x => String(x.id) === String(id));
          if (idx >= 0) this.activeChecklistItems[idx] = { ...this.activeChecklistItems[idx], ...updated };
        }
        this.renderChecklistTab();
        return;
      } catch (e) {
        // fall back to legacy evidence_json update
      }
    }

    // Legacy fallback: clear evidence_json directly
    const cur = (this.activeChecklistItems || []).find(x => String(x.id) === String(id)) || null;
    const evCur = this.parseJsonArrayBestEffort(cur?.evidence_json);
    let next = [];
    if (fid > 0) {
      next = evCur.filter(x => {
        const fid2 = parseInt(String(x?.file_id || '0'), 10) || 0;
        return fid2 !== fid;
      });
    } else {
      next = [];
    }
    await this.updateChecklistItem(id, { evidence_json: next });
  }

  async createChecklistFromTemplateForActivePlan() {
    const plan = this.getActivePlan();
    const planId = plan ? (parseInt(plan.id, 10) || 0) : 0;
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    if (!planId || !clientTenantId) return this.toast('Seleziona un piano', 'error');

    const objEl = document.getElementById('planningChecklistObjectiveText');
    const objectiveText = String(objEl?.value || '').trim();
    if (!objectiveText) {
      this.toast('Obiettivo checklist obbligatorio', 'error');
      try { objEl?.focus?.(); } catch (_) {}
      return;
    }
    if (this.checklistObjectiveSupported === false) {
      this.toast('Checklist: DB non aggiornato (applica migrazione 65 per objective_text)', 'error');
      return;
    }

    try {
      this.startProgress('Creazione checklist…');
      await this.apiFetch('consulting_plans/checklists/checklist_create.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          plan_id: planId,
          company_id: clientTenantId,
          template_key: this.getChecklistTemplateKey(),
          objective_text: objectiveText,
        }),
      });
      this.toast('Checklist creata', 'success');
      await this.loadChecklistForActivePlan({ force: true });
    } catch (e) {
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Errore creazione checklist';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.finishProgress();
    }
  }

  async autofillChecklistFromDocsForActivePlan() {
    const plan = this.getActivePlan();
    const planId = plan ? (parseInt(plan.id, 10) || 0) : 0;
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    if (!planId || !clientTenantId) return this.toast('Seleziona un piano', 'error');
    if (!this.activeChecklist) return this.toast('Crea prima la checklist da template', 'error');
    try {
      this.startProgress('Precompilazione checklist…');

      // Best-effort: run doc analysis first (if intervention_type is available).
      // This enriches matching (doc_evidence tags/keywords) but is non-blocking if AI is unavailable.
      const it = this.getActivePlanInterventionTypeBestEffort();
      if (it) {
        try {
          const resAnalyze = await this.apiFetch('consulting_plans/client_docs_analyze.php', {
            method: 'POST',
            json: true,
            body: JSON.stringify({
              csrf_token: this.csrfToken,
              client_tenant_id: clientTenantId,
              standard_codes: this.getProvisioningStandardsForUi(),
              intervention_type: it,
            }),
          });
          const prof = (resAnalyze?.data && typeof resAnalyze.data.payload === 'object') ? resAnalyze.data.payload : null;
          if (prof) this.activeChecklistDocProfile = prof;
        } catch (_) {
          // ignore analyze errors; proceed with metadata-based autofill
        }
      }

      const res = await this.apiFetch('consulting_plans/checklists/checklist_autofill_from_docs.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          plan_id: planId,
          company_id: clientTenantId,
          template_key: this.getChecklistTemplateKey(),
        }),
      });
      const data = res?.data || {};
      const updated = parseInt(String(data.updated_items || '0'), 10) || 0;
      this.toast(`Precompilazione completata (aggiornati: ${updated})`, 'success');

      // Use doc_profile summary (if returned) for UI box
      const prof = data?.doc_profile || null;
      if (prof && typeof prof === 'object') {
        const base = (this.activeChecklistDocProfile && typeof this.activeChecklistDocProfile === 'object') ? this.activeChecklistDocProfile : {};
        this.activeChecklistDocProfile = {
          ...base,
          maturity_suggested: String(prof.maturity_suggested || ''),
          confidence: parseInt(String(prof.confidence || '0'), 10) || 0,
          planning_adjustments: prof.planning_adjustments || null,
          gaps: Array.isArray(prof.gaps) ? prof.gaps : [],
        };
      }

      await this.loadChecklistForActivePlan({ force: true });
    } catch (e) {
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Errore rilevamento documenti';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.finishProgress();
    }
  }

  async checklistReindexClientDocs() {
    const plan = this.getActivePlan();
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    if (!clientTenantId) return this.toast('Piano non valido (manca azienda cliente)', 'error');
    try {
      this.startProgress('Reindicizzazione documenti…');
      const res = await this.apiFetch('consulting_plans/client_docs_reindex.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, client_tenant_id: clientTenantId, force: true }),
      });
      const st = String(res?.data?.status || '').trim();
      if (st === 'fresh_skip') this.toast('Indicizzazione già aggiornata (≤10 min)', 'success');
      else if (st === 'locked') this.toast('Indicizzazione già in corso (lock attivo)', 'warning');
      else this.toast('Reindicizzazione avviata (best-effort)', 'success');
    } catch (e) {
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Reindicizzazione non disponibile';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.finishProgress();
    }
  }

  async checklistAnalyzeClientDocs() {
    const plan = this.getActivePlan();
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    if (!clientTenantId) return this.toast('Piano non valido (manca azienda cliente)', 'error');
    const it = this.getActivePlanInterventionTypeBestEffort();
    if (!it) {
      return this.toast('Analisi documenti: manca Tipo intervento nel piano (usa il wizard stima per generarlo).', 'error');
    }
    try {
      this.startProgress('Analisi documenti…');

      // Step 1: snapshot (best-effort)
      let snap = null;
      try {
        const r = await this.apiFetch(`consulting_plans/client_docs_snapshot.php?client_tenant_id=${encodeURIComponent(String(clientTenantId))}&csrf_token=${encodeURIComponent(String(this.csrfToken || ''))}`, { method: 'GET', json: true });
        snap = r?.data || null;
      } catch (_) {
        snap = null;
      }

      // Step 2: if stale/never, trigger reindex best-effort (non-blocking)
      try {
        const lastIdx = String(snap?.knowledge?.last_indexed_at || '').trim();
        const stale = !!snap?.stale;
        if (!lastIdx || stale) {
          await this.apiFetch('consulting_plans/client_docs_reindex.php', {
            method: 'POST',
            json: true,
            body: JSON.stringify({ csrf_token: this.csrfToken, client_tenant_id: clientTenantId, force: true }),
          });
        }
      } catch (_) {
        // ignore reindex errors
      }

      const res = await this.apiFetch('consulting_plans/client_docs_analyze.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_tenant_id: clientTenantId,
          standard_codes: this.getProvisioningStandardsForUi(),
          intervention_type: it,
        }),
      });
      const data = res?.data || {};
      const prof = (data && typeof data.payload === 'object') ? data.payload : null;
      if (prof) {
        this.activeChecklistDocProfile = prof;
        this.toast('Analisi completata', 'success');
        this.renderChecklistTab();
      } else {
        this.toast('Analisi completata (nessun payload)', 'success');
      }
    } catch (e) {
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Analisi non disponibile';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.finishProgress();
    }
  }

  exportChecklistXlsxForActivePlan() {
    const planId = parseInt(String(this.activePlanId || '0'), 10) || 0;
    if (!planId) return this.toast('Seleziona un piano', 'error');
    const tpl = this.getChecklistTemplateKey();
    const url = `${this.apiBase}consulting_plans/checklists/checklist_export_xlsx.php?plan_id=${encodeURIComponent(String(planId))}&template_key=${encodeURIComponent(String(tpl || ''))}&csrf_token=${encodeURIComponent(String(this.csrfToken || ''))}`;
    try {
      window.open(url, '_blank', 'noopener');
    } catch (_) {
      window.location.href = url;
    }
  }

  openBlueprintModal({ mode = 'new' } = {}) {
    if (!this.activePlanId) {
      this.toast('Seleziona un piano', 'error');
      return;
    }
    const plan = this.plans.find(p => String(p.id) === String(this.activePlanId));
    const clientTenantId = plan ? parseInt(plan.client_tenant_id, 10) : 0;
    if (!clientTenantId) {
      this.toast('Piano non valido (manca azienda cliente)', 'error');
      return;
    }

    // Defaults: for edit-mode reuse values saved on plan/localStorage; for new-mode reset to base defaults.
    const lsKey = `cnx_bp_inputs_plan_${String(this.activePlanId)}`;
    let saved = null;
    try {
      saved = JSON.parse(localStorage.getItem(lsKey) || 'null');
    } catch (e) {
      saved = null;
    }

    const standardsSel = document.getElementById('planningBlueprintStandards');
    if (standardsSel) {
      let std = null;
      if (mode === 'edit') {
        try {
          if (plan?.blueprint_standards_json) std = JSON.parse(plan.blueprint_standards_json);
        } catch (e) {}
        if (!Array.isArray(std) || !std.length) std = Array.isArray(saved?.standards) ? saved.standards : null;
      }
      if (!Array.isArray(std) || !std.length) std = ['ISO 9001'];

      const set = new Set(std.map(s => String(s)));
      [...standardsSel.options].forEach(o => { o.selected = set.has(String(o.value)); });
      this.enableClickToggleMultiSelect(standardsSel);
    }

    const deadlineEl = document.getElementById('planningBlueprintDeadline');
    if (deadlineEl) {
      const d = (plan?.period_end || '').trim();
      deadlineEl.value = d;
    }

    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    const bd = (mode === 'edit') ? ((plan?.client_business_description ?? saved?.business_description ?? '') || '') : '';
    const sc = (mode === 'edit') ? ((plan?.client_desired_scope_hint ?? saved?.desired_scope_hint ?? '') || '') : '';
    const ec = (mode === 'edit') ? (plan?.client_employee_count ?? saved?.employee_count ?? '') : '';
    const stc = (mode === 'edit') ? (plan?.client_sites_count ?? saved?.sites_count ?? '') : '';
    setVal('planningBlueprintBusinessDescription', String(bd || ''));
    setVal('planningBlueprintEmployeeCount', (ec === null || ec === undefined) ? '' : String(ec));
    setVal('planningBlueprintSitesCount', (stc === null || stc === undefined) ? '' : String(stc));
    setVal('planningBlueprintDesiredScopeHint', String(sc || ''));

    this.openModal('planningBlueprintModal');
  }

  getSelectedValues(selectEl) {
    if (!selectEl) return [];
    return [...selectEl.selectedOptions].map(o => String(o.value || '').trim()).filter(Boolean);
  }

  async generateBlueprintFromModal() {
    if (!this.activePlanId) return this.toast('Seleziona un piano', 'error');
    const plan = this.plans.find(p => String(p.id) === String(this.activePlanId));
    const planId = plan ? (parseInt(plan.id, 10) || 0) : 0;
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    if (!planId || !clientTenantId) return this.toast('Piano non valido', 'error');

    const standards = this.getSelectedValues(document.getElementById('planningBlueprintStandards'));
    const deadline = (document.getElementById('planningBlueprintDeadline')?.value || '').trim();
    const businessDescription = (document.getElementById('planningBlueprintBusinessDescription')?.value || '').trim();
    const desiredScopeHint = (document.getElementById('planningBlueprintDesiredScopeHint')?.value || '').trim();
    const employeeCountRaw = (document.getElementById('planningBlueprintEmployeeCount')?.value || '').trim();
    const sitesCountRaw = (document.getElementById('planningBlueprintSitesCount')?.value || '').trim();
    const employeeCount = employeeCountRaw === '' ? null : (parseInt(employeeCountRaw, 10) || 0);
    const sitesCount = sitesCountRaw === '' ? null : (parseInt(sitesCountRaw, 10) || 0);

    // Persist inputs (localStorage) + best-effort persist on plan (migration 43)
    try {
      const lsKey = `cnx_bp_inputs_plan_${String(planId)}`;
      localStorage.setItem(lsKey, JSON.stringify({
        standards,
        business_description: businessDescription,
        desired_scope_hint: desiredScopeHint,
        employee_count: employeeCount,
        sites_count: sitesCount
      }));
    } catch (_) {}

    try {
      await this.apiFetch('consulting_plans/update.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          id: planId,
          client_business_description: businessDescription || null,
          client_desired_scope_hint: desiredScopeHint || null,
          client_employee_count: employeeCount,
          client_sites_count: sitesCount,
          blueprint_standards_json: standards?.length ? JSON.stringify(standards) : null
        }),
      });
      try { await this.loadPlans(); } catch (_) {}
    } catch (_) {
      // non-blocking (schema drift)
    }

    const btn = document.getElementById('planningBlueprintGenerateBtn');
    const prevLabel = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Generazione...'; }

    try {
      this.startProgress('Generazione struttura documentale…');
      const res = await this.apiFetch('consulting_plans/proposal_generate.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          action: 'generate_blueprint',
          plan_id: planId,
          client_tenant_id: clientTenantId,
          objective_activity_type_id: 0,
          objective_overrides: {},
          deadline: deadline || '',
          standards,
          client_profile: {
            business_description: businessDescription || '',
            employee_count: employeeCount,
            sites_count: sitesCount,
            desired_scope_hint: desiredScopeHint || '',
          },
          starting_point: 'greenfield',
        }),
      });

      const bp = res.data?.compliance_blueprint;
      if (!bp) throw new Error('Struttura documentale non valida (manca compliance_blueprint)');

      this.blueprintResult = res.data;
      this.blueprintPlanId = planId;
      this.closeModal('planningBlueprintModal');
      this.renderBlueprint(res.data);
      this.toast('Struttura documentale generata', 'success');
    } catch (e) {
      console.error('[Blueprint] generate error:', e);
      const msg = (e?.message || '').trim();
      const text = msg || 'Errore generazione struttura documentale';
      this.toast(text, 'error');
      // Also show error in blueprint section so it doesn't feel "stuck"
      try {
        const apiData = e?.data?.data || null;
        const debug = apiData?.debug || null;
        const debugLine = debug && (debug.kind || debug.http)
          ? `Dettagli tecnici: ${this.escapeHtml(String(debug.kind || ''))}${debug.http ? this.escapeHtml(` (http ${debug.http})`) : ''}`
          : '';
        const content = document.getElementById('planningBlueprintContent');
        if (content) {
          content.innerHTML = `
            <div style="font-weight:700; margin-bottom:6px;">Errore generazione struttura documentale</div>
            <div class="planning-muted" style="margin-bottom:10px;">${this.escapeHtml(text)}</div>
            ${debugLine ? `<div class="planning-muted" style="margin-bottom:10px; font-size:12px;">${debugLine}</div>` : ''}
            <div class="planning-muted" style="font-size:12px;">
              Suggerimenti: verifica <code>OPENAI_API_KEY</code> in <code>config.secrets.php</code> e controlla <code>logs/php_errors.log</code> (righe che iniziano con <code>[OPENAI]</code>).
            </div>
          `;
        }
      } catch (_) {}
    } finally {
      this.finishProgress();
      if (btn) { btn.disabled = false; btn.textContent = prevLabel || 'Genera'; }
    }
  }

  renderBlueprint(data) {
    const content = document.getElementById('planningBlueprintContent');
    const warn = document.getElementById('planningBlueprintStorageWarning');
    const exportBtn = document.getElementById('planningBlueprintExportBtn');
    const provisionBtn = document.getElementById('planningBlueprintProvisionBtn');
    const tasksBtn = document.getElementById('planningBlueprintTasksBtn');
    const openBtn = document.getElementById('planningBlueprintOpenBtn');
    const editBtn = document.getElementById('planningBlueprintEditInputsBtn');
    if (!content) return;

    const storageAvailable = !!data?.storage_available;
    const stored = !!data?.stored;
    if (warn) {
      if (!storageAvailable) {
        warn.style.display = 'block';
        warn.textContent = 'Struttura documentale non salvata: migrazione 38 non applicata (storage non disponibile).';
      } else if (!stored) {
        warn.style.display = 'block';
        warn.textContent = 'Struttura generata, ma non è stata salvata (best-effort).';
      } else {
        warn.style.display = 'none';
        warn.textContent = '';
      }
    }

    if (exportBtn) exportBtn.style.display = 'inline-flex';
    if (provisionBtn) provisionBtn.style.display = (this.activePlanId ? 'inline-flex' : 'none');
    if (tasksBtn) tasksBtn.style.display = (this.activePlanId ? 'inline-flex' : 'none');
    if (openBtn) openBtn.textContent = 'Genera nuovo';
    if (editBtn) editBtn.style.display = 'inline-flex';

    const bp = data?.compliance_blueprint || {};
    const meta = data?.meta || {};
    const standards = Array.isArray(meta?.standards) ? meta.standards : (Array.isArray(bp.standards) ? bp.standards : []);

    const list = (arr) => Array.isArray(arr) && arr.length
      ? `<ul style="margin:6px 0 0 18px;">${arr.map(x => `<li>${this.escapeHtml(String(x || ''))}</li>`).join('')}</ul>`
      : `<div class="planning-muted">—</div>`;

    const repo = Array.isArray(bp.repository_structure) ? bp.repository_structure : [];
    const docs = Array.isArray(bp.documents) ? bp.documents : [];
    const recs = Array.isArray(bp.records_register) ? bp.records_register : [];

    content.innerHTML = `
      <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start;">
        <div style="flex:1; min-width: 280px;">
          <div style="font-weight:600; margin-bottom:6px;">Standards</div>
          ${list(standards.map(s => `${s.code} (${s.edition})`))}
        </div>
        <div style="flex:1; min-width: 280px;">
          <div style="font-weight:600; margin-bottom:6px;">Meta</div>
          <div class="planning-muted">Confidence: ${this.escapeHtml(String(meta.confidence ?? '—'))}</div>
          ${Array.isArray(meta.risks) && meta.risks.length ? `<div style="margin-top:6px;"><div style="font-weight:600;">Rischi</div>${list(meta.risks)}</div>` : ''}
        </div>
      </div>

      <div style="margin-top:12px; font-weight:600;">Scope proposal</div>
      <div style="margin-top:6px;">${this.escapeHtml(String(bp.scope_proposal || ''))}</div>

      <div style="margin-top:12px; font-weight:600;">Assunzioni</div>
      ${list(bp.assumptions)}

      <div style="margin-top:12px; font-weight:600;">Domande aperte</div>
      ${list(bp.open_questions)}

      <div style="margin-top:12px; font-weight:600;">Repository structure (/IMS)</div>
      ${list(repo.map(x => x?.path || ''))}

      <div style="margin-top:12px; font-weight:600;">Documenti minimi</div>
      <div style="overflow-x:auto; margin-top:8px;">
        <table class="planning-table">
          <thead>
            <tr>
              <th>Codice</th>
              <th>Titolo</th>
              <th>Owner role</th>
              <th>Percorso</th>
            </tr>
          </thead>
          <tbody>
            ${docs.length ? docs.map(d => `
              <tr>
                <td>${this.escapeHtml(String(d.doc_code || ''))}</td>
                <td>${this.escapeHtml(String(d.title || ''))}</td>
                <td>${this.escapeHtml(String(d.owner_role || ''))}</td>
                <td><code>${this.escapeHtml(String(d.repository_path || ''))}</code></td>
              </tr>
            `).join('') : `<tr><td colspan="4" class="planning-muted">—</td></tr>`}
          </tbody>
        </table>
      </div>

      <div style="margin-top:12px; font-weight:600;">Registri / Evidenze</div>
      <div style="overflow-x:auto; margin-top:8px;">
        <table class="planning-table">
          <thead>
            <tr>
              <th>Record</th>
              <th>Owner role</th>
              <th>Esempi evidenze</th>
            </tr>
          </thead>
          <tbody>
            ${recs.length ? recs.map(r => `
              <tr>
                <td>${this.escapeHtml(String(r.record_name || ''))}</td>
                <td>${this.escapeHtml(String(r.owner_role || ''))}</td>
                <td class="planning-muted">${this.escapeHtml(Array.isArray(r.evidence_examples) ? r.evidence_examples.join('; ') : '')}</td>
              </tr>
            `).join('') : `<tr><td colspan="3" class="planning-muted">—</td></tr>`}
          </tbody>
        </table>
      </div>
    `;
  }

  exportBlueprintJson() {
    if (!this.blueprintResult) return this.toast('Nessuna struttura documentale da esportare', 'error');
    const json = JSON.stringify(this.blueprintResult, null, 2);
    const blob = new Blob([json], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const ts = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
    a.href = url;
    a.download = `compliance_blueprint_plan_${this.blueprintPlanId || 'unknown'}_${ts}.json`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 2500);
  }

  // ------------------------------
  // Compliance Provisioning (Leva 2/3)
  // ------------------------------
  getProvisioningStandardsForUi() {
    const labels = [];

    // 1) Prefer the active plan data (new flow: estimate_json / blueprint_standards_json)
    const plan = this.getActivePlan();
    if (plan) {
      // Optional (migration 43): blueprint_standards_json
      try {
        const raw = plan.blueprint_standards_json;
        if (raw) {
          const arr = (typeof raw === 'string') ? JSON.parse(raw) : raw;
          if (Array.isArray(arr)) {
            arr.forEach(v => {
              const s = String(v || '').trim();
              if (s) labels.push(s);
            });
          }
        }
      } catch (_) {
        // best-effort
      }

      // Optional (migration 50): estimate_json may contain selected service codes
      try {
        const raw = plan.estimate_json;
        let est = null;
        if (raw && typeof raw === 'string') {
          est = JSON.parse(raw);
        } else if (raw && typeof raw === 'object') {
          est = raw;
        }
        const estimates = Array.isArray(est?.estimates) ? est.estimates : [];
        estimates.forEach(e => {
          const sc = String(e?.service_code || '').trim();
          if (sc) labels.push(sc);
        });
      } catch (_) {
        // best-effort
      }
    }

    // 2) Legacy fallback: blueprintResult (if present)
    if (!labels.length && this.blueprintResult) {
      const meta = this.blueprintResult?.meta || {};
      const bp = this.blueprintResult?.compliance_blueprint || {};
      let standards = Array.isArray(meta?.standards) ? meta.standards : (Array.isArray(bp?.standards) ? bp.standards : []);
      if (!Array.isArray(standards)) standards = [];
      standards.forEach(s => {
        if (!s) return;
        if (typeof s === 'string') {
          const v = s.trim();
          if (v) labels.push(v);
          return;
        }
        const code = String(s.code || s.name || '').trim();
        if (!code) return;
        labels.push(code);
      });
    }

    // Normalize to standard codes used by IMS modules/templates
    const codes = labels.map(s => this.normalizeStandardCodeForModules(s)).filter(Boolean);
    const uniq = [...new Set(codes)].slice(0, 10);
    return uniq.length ? uniq : ['ISO9001'];
  }

  updateProvisionModalTitle() {
    const h = document.getElementById('planningComplianceProvisionTitle');
    if (!h) return;
    const std = this.getProvisioningStandardsForUi();
    const suffix = std.length ? ` — ${std.join(', ')}` : '';
    h.textContent = `Provisiona Compliance (IMS)${suffix}`;
  }

  async refreshProvisionPlanItemsCount() {
    const pid = parseInt(String(this.activePlanId || '0'), 10) || 0;
    if (!pid) return;
    // If items are already loaded for this plan, use them (avoid extra call)
    try {
      const items = Array.isArray(this.items) ? this.items : [];
      if (items.length) {
        const firstPid = parseInt(String(items[0]?.plan_id || '0'), 10) || 0;
        if (firstPid === pid) {
          this.provisionPlanItemsCount = items.length;
          this.provisionPlanItemsCountPlanId = String(pid);
          this.updateProvisionCreateTasksUi();
          return;
        }
      }
    } catch (_) {}

    try {
      const res = await this.apiFetch(`consulting_plans/items_count.php?plan_id=${encodeURIComponent(String(pid))}&csrf_token=${encodeURIComponent(String(this.csrfToken || ''))}`, { method: 'GET', json: true });
      const c = parseInt(String(res?.data?.items_count ?? '0'), 10) || 0;
      this.provisionPlanItemsCount = (c > 0 ? c : 0);
      this.provisionPlanItemsCountPlanId = String(pid);
    } catch (_) {
      // best-effort: keep unknown
      this.provisionPlanItemsCount = null;
      this.provisionPlanItemsCountPlanId = String(pid);
    }
    this.updateProvisionCreateTasksUi();
  }

  updateProvisionCreateTasksUi() {
    const chkDocs = document.getElementById('planningProvisionCreateDocuments');
    const chkTasks = document.getElementById('planningProvisionCreateTasks');
    const help = document.getElementById('planningProvisionCreateTasksHelp');
    if (!chkDocs || !chkTasks) return;

    const baseHelp = 'Crea task/checklist in Compliance (compilazione deliverable IMS).';

    // Contextual behavior requested:
    // - if the plan already has consulting_plan_items => disable and show a clear note (avoid any possible duplication/confusion)
    const pid = String(this.activePlanId || '');
    const countKnown = (String(this.provisionPlanItemsCountPlanId || '') === pid) && (this.provisionPlanItemsCount !== null);
    const hasPlanItems = countKnown ? ((parseInt(String(this.provisionPlanItemsCount || '0'), 10) || 0) > 0) : false;

    if (countKnown && hasPlanItems) {
      chkTasks.checked = false;
      chkTasks.disabled = true;
      if (help) help.textContent = 'Attività già presenti (create dal wizard): questa opzione non verrà applicata.';
      return;
    }

    // Default (legacy/manual plans): allow tasks
    chkTasks.disabled = false;
    if (help) help.textContent = baseHelp;
  }

  openComplianceProvisionModal() {
    if (!this.activePlanId) return this.toast('Seleziona un piano prima di provisionare', 'error');
    const box = document.getElementById('planningProvisionResult');
    if (box) { box.style.display = 'none'; box.textContent = ''; }
    this.refreshProvisionCompanyProfileUi();
    this.updateProvisionModalTitle();
    this.openModal('planningComplianceProvisionModal');

    // Keep "create tasks" checkbox meaningful (avoid confusing it with plan activities)
    this.updateProvisionCreateTasksUi();
    // Load plan items count (best-effort) to disable the checkbox when the plan already has items.
    this.refreshProvisionPlanItemsCount().catch(() => {});
    const chkDocs = document.getElementById('planningProvisionCreateDocuments');
    if (chkDocs && chkDocs.dataset.cnxBound !== '1') {
      chkDocs.addEventListener('change', () => this.updateProvisionCreateTasksUi());
      chkDocs.dataset.cnxBound = '1';
    }

    // Modules multi-select: allow toggle without Ctrl, and auto-preselect compat modules (best-effort)
    const sel = document.getElementById('planningProvisionModulesSelect');
    if (sel) {
      try { this.enableClickToggleMultiSelect(sel); } catch (_) {}
      this.loadImsModulesForProvisioning().catch(() => {});
    }

    const isoBtn = document.getElementById('planningProvisionSelectIso9001SgqCompletoBtn');
    if (isoBtn && isoBtn.dataset.cnxBound !== '1') {
      isoBtn.addEventListener('click', () => this.selectOnlyProvisionModule('ISO9001_SGQ_COMPLETO'));
      isoBtn.dataset.cnxBound = '1';
    }
    const deselBtn = document.getElementById('planningProvisionDeselectAllModulesBtn');
    if (deselBtn && deselBtn.dataset.cnxBound !== '1') {
      deselBtn.addEventListener('click', () => this.deselectAllProvisionModules());
      deselBtn.dataset.cnxBound = '1';
    }
  }

  deselectAllProvisionModules() {
    const sel = document.getElementById('planningProvisionModulesSelect');
    if (!sel) return;
    Array.from(sel.options || []).forEach(o => { if (o) o.selected = false; });
  }

  selectOnlyProvisionModule(moduleKey) {
    const key = String(moduleKey || '').trim().toUpperCase();
    if (!key) return;
    const sel = document.getElementById('planningProvisionModulesSelect');
    if (!sel) return;
    let found = false;
    Array.from(sel.options || []).forEach(o => {
      if (!o) return;
      const v = String(o.value || '').trim().toUpperCase();
      const is = (v === key);
      o.selected = is;
      if (is) found = true;
    });
    if (!found) this.toast(`Modulo non trovato: ${key}. Apri “Catalogo template IMS” e usa “Installa pack ISO 9001”.`, 'info');
  }

  openComplianceTasksModal() {
    if (!this.activePlanId) return this.toast('Seleziona un piano', 'error');
    if (!this.blueprintResult) return this.toast('Genera prima la struttura documentale', 'error');
    const chkDocs = document.getElementById('planningProvisionCreateDocuments');
    const chkTasks = document.getElementById('planningProvisionCreateTasks');
    if (chkDocs) chkDocs.checked = false;
    if (chkTasks) chkTasks.checked = true;
    this.openComplianceProvisionModal();
  }

  // ------------------------------
  // IMS Template Catalog (tenant 28)
  // ------------------------------
  openImsTemplateCatalogModal() {
    this.openModal('planningImsTemplateCatalogModal');
    this.loadImsTemplateCatalog({ force: false });
  }

  async installIso9001TemplatePack() {
    const btn = document.getElementById('planningImsInstallIso9001PackBtn');
    const prev = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = 'Installazione…'; }
    try {
      const res = await this.apiFetch('compliance/source_files.php?action=install_iso9001_pack', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken })
      });
      const w = Array.isArray(res?.data?.warnings) ? res.data.warnings : [];
      const msg = w.length ? ('Pack ISO 9001 installato (con warning)') : 'Pack ISO 9001 installato';
      this.toast(msg, w.length ? 'info' : 'success');
      await this.loadImsTemplateCatalog({ force: true });
      // Modules list may change; reload best-effort
      try { await this.loadImsModules({ force: true }); } catch (_) {}
    } catch (e) {
      this.toast(String(e?.message || e), 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = prev || 'Installa pack ISO 9001'; }
    }
  }

  // ------------------------------
  // Company profile (pre-provisioning, planning-side draft)
  // ------------------------------
  getCompanyProfileDraftStorageKey(planId) {
    return `cnx_company_profile_plan_${String(planId || '')}`;
  }

  getCompanyProfileSkipStorageKey(planId) {
    return `cnx_company_profile_skip_plan_${String(planId || '')}`;
  }

  getActivePlan() {
    return (this.plans || []).find(p => String(p.id) === String(this.activePlanId)) || null;
  }

  parseLines(text) {
    return String(text || '').split('\n').map(s => s.trim()).filter(Boolean);
  }

  isCompanyProfileMeaningful(p) {
    if (!p || typeof p !== 'object') return false;
    const name = String(p.company_name || '').trim();
    const hasAnyList = ['sites', 'products_services', 'products', 'processes', 'roles'].some(k => Array.isArray(p[k]) && p[k].some(x => String(x || '').trim()));
    const hasNotes = String(p.notes || '').trim().length > 0;
    return !!(name || hasAnyList || hasNotes);
  }

  loadCompanyProfileDraftForActivePlan() {
    const plan = this.getActivePlan();
    const planId = plan ? plan.id : null;
    if (!planId) return null;
    try {
      const raw = localStorage.getItem(this.getCompanyProfileDraftStorageKey(planId));
      const obj = raw ? JSON.parse(raw) : null;
      if (!obj || typeof obj !== 'object') return null;
      return obj;
    } catch (_) {
      return null;
    }
  }

  getPrefilledCompanyProfileDraft() {
    const plan = this.getActivePlan();
    const existing = this.loadCompanyProfileDraftForActivePlan() || {};
    const companyName = String(existing.company_name || '').trim() || String(plan?.client_name || '').trim() || '';
    return {
      company_name: companyName,
      sites: Array.isArray(existing.sites) ? existing.sites : [],
      products_services: Array.isArray(existing.products_services) ? existing.products_services : (Array.isArray(existing.products) ? existing.products : []),
      processes: Array.isArray(existing.processes) ? existing.processes : [],
      roles: Array.isArray(existing.roles) ? existing.roles : [],
      notes: String(existing.notes || ''),
    };
  }

  setCompanyProfileModalValues(draft) {
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = String(v || ''); };
    setVal('planningCompanyProfileCompanyName', draft.company_name || '');
    setVal('planningCompanyProfileSites', (Array.isArray(draft.sites) ? draft.sites : []).join('\n'));
    setVal('planningCompanyProfileProducts', (Array.isArray(draft.products_services) ? draft.products_services : []).join('\n'));
    setVal('planningCompanyProfileProcesses', (Array.isArray(draft.processes) ? draft.processes : []).join('\n'));
    setVal('planningCompanyProfileRoles', (Array.isArray(draft.roles) ? draft.roles : []).join('\n'));
    setVal('planningCompanyProfileNotes', draft.notes || '');
  }

  collectCompanyProfileModalValues() {
    const getVal = (id) => String(document.getElementById(id)?.value || '');
    return {
      company_name: getVal('planningCompanyProfileCompanyName').trim(),
      sites: this.parseLines(getVal('planningCompanyProfileSites')),
      products_services: this.parseLines(getVal('planningCompanyProfileProducts')),
      processes: this.parseLines(getVal('planningCompanyProfileProcesses')),
      roles: this.parseLines(getVal('planningCompanyProfileRoles')),
      notes: getVal('planningCompanyProfileNotes').trim(),
    };
  }

  saveCompanyProfileDraftToLocalStorage(planId, draft) {
    try {
      localStorage.setItem(this.getCompanyProfileDraftStorageKey(planId), JSON.stringify(draft || {}));
    } catch (_) {}
  }

  refreshProvisionCompanyProfileUi() {
    const plan = this.getActivePlan();
    const planId = plan ? plan.id : null;
    const statusEl = document.getElementById('planningProvisionCompanyProfileStatus');
    if (!statusEl) return;

    if (!planId) {
      statusEl.textContent = 'Seleziona un piano.';
      return;
    }

    const skip = (() => {
      try { return localStorage.getItem(this.getCompanyProfileSkipStorageKey(planId)) === '1'; } catch (_) { return false; }
    })();
    const draft = this.getPrefilledCompanyProfileDraft();
    const meaningful = this.isCompanyProfileMeaningful(draft);
    this.pendingCompanyProfile = meaningful ? draft : null;

    if (skip) {
      statusEl.textContent = 'Procedi comunque (placeholder) selezionato per questo piano.';
      return;
    }
    if (!meaningful) {
      statusEl.textContent = 'Non compilato. Ti verrà richiesto prima del provisioning.';
      return;
    }
    const name = String(draft.company_name || '').trim();
    statusEl.textContent = name ? `OK — ${name}` : 'OK';
  }

  openCompanyProfileModal() {
    const draft = this.getPrefilledCompanyProfileDraft();
    this.setCompanyProfileModalValues(draft);
    const msg = document.getElementById('planningCompanyProfileMsg');
    if (msg) { msg.style.display = 'none'; msg.textContent = ''; }
    this.openModal('planningCompanyProfileModal');
  }

  ensureCompanyProfileBeforeProvisioning() {
    const plan = this.getActivePlan();
    const planId = plan ? plan.id : null;
    if (!planId) return true;

    const skip = (() => {
      try { return localStorage.getItem(this.getCompanyProfileSkipStorageKey(planId)) === '1'; } catch (_) { return false; }
    })();
    if (skip) {
      this.pendingCompanyProfile = null;
      return true;
    }

    const draft = this.getPrefilledCompanyProfileDraft();
    const meaningful = this.isCompanyProfileMeaningful(draft);
    this.pendingCompanyProfile = meaningful ? draft : null;
    if (meaningful) return true;

    // Ask user now
    this.openCompanyProfileModal();
    return false;
  }

  skipCompanyProfileAndContinueProvisioning() {
    const plan = this.getActivePlan();
    const planId = plan ? plan.id : null;
    if (planId) {
      try { localStorage.setItem(this.getCompanyProfileSkipStorageKey(planId), '1'); } catch (_) {}
    }
    this.pendingCompanyProfile = null;
    this._bypassCompanyProfilePromptOnce = true;
    this.closeModal('planningCompanyProfileModal');
    this.refreshProvisionCompanyProfileUi();
    this.runComplianceProvisioningFromModal();
  }

  saveCompanyProfileAndContinueProvisioning() {
    const plan = this.getActivePlan();
    const planId = plan ? plan.id : null;
    if (!planId) return;

    const draft = this.collectCompanyProfileModalValues();
    if (!this.isCompanyProfileMeaningful(draft)) {
      const msg = document.getElementById('planningCompanyProfileMsg');
      if (msg) {
        msg.style.display = 'block';
        msg.textContent = 'Inserisci almeno la ragione sociale oppure procedi comunque (placeholder).';
      }
      return;
    }

    // clear any previous skip choice
    try { localStorage.removeItem(this.getCompanyProfileSkipStorageKey(planId)); } catch (_) {}

    this.saveCompanyProfileDraftToLocalStorage(planId, draft);
    this.pendingCompanyProfile = draft;
    this._bypassCompanyProfilePromptOnce = true;
    this.closeModal('planningCompanyProfileModal');
    this.refreshProvisionCompanyProfileUi();
    this.runComplianceProvisioningFromModal();
  }

  showImsTemplateCatalogWarning(text) {
    const box = document.getElementById('planningImsTemplateCatalogWarning');
    if (!box) return;
    if (!text) {
      box.style.display = 'none';
      box.textContent = '';
      return;
    }
    box.style.display = 'block';
    box.textContent = String(text);
  }

  showImsTemplateCatalogWarningHtml(html) {
    const box = document.getElementById('planningImsTemplateCatalogWarning');
    if (!box) return;
    if (!html) {
      box.style.display = 'none';
      box.innerHTML = '';
      return;
    }
    box.style.display = 'block';
    box.innerHTML = String(html);
  }

  async loadImsTemplateCatalog({ force = false } = {}) {
    const activeOnly = !!document.getElementById('planningImsTemplateActiveOnly')?.checked;
    if (!force && this.imsTemplatesLoadedAt && (Date.now() - this.imsTemplatesLoadedAt) < 15_000) {
      return this.renderImsTemplateCatalog();
    }

    const tbody = document.getElementById('planningImsTemplateCatalogTbody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="10" class="planning-muted">Caricamento...</td></tr>`;
    this.showImsTemplateCatalogWarning('');

    try {
      const res = await this.apiFetch(`compliance/templates.php?action=list&active_only=${activeOnly ? '1' : '0'}`, { method: 'GET' });
      const templates = Array.isArray(res?.data?.templates) ? res.data.templates : [];
      this.imsTemplates = templates;
      this.imsTemplatesLoadedAt = Date.now();
      this.renderImsTemplateCatalog();
    } catch (e) {
      console.error('[IMS Templates] load error', e);
      const msg = (e?.message || '').trim() || 'Errore caricamento catalogo template';
      const is503 = (e && e.status === 503) || false;
      const looksLikeM45 = msg.toLowerCase().includes('migrazione') && msg.includes('45');
      if (is503 && looksLikeM45) {
        this.showImsTemplateCatalogWarningHtml(`
          <div style="font-weight:700; margin-bottom:6px;">Catalogo template non inizializzato</div>
          <div class="planning-muted" style="margin-bottom:10px;">${this.escapeHtml(msg)}</div>
          <div class="planning-actions">
            <button type="button" class="btn btn-primary btn-sm" id="planningImsInitStorageBtn">Inizializza storage (migrazione 45)</button>
            <button type="button" class="btn btn-secondary btn-sm" id="planningImsDiagStorageBtn">Diagnostica DB</button>
          </div>
          <div class="planning-muted" style="margin-top:8px; font-size:12px;">Nota: l’inizializzazione richiede <strong>super_admin</strong> e CSRF.</div>
        `);
        setTimeout(() => {
          const btn = document.getElementById('planningImsInitStorageBtn');
          if (!btn || btn.dataset.cnxBound === '1') return;
          btn.addEventListener('click', async () => {
            btn.disabled = true;
            const prev = btn.textContent;
            btn.textContent = 'Inizializzazione…';
            try {
              await this.apiFetch('compliance/templates.php?action=init_storage', {
                method: 'POST',
                json: true,
                body: JSON.stringify({ csrf_token: this.csrfToken })
              });
              this.toast('Migrazione 45 applicata', 'success');
              await this.loadImsTemplateCatalog({ force: true });
            } catch (err) {
              this.toast(String(err?.message || err), 'error');
            } finally {
              btn.disabled = false;
              btn.textContent = prev || 'Inizializza storage (migrazione 45)';
            }
          });
          btn.dataset.cnxBound = '1';
        }, 0);

        setTimeout(() => {
          const diagBtn = document.getElementById('planningImsDiagStorageBtn');
          if (!diagBtn || diagBtn.dataset.cnxBound === '1') return;
          diagBtn.addEventListener('click', async () => {
            diagBtn.disabled = true;
            const prev = diagBtn.textContent;
            diagBtn.textContent = 'Diagnostica…';
            try {
              const d = await this.apiFetch('compliance/templates.php?action=diag_storage', { method: 'GET' });
              const data = d?.data || {};
              const missing = Array.isArray(data.missing) ? data.missing : [];
              this.showImsTemplateCatalogWarningHtml(`
                <div style="font-weight:700; margin-bottom:6px;">Diagnostica storage template</div>
                <div class="planning-muted">DB: <code>${this.escapeHtml(String(data.db_name || ''))}</code> — Host: <code>${this.escapeHtml(String(data.db_host || ''))}</code></div>
                <div class="planning-muted" style="margin-top:6px;">Storage disponibile: <strong>${data.storage_available ? 'SI' : 'NO'}</strong></div>
                ${missing.length ? `<div class="planning-muted" style="margin-top:6px;">Mancano: ${this.escapeHtml(missing.join(', '))}</div>` : ''}
                <div class="planning-actions" style="margin-top:10px;">
                  <button type="button" class="btn btn-primary btn-sm" id="planningImsInitStorageBtn2">Inizializza storage (migrazione 45)</button>
                </div>
              `);
              // rebind init
              setTimeout(() => {
                const b2 = document.getElementById('planningImsInitStorageBtn2');
                if (!b2 || b2.dataset.cnxBound === '1') return;
                b2.addEventListener('click', async () => {
                  b2.disabled = true;
                  const p2 = b2.textContent;
                  b2.textContent = 'Inizializzazione…';
                  try {
                    await this.apiFetch('compliance/templates.php?action=init_storage', {
                      method: 'POST',
                      json: true,
                      body: JSON.stringify({ csrf_token: this.csrfToken })
                    });
                    this.toast('Migrazione 45 applicata', 'success');
                    await this.loadImsTemplateCatalog({ force: true });
                  } catch (err) {
                    this.toast(String(err?.message || err), 'error');
                  } finally {
                    b2.disabled = false;
                    b2.textContent = p2 || 'Inizializza storage (migrazione 45)';
                  }
                });
                b2.dataset.cnxBound = '1';
              }, 0);
            } catch (err) {
              this.toast(String(err?.message || err), 'error');
            } finally {
              diagBtn.disabled = false;
              diagBtn.textContent = prev || 'Diagnostica DB';
            }
          });
          diagBtn.dataset.cnxBound = '1';
        }, 0);
      } else {
        this.showImsTemplateCatalogWarning(msg);
      }
      if (tbody) tbody.innerHTML = `<tr><td colspan="10" class="planning-muted">—</td></tr>`;
      this.toast(msg, 'error');
    }
  }

  renderImsTemplateCatalog() {
    const tbody = document.getElementById('planningImsTemplateCatalogTbody');
    if (!tbody) return;
    const q = String(this.imsTemplateSearchQuery || '').trim().toLowerCase();
    const allRows = Array.isArray(this.imsTemplates) ? this.imsTemplates : [];
    const filtered = !q ? allRows : allRows.filter(t => {
      const hay = [
        t.template_key,
        t.title,
        t.doc_type,
        t.file_kind,
        t.folder_path,
      ].map(x => String(x || '').toLowerCase()).join(' ');
      return hay.includes(q);
    });
    const rows = filtered.slice().sort((a, b) => String(a?.template_key || '').localeCompare(String(b?.template_key || '')));

    const pageSize = Math.max(5, parseInt(String(this.imsTemplatePageSize || 25), 10) || 25);
    const total = rows.length;
    const pageCount = Math.max(1, Math.ceil(total / pageSize));
    let page = Math.max(1, parseInt(String(this.imsTemplatePage || 1), 10) || 1);
    if (page > pageCount) page = pageCount;
    this.imsTemplatePage = page;
    const startIdx = (page - 1) * pageSize;
    const pageRows = rows.slice(startIdx, startIdx + pageSize);

    const pageInfo = document.getElementById('planningImsTemplatePageInfo');
    if (pageInfo) pageInfo.textContent = total ? `${startIdx + 1}-${Math.min(total, startIdx + pageSize)} / ${total}` : '0 / 0';
    const prevBtn = document.getElementById('planningImsTemplatePrevPage');
    const nextBtn = document.getElementById('planningImsTemplateNextPage');
    if (prevBtn) prevBtn.disabled = page <= 1;
    if (nextBtn) nextBtn.disabled = page >= pageCount;

    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="10" class="planning-muted">Nessun template</td></tr>`;
      return;
    }

    tbody.innerHTML = pageRows.map(t => {
      const key = this.escapeHtml(String(t.template_key || ''));
      const title = this.escapeHtml(String(t.title || ''));
      const docType = this.escapeHtml(String(t.doc_type || ''));
      const fileKind = this.escapeHtml(String(t.file_kind || ''));
      const folder = this.escapeHtml(String(t.folder_path || ''));
      const common = !!t.is_common_hls;
      const active = !!t.is_active;
      const mode = String(t.content_mode || 'placeholder');
      const hasSource = mode === 'copy_source' && !!t.source_file_id;
      const sourceBadge = hasSource ? '<span class="badge badge-success">Modello</span>' : '<span class="planning-muted">Placeholder</span>';
      const hasSchema = !!(t.input_schema_json && String(t.input_schema_json).trim() !== '');
      const hasAi = !!(t.ai_hint && String(t.ai_hint).trim() !== '');
      const compileBadge = (hasSchema || hasAi)
        ? [
            hasSchema ? '<span class="badge badge-success">Schema</span>' : '',
            hasAi ? '<span class="badge badge-success">AI</span>' : ''
          ].filter(Boolean).join(' ')
        : '<span class="planning-muted">—</span>';
      return `
        <tr>
          <td><code>${key}</code></td>
          <td>${title}</td>
          <td>${docType}</td>
          <td>${fileKind}</td>
          <td>${sourceBadge}</td>
          <td>${compileBadge}</td>
          <td><code>${folder}</code></td>
          <td>${common ? '<span class="badge badge-success">HLS</span>' : '<span class="planning-muted">—</span>'}</td>
          <td>${active ? '<span class="badge badge-success">SI</span>' : '<span class="badge badge-warning">NO</span>'}</td>
          <td class="planning-actions">
            <button type="button" class="btn btn-secondary btn-sm" data-action="edit" data-key="${key}">Modifica</button>
            <button type="button" class="btn btn-secondary btn-sm" data-action="toggle" data-key="${key}" data-active="${active ? '1' : '0'}">${active ? 'Disattiva' : 'Attiva'}</button>
          </td>
        </tr>
      `;
    }).join('');

    // bind actions
    tbody.querySelectorAll('[data-action="edit"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const key = String(btn.getAttribute('data-key') || '').trim();
        const t = (this.imsTemplates || []).find(x => String(x.template_key) === key) || null;
        this.openImsTemplateEditModal(t);
      });
    });
    tbody.querySelectorAll('[data-action="toggle"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const key = String(btn.getAttribute('data-key') || '').trim();
        const isActive = String(btn.getAttribute('data-active') || '') === '1';
        this.toggleImsTemplateActive(key, !isActive);
      });
    });
  }

  openImsTemplateEditModal(tpl) {
    const isNew = !tpl;
    this.imsTemplateEditingKey = isNew ? null : String(tpl.template_key || '').trim();

    const titleEl = document.getElementById('planningImsTemplateEditTitle');
    if (titleEl) titleEl.textContent = isNew ? 'Nuovo template IMS' : `Modifica template IMS — ${this.imsTemplateEditingKey}`;

    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v ?? '') === null ? '' : String(v ?? ''); };
    setVal('planningImsTplKey', isNew ? '' : (tpl.template_key || ''));
    setVal('planningImsTplTitle', isNew ? '' : (tpl.title || ''));
    setVal('planningImsTplDocType', isNew ? 'procedure' : (tpl.doc_type || 'procedure'));
    setVal('planningImsTplFileKind', isNew ? 'docx' : (tpl.file_kind || 'docx'));
    setVal('planningImsTplFolderPath', isNew ? '/IMS/' : (tpl.folder_path || '/IMS/'));
    setVal('planningImsTplFilename', isNew ? '' : (tpl.filename_template || ''));
    setVal('planningImsTplTags', isNew ? '' : (Array.isArray(tpl.tags) ? tpl.tags.join(',') : ''));
    setVal('planningImsTplDocCodeTemplate', isNew ? '' : (tpl.doc_code_template || ''));
    const srcIdEl = document.getElementById('planningImsTplSourceFileId');
    if (srcIdEl) srcIdEl.value = tpl && tpl.source_file_id ? String(tpl.source_file_id) : '';
    const chkCopy = document.getElementById('planningImsTplUseCopySource');
    if (chkCopy) chkCopy.checked = (tpl && String(tpl.content_mode || '') === 'copy_source' && !!tpl.source_file_id) ? true : false;
    const resBox = document.getElementById('planningImsTplSourceSearchResults');
    if (resBox) resBox.innerHTML = '';
    this.updateImsSourceFileLabelFromState();
    const clauseEl = document.getElementById('planningImsTplClauseRefs');
    if (clauseEl) {
      const val = tpl?.clause_refs ?? null;
      clauseEl.value = val ? JSON.stringify(val, null, 2) : '';
    }
    const schemaEl = document.getElementById('planningImsTplInputSchema');
    if (schemaEl) {
      const s = tpl?.input_schema_json ?? null;
      if (!s) {
        schemaEl.value = '';
      } else if (typeof s === 'string') {
        schemaEl.value = s;
      } else {
        schemaEl.value = JSON.stringify(s, null, 2);
      }
    }
    const hintEl = document.getElementById('planningImsTplAiHint');
    if (hintEl) {
      hintEl.value = String(tpl?.ai_hint || '');
    }
    const chkCommon = document.getElementById('planningImsTplIsCommon');
    const chkActive = document.getElementById('planningImsTplIsActive');
    if (chkCommon) chkCommon.checked = !!tpl?.is_common_hls;
    if (chkActive) chkActive.checked = tpl ? !!tpl?.is_active : true;

    const keyEl = document.getElementById('planningImsTplKey');
    if (keyEl) {
      keyEl.readOnly = !isNew;
      keyEl.style.opacity = isNew ? '1' : '0.8';
    }

    const msg = document.getElementById('planningImsTplEditMsg');
    if (msg) { msg.style.display = 'none'; msg.textContent = ''; }

    this.openModal('planningImsTemplateEditModal');
  }

  async toggleImsTemplateActive(templateKey, isActive) {
    const key = String(templateKey || '').trim();
    if (!key) return;
    try {
      await this.apiFetch('compliance/templates.php?action=toggle_active', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, template_key: key, is_active: !!isActive })
      });
      this.toast('Aggiornato', 'success');
      await this.loadImsTemplateCatalog({ force: true });
    } catch (e) {
      const msg = (e?.message || '').trim() || 'Errore aggiornamento template';
      this.toast(msg, 'error');
    }
  }

  async saveImsTemplateFromModal() {
    const msg = document.getElementById('planningImsTplEditMsg');
    const showMsg = (t) => {
      if (!msg) return;
      msg.style.display = 'block';
      msg.textContent = String(t || '');
    };

    const getVal = (id) => (document.getElementById(id)?.value || '').trim();
    const keyRaw = getVal('planningImsTplKey');
    const key = keyRaw.toUpperCase().replace(/\s+/g, '_');
    const title = getVal('planningImsTplTitle');
    const docType = getVal('planningImsTplDocType');
    const fileKind = getVal('planningImsTplFileKind');
    const folderPath = getVal('planningImsTplFolderPath');
    const filenameTemplate = getVal('planningImsTplFilename');
    const docCodeTemplate = getVal('planningImsTplDocCodeTemplate');
    const tagsRaw = getVal('planningImsTplTags');
    const tags = tagsRaw ? tagsRaw.split(',').map(s => s.trim()).filter(Boolean) : [];
    const isCommon = !!document.getElementById('planningImsTplIsCommon')?.checked;
    const isActive = !!document.getElementById('planningImsTplIsActive')?.checked;
    const useCopy = !!document.getElementById('planningImsTplUseCopySource')?.checked;
    const sourceFileId = parseInt(String(document.getElementById('planningImsTplSourceFileId')?.value || '0'), 10) || 0;
    const contentMode = (useCopy && sourceFileId > 0) ? 'copy_source' : 'placeholder';
    const clauseRaw = (document.getElementById('planningImsTplClauseRefs')?.value || '').trim();
    const schemaRaw = (document.getElementById('planningImsTplInputSchema')?.value || '').trim();
    const aiHint = (document.getElementById('planningImsTplAiHint')?.value || '').trim();

    if (!key) return showMsg('template_key richiesto');
    if (!title) return showMsg('Titolo richiesto');
    if (!folderPath || !folderPath.startsWith('/IMS')) return showMsg('folder_path deve iniziare con /IMS');
    if (!filenameTemplate) return showMsg('filename_template richiesto');

    let clauseRefs = null;
    if (clauseRaw) {
      try {
        clauseRefs = JSON.parse(clauseRaw);
      } catch (e) {
        return showMsg('clause_refs_json non è JSON valido');
      }
      if (!clauseRefs || typeof clauseRefs !== 'object' || Array.isArray(clauseRefs)) {
        return showMsg('clause_refs_json deve essere un oggetto {"ISO9001":["7.5"]}');
      }
    }

    let inputSchemaJson = null;
    if (schemaRaw) {
      try {
        const parsed = JSON.parse(schemaRaw);
        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
          return showMsg('Schema compilazione: JSON deve essere un oggetto');
        }
        inputSchemaJson = JSON.stringify(parsed);
      } catch (e) {
        return showMsg('Schema compilazione: JSON non valido');
      }
    }

    const btn = document.getElementById('planningImsTemplateEditSaveBtn');
    const prev = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = 'Salvataggio…'; }
    showMsg('');

    try {
      await this.apiFetch('compliance/templates.php?action=upsert', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          template_key: key,
          title,
          doc_type: docType,
          file_kind: fileKind,
          folder_path: folderPath,
          filename_template: filenameTemplate,
          doc_code_template: docCodeTemplate || null,
          content_mode: contentMode,
          source_tenant_id: 28,
          source_file_id: sourceFileId > 0 ? sourceFileId : null,
          tags,
          clause_refs: clauseRefs,
          input_schema_json: inputSchemaJson,
          ai_hint: aiHint || null,
          is_common_hls: isCommon,
          is_active: isActive
        })
      });
      this.toast('Template salvato', 'success');
      this.closeModal('planningImsTemplateEditModal');
      await this.loadImsTemplateCatalog({ force: true });
    } catch (e) {
      const m = (e?.message || '').trim() || 'Errore salvataggio template';
      showMsg(m);
      this.toast(m, 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = prev || 'Salva'; }
    }
  }

  // ------------------------------
  // Source file (master) selection (tenant 28)
  // ------------------------------
  updateImsSourceFileLabelFromState() {
    const label = document.getElementById('planningImsTplSourceFileLabel');
    const openBtn = document.getElementById('planningImsTplOpenSourceBtn');
    const idEl = document.getElementById('planningImsTplSourceFileId');
    const id = idEl ? String(idEl.value || '').trim() : '';
    if (!id) {
      if (label) label.textContent = 'Nessun modello selezionato';
      if (openBtn) openBtn.style.display = 'none';
      return;
    }
    if (label) label.innerHTML = `Selezionato file_id: <code>${this.escapeHtml(id)}</code>`;
    if (openBtn) openBtn.style.display = 'inline-flex';
  }

  async ensureImsMasterFolder() {
    try {
      await this.apiFetch('compliance/source_files.php?action=ensure_master_folder', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken })
      });
      this.toast('Cartella /Templates/IMS pronta', 'success');
    } catch (e) {
      this.toast(String(e?.message || e), 'error');
    }
  }

  clearImsSourceFileSelection() {
    const idEl = document.getElementById('planningImsTplSourceFileId');
    if (idEl) idEl.value = '';
    const chkCopy = document.getElementById('planningImsTplUseCopySource');
    if (chkCopy) chkCopy.checked = false;
    const resBox = document.getElementById('planningImsTplSourceSearchResults');
    if (resBox) resBox.innerHTML = '';
    this.updateImsSourceFileLabelFromState();
  }

  openSelectedImsSourceFile() {
    const idEl = document.getElementById('planningImsTplSourceFileId');
    const id = idEl ? String(idEl.value || '').trim() : '';
    if (!id) return;
    window.open(`files.php?open_file_id=${encodeURIComponent(id)}&open_mode=edit`, '_blank', 'noopener');
  }

  async searchImsSourceFiles() {
    const q = String(document.getElementById('planningImsTplSourceSearch')?.value || '').trim();
    const fileKind = String(document.getElementById('planningImsTplFileKind')?.value || '').trim();
    const out = document.getElementById('planningImsTplSourceSearchResults');
    if (out) out.innerHTML = `<div class="planning-muted">Caricamento...</div>`;
    try {
      const res = await this.apiFetch(`compliance/source_files.php?action=search&q=${encodeURIComponent(q)}&file_kind=${encodeURIComponent(fileKind)}`, { method: 'GET' });
      const data = res?.data || {};
      const files = Array.isArray(data.files) ? data.files : [];
      if (!data.master_folder_available) {
        if (out) out.innerHTML = `<div class="planning-muted">${this.escapeHtml(String(data.hint || 'Cartella master non disponibile'))}</div>`;
        return;
      }
      if (!files.length) {
        if (out) out.innerHTML = `<div class="planning-muted">Nessun file trovato in <code>/Templates/IMS</code></div>`;
        return;
      }
      if (out) {
        out.innerHTML = `
          <div class="planning-muted" style="margin-bottom:6px;">Risultati:</div>
          <div style="overflow-x:auto;">
            <table class="planning-table">
              <thead><tr><th>ID</th><th>Nome</th><th>Azione</th></tr></thead>
              <tbody>
                ${files.map(f => {
                  const id = this.escapeHtml(String(f.id || ''));
                  const name = this.escapeHtml(String(f.name || ''));
                  return `<tr>
                    <td><code>${id}</code></td>
                    <td>${name}</td>
                    <td class="planning-actions">
                      <button type="button" class="btn btn-secondary btn-sm" data-action="select_source" data-id="${id}">Seleziona</button>
                    </td>
                  </tr>`;
                }).join('')}
              </tbody>
            </table>
          </div>
        `;
        out.querySelectorAll('[data-action="select_source"]').forEach(btn => {
          btn.addEventListener('click', () => {
            const id = String(btn.getAttribute('data-id') || '').trim();
            const idEl = document.getElementById('planningImsTplSourceFileId');
            if (idEl) idEl.value = id;
            const chkCopy = document.getElementById('planningImsTplUseCopySource');
            if (chkCopy) chkCopy.checked = true;
            this.updateImsSourceFileLabelFromState();
          });
        });
      }
    } catch (e) {
      if (out) out.innerHTML = `<div class="planning-muted">${this.escapeHtml(String(e?.message || e))}</div>`;
      this.toast(String(e?.message || e), 'error');
    }
  }

  // ------------------------------
  // Modules (tenant 28)
  // ------------------------------
  openImsModulesModal() {
    this.openModal('planningImsModulesModal');
    this.loadImsModules({ force: false });
  }

  showImsModulesWarning(text) {
    const box = document.getElementById('planningImsModulesWarning');
    if (!box) return;
    if (!text) { box.style.display = 'none'; box.textContent = ''; return; }
    box.style.display = 'block';
    box.textContent = String(text);
  }

  async loadImsModules({ force = false } = {}) {
    const activeOnly = !!document.getElementById('planningImsModulesActiveOnly')?.checked;
    if (!force && this.imsModulesLoadedAt && (Date.now() - this.imsModulesLoadedAt) < 15_000) {
      return this.renderImsModules();
    }
    const tbody = document.getElementById('planningImsModulesTbody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="5" class="planning-muted">Caricamento...</td></tr>`;
    this.showImsModulesWarning('');
    try {
      const res = await this.apiFetch(`compliance/template_modules.php?action=list&active_only=${activeOnly ? '1' : '0'}`, { method: 'GET' });
      this.imsModules = Array.isArray(res?.data?.modules) ? res.data.modules : [];
      this.imsModulesLoadedAt = Date.now();
      this.renderImsModules();
    } catch (e) {
      const msg = String(e?.message || e);
      this.showImsModulesWarning(msg);
      if (tbody) tbody.innerHTML = `<tr><td colspan="5" class="planning-muted">—</td></tr>`;
    }
  }

  renderImsModules() {
    const tbody = document.getElementById('planningImsModulesTbody');
    if (!tbody) return;
    const rows = Array.isArray(this.imsModules) ? this.imsModules : [];
    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="5" class="planning-muted">Nessun modulo</td></tr>`;
      return;
    }
    tbody.innerHTML = rows.map(m => {
      const key = this.escapeHtml(String(m.module_key || ''));
      const title = this.escapeHtml(String(m.title || ''));
      const std = Array.isArray(m.standards) ? m.standards.join(', ') : '';
      const active = !!m.is_active;
      return `<tr>
        <td><code>${key}</code></td>
        <td>${title}</td>
        <td class="planning-muted">${this.escapeHtml(std)}</td>
        <td>${active ? '<span class="badge badge-success">SI</span>' : '<span class="badge badge-warning">NO</span>'}</td>
        <td class="planning-actions">
          <button type="button" class="btn btn-secondary btn-sm" data-action="edit_module" data-key="${key}">Modifica</button>
        </td>
      </tr>`;
    }).join('');
    tbody.querySelectorAll('[data-action="edit_module"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const key = String(btn.getAttribute('data-key') || '').trim();
        const m = (this.imsModules || []).find(x => String(x.module_key) === key) || null;
        this.openImsModuleEditModal(m);
      });
    });
  }

  async openImsModuleEditModal(mod) {
    const isNew = !mod;
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v ?? '') === null ? '' : String(v ?? ''); };
    const titleEl = document.getElementById('planningImsModuleEditTitle');
    const keyEl = document.getElementById('planningImsModuleKey');
    setVal('planningImsModuleEditingKey', isNew ? '' : (mod.module_key || ''));
    setVal('planningImsModuleKey', isNew ? '' : (mod.module_key || ''));
    setVal('planningImsModuleTitle', isNew ? '' : (mod.title || ''));
    setVal('planningImsModuleDesc', isNew ? '' : (mod.description || ''));
    setVal('planningImsModuleStandards', isNew ? '' : (Array.isArray(mod.standards) ? mod.standards.join(',') : ''));
    setVal('planningImsModuleTags', isNew ? '' : (Array.isArray(mod.tags) ? mod.tags.join(',') : ''));
    const chk = document.getElementById('planningImsModuleIsActive');
    if (chk) chk.checked = isNew ? true : !!mod.is_active;
    if (keyEl) { keyEl.readOnly = !isNew; keyEl.style.opacity = isNew ? '1' : '0.8'; }
    if (titleEl) titleEl.textContent = isNew ? 'Nuovo modulo IMS' : `Modifica modulo IMS — ${String(mod.module_key || '')}`;
    this.imsModuleItems = [];
    const tbody = document.getElementById('planningImsModuleItemsTbody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="5" class="planning-muted">Caricamento...</td></tr>`;
    const out = document.getElementById('planningImsModuleTplSearchResults');
    if (out) out.innerHTML = '';
    this.openModal('planningImsModuleEditModal');

    if (!isNew) {
      try {
        const res = await this.apiFetch(`compliance/template_modules.php?action=items_list&module_key=${encodeURIComponent(String(mod.module_key || ''))}`, { method: 'GET' });
        this.imsModuleItems = Array.isArray(res?.data?.items) ? res.data.items : [];
      } catch (_) {
        this.imsModuleItems = [];
      }
    }
    this.renderImsModuleItemsTable();
  }

  renderImsModuleItemsTable() {
    const tbody = document.getElementById('planningImsModuleItemsTbody');
    if (!tbody) return;
    const items = Array.isArray(this.imsModuleItems) ? this.imsModuleItems : [];
    if (!items.length) {
      tbody.innerHTML = `<tr><td colspan="5" class="planning-muted">Nessun template nel modulo</td></tr>`;
      return;
    }
    tbody.innerHTML = items.map((it, idx) => {
      const key = this.escapeHtml(String(it.template_key || ''));
      const title = this.escapeHtml(String(it.template?.title || ''));
      const req = !!it.is_required;
      const order = Number.isFinite(Number(it.sort_order)) ? Number(it.sort_order) : (idx * 10);
      return `<tr>
        <td><code>${key}</code></td>
        <td>${title}</td>
        <td><input type="checkbox" data-action="req" data-key="${key}" ${req ? 'checked' : ''}></td>
        <td><input type="number" class="form-control" style="max-width:120px;" data-action="order" data-key="${key}" value="${this.escapeHtml(String(order))}"></td>
        <td class="planning-actions">
          <button type="button" class="btn btn-secondary btn-sm" data-action="remove" data-key="${key}">Rimuovi</button>
        </td>
      </tr>`;
    }).join('');
    tbody.querySelectorAll('[data-action="remove"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const k = String(btn.getAttribute('data-key') || '').trim();
        this.imsModuleItems = (this.imsModuleItems || []).filter(x => String(x.template_key) !== k);
        this.renderImsModuleItemsTable();
      });
    });
    tbody.querySelectorAll('input[data-action="req"]').forEach(chk => {
      chk.addEventListener('change', () => {
        const k = String(chk.getAttribute('data-key') || '').trim();
        const it = (this.imsModuleItems || []).find(x => String(x.template_key) === k);
        if (it) it.is_required = !!chk.checked;
      });
    });
    tbody.querySelectorAll('input[data-action="order"]').forEach(inp => {
      inp.addEventListener('change', () => {
        const k = String(inp.getAttribute('data-key') || '').trim();
        const it = (this.imsModuleItems || []).find(x => String(x.template_key) === k);
        if (it) it.sort_order = parseInt(String(inp.value || '0'), 10) || 0;
      });
    });
  }

  searchTemplatesForModule() {
    const q = String(document.getElementById('planningImsModuleTplSearch')?.value || '').trim().toLowerCase();
    const out = document.getElementById('planningImsModuleTplSearchResults');
    const rows = Array.isArray(this.imsTemplates) ? this.imsTemplates : [];
    const filtered = q ? rows.filter(t => String(t.template_key || '').toLowerCase().includes(q) || String(t.title || '').toLowerCase().includes(q)).slice(0, 25) : rows.slice(0, 25);
    if (!out) return;
    if (!filtered.length) { out.innerHTML = `<div class="planning-muted">Nessun template trovato</div>`; return; }
    out.innerHTML = `
      <div class="planning-muted" style="margin-bottom:6px;">Seleziona un template da aggiungere:</div>
      <div style="overflow-x:auto;">
        <table class="planning-table">
          <thead><tr><th>Key</th><th>Titolo</th><th>Azione</th></tr></thead>
          <tbody>
            ${filtered.map(t => {
              const k = this.escapeHtml(String(t.template_key || ''));
              const title = this.escapeHtml(String(t.title || ''));
              return `<tr>
                <td><code>${k}</code></td>
                <td>${title}</td>
                <td class="planning-actions"><button type="button" class="btn btn-secondary btn-sm" data-action="add_tpl" data-key="${k}">Aggiungi</button></td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;
    out.querySelectorAll('[data-action="add_tpl"]').forEach(btn => {
      btn.addEventListener('click', () => {
        const k = String(btn.getAttribute('data-key') || '').trim();
        const exists = (this.imsModuleItems || []).some(x => String(x.template_key) === k);
        if (exists) return this.toast('Template già presente nel modulo', 'info');
        const tpl = (this.imsTemplates || []).find(x => String(x.template_key) === k) || null;
        this.imsModuleItems = [...(this.imsModuleItems || []), { template_key: k, is_required: true, sort_order: (this.imsModuleItems || []).length * 10, template: tpl }];
        this.renderImsModuleItemsTable();
      });
    });
  }

  async saveImsModuleFromModal() {
    const key = String(document.getElementById('planningImsModuleKey')?.value || '').trim().toUpperCase();
    const title = String(document.getElementById('planningImsModuleTitle')?.value || '').trim();
    const desc = String(document.getElementById('planningImsModuleDesc')?.value || '').trim();
    const standards = String(document.getElementById('planningImsModuleStandards')?.value || '').split(',').map(s => s.trim()).filter(Boolean);
    const tags = String(document.getElementById('planningImsModuleTags')?.value || '').split(',').map(s => s.trim()).filter(Boolean);
    const isActive = !!document.getElementById('planningImsModuleIsActive')?.checked;
    const msg = document.getElementById('planningImsModuleEditMsg');
    if (msg) { msg.style.display = 'none'; msg.textContent = ''; }
    try {
      await this.apiFetch('compliance/template_modules.php?action=upsert', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, module_key: key, title, description: desc, standards, tags, is_active: isActive })
      });
      const items = (this.imsModuleItems || []).map((it, idx) => ({
        template_key: String(it.template_key || '').trim().toUpperCase(),
        is_required: !!it.is_required,
        sort_order: Number.isFinite(Number(it.sort_order)) ? Number(it.sort_order) : (idx * 10)
      }));
      await this.apiFetch('compliance/template_modules.php?action=items_set', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, module_key: key, items })
      });
      this.toast('Modulo salvato', 'success');
      this.closeModal('planningImsModuleEditModal');
      await this.loadImsModules({ force: true });
    } catch (e) {
      const m = String(e?.message || e);
      if (msg) { msg.style.display = 'block'; msg.textContent = m; }
      this.toast(m, 'error');
    }
  }

  normalizeStandardCodeForModules(raw) {
    const s = String(raw || '').toUpperCase();
    if (!s) return '';
    if (s.includes('9001')) return 'ISO9001';
    if (s.includes('14001')) return 'ISO14001';
    if (s.includes('45001')) return 'ISO45001';
    if (s.includes('27001')) return 'ISO27001';
    if (s.includes('13485')) return 'ISO13485';
    if (s.includes('42001')) return 'ISO42001';
    if (s.includes('7101')) return 'ISO7101';
    if (s.includes('10881')) return 'UNI10881';
    return s.replace(/[^A-Z0-9]/g, '');
  }

  async loadImsModulesForProvisioning() {
    const sel = document.getElementById('planningProvisionModulesSelect');
    if (!sel) return;
    sel.innerHTML = '';
    try {
      const res = await this.apiFetch('compliance/template_modules.php?action=list&active_only=1', { method: 'GET' });
      const mods = Array.isArray(res?.data?.modules) ? res.data.modules : [];
      this.imsModules = mods;
      this.imsModulesLoadedAt = Date.now();
      const stdLabels = this.getProvisioningStandardsForUi().map(s => this.normalizeStandardCodeForModules(s)).filter(Boolean);
      const hasIso9001 = stdLabels.includes('ISO9001');
      const hasSgqCompleto = mods.some(m => String(m.module_key || '').toUpperCase() === 'ISO9001_SGQ_COMPLETO');
      const preferSgqCompleto = hasIso9001 && hasSgqCompleto;

      // If ISO9001 is selected but the full pack module is missing, auto-install it once (tenant 28 only).
      // This makes "Sistema documentale completo" reliable without extra clicks.
      if (hasIso9001 && !hasSgqCompleto && !this._autoInstalledIso9001PackOnce) {
        this._autoInstalledIso9001PackOnce = true;
        try {
          await this.installIso9001TemplatePack();
        } catch (_) {
          // non-blocking
        }
        // Reload modules after install attempt
        return await this.loadImsModulesForProvisioning();
      }

      mods.forEach(m => {
        const opt = document.createElement('option');
        opt.value = String(m.module_key || '');
        opt.textContent = `${String(m.module_key || '')} — ${String(m.title || '')}`;
        const mStd = Array.isArray(m.standards) ? m.standards : [];
        const compatible = !mStd.length || mStd.some(x => stdLabels.includes(String(x)));
        if (preferSgqCompleto) {
          opt.selected = (String(m.module_key || '').toUpperCase() === 'ISO9001_SGQ_COMPLETO');
        } else if (compatible) {
          opt.selected = true;
        }
        sel.appendChild(opt);
      });
    } catch (_) {
      // Non-blocking
    }
  }

  async runComplianceProvisioningFromModal() {
    if (!this.activePlanId) return this.toast('Seleziona un piano', 'error');

    // Ask for company profile at least once before provisioning (unless user explicitly chose to proceed anyway)
    if (!this._bypassCompanyProfilePromptOnce) {
      const ok = this.ensureCompanyProfileBeforeProvisioning();
      if (!ok) return;
    }
    this._bypassCompanyProfilePromptOnce = false;

    const createDocuments = !!document.getElementById('planningProvisionCreateDocuments')?.checked;
    const createTasks = !!document.getElementById('planningProvisionCreateTasks')?.checked;
    const createMilestones = !!document.getElementById('planningProvisionCreateMilestones')?.checked;

    const btn = document.getElementById('planningComplianceProvisionRunBtn');
    const prevLabel = btn?.textContent;
    if (btn) { btn.disabled = true; btn.textContent = 'Esecuzione…'; }

    const box = document.getElementById('planningProvisionResult');
    const showBox = (html) => {
      if (!box) return;
      box.style.display = 'block';
      box.innerHTML = html;
    };

    try {
      this.startProgress('Provisioning IMS…');

      const planIdNum = parseInt(this.activePlanId, 10);
      const moduleKeys = (() => {
        const sel = document.getElementById('planningProvisionModulesSelect');
        if (!sel) return [];
        const out = [];
        Array.from(sel.options || []).forEach(o => { if (o && o.selected) out.push(String(o.value || '').trim()); });
        return out.filter(Boolean);
      })();
      const standards = this.getProvisioningStandardsForUi();
      const reqPayload = {
        csrf_token: this.csrfToken,
        plan_id: planIdNum,
        // Legacy/optional: keep for backward compatibility when available
        blueprint_json: this.blueprintResult || null,
        standards,
        module_keys: moduleKeys,
        company_profile: (this.pendingCompanyProfile && this.isCompanyProfileMeaningful(this.pendingCompanyProfile)) ? this.pendingCompanyProfile : null,
        options: {
          create_documents: createDocuments,
          create_tasks: createTasks,
          create_milestones: createMilestones
        }
      };

      let prov = null;
      try {
        prov = await this.apiFetch('consulting_plans/compliance_provision.php', {
          method: 'POST',
          json: true,
          body: JSON.stringify(reqPayload)
        });
      } catch (e) {
        // If wrapper endpoint is missing in production (partial deploy), try fallback APIs (Leva2/3).
        const is404 = (e && (e.status === 404)) || false;
        const nonJson = !!(e && e.data == null && e.rawPreview);
        const raw = String(e?.rawPreview || '').toLowerCase();
        const looksLikeHtml = nonJson && (raw.includes('<html') || raw.includes('<!doctype') || raw.includes('<head') || raw.includes('<body'));
        // Only fallback when the endpoint itself is missing (404 HTML / non-JSON).
        if (is404 && (looksLikeHtml || nonJson)) {
          console.warn('[Compliance] wrapper endpoint 404, attempting fallback to /api/compliance/*', e);
          // Fallback: provision folders/docs via /api/compliance/provision.php, then tasks/events via /api/compliance/tasks_sync.php
          try {
            const prov2 = await this.apiFetch('compliance/provision.php', {
              method: 'POST',
              json: true,
              body: JSON.stringify({
                csrf_token: this.csrfToken,
                consulting_plan_id: planIdNum,
                blueprint_json: this.blueprintResult || null,
                module_keys: (Array.isArray(reqPayload.module_keys) ? reqPayload.module_keys : []),
                options: {
                  create_documents: createDocuments,
                  create_tasks: createTasks,
                  create_calendar_events: createMilestones
                }
              })
            });

            // tasks_sync is optional but keeps the UI counts aligned (and supports create_calendar_events)
            let tasks2 = null;
            if (createTasks || createMilestones) {
              tasks2 = await this.apiFetch('compliance/tasks_sync.php', {
                method: 'POST',
                json: true,
                body: JSON.stringify({
                  csrf_token: this.csrfToken,
                  consulting_plan_id: planIdNum,
                  options: {
                    create_tasks: createTasks,
                    create_calendar_events: createMilestones
                  }
                })
              });
            }

            // Normalize to the wrapper response shape used below
            const p = prov2?.data || {};
            const t = tasks2?.data || {};
            prov = {
              data: {
                storage_available: !!p.storage_available,
                data: {
                  tasks_skipped_reason: (t.tasks_skipped_reason ?? null),
                  created: {
                    folders: (p.created?.folders ?? 0),
                    documents: (p.created?.documents ?? 0),
                    tasks: (t.created?.tasks ?? (p.created?.tasks ?? 0)),
                    events: (t.created?.events ?? 0),
                  },
                  reused: {
                    folders: (p.reused?.folders ?? 0),
                    documents: (p.reused?.documents ?? 0),
                    tasks: (t.reused?.tasks ?? (p.reused?.tasks ?? 0)),
                    events: (t.reused?.events ?? 0),
                  },
                  warnings: [
                    ...(Array.isArray(p.warnings) ? p.warnings : []),
                    ...(Array.isArray(t.warnings) ? t.warnings : []),
                  ],
                }
              }
            };
          } catch (fallbackErr) {
            // Give a clear deploy diagnosis when wrapper is missing and fallback fails too.
            const hint = looksLikeHtml || nonJson
              ? 'Endpoint provisioning non trovato in produzione: deploy incompleto (manca api/consulting_plans/compliance_provision.php).'
              : 'Provisioning non disponibile (endpoint mancante o non raggiungibile).';
            throw new Error(`${hint} Dettaglio: ${String(fallbackErr?.message || fallbackErr)}`);
          }
        }
        // 404 with JSON (e.g. "Piano non trovato") is a real application error: surface it.
        throw e;
      }

      this.finishProgress();

      const payload = prov?.data?.data || {};
      const created = payload?.created || {};
      const reused = payload?.reused || {};
      const warnings = Array.isArray(payload?.warnings) ? payload.warnings : [];
      const tasksSkippedReason = String(payload?.tasks_skipped_reason || '').trim();
      const storageAvailable = !!prov?.data?.storage_available;

      showBox(`
        <div style="font-weight:700; margin-bottom:6px;">Provisioning completato</div>
        <div class="planning-muted">Cartelle: +${this.escapeHtml(String(created.folders ?? 0))} (esistenti: ${this.escapeHtml(String(reused.folders ?? 0))})</div>
        <div class="planning-muted">Documenti: +${this.escapeHtml(String(created.documents ?? 0))} (esistenti: ${this.escapeHtml(String(reused.documents ?? 0))})</div>
        <div class="planning-muted" style="margin-top:6px;">Task: +${this.escapeHtml(String(created.tasks ?? 0))} (esistenti: ${this.escapeHtml(String(reused.tasks ?? 0))})</div>
        ${tasksSkippedReason ? `<div class="planning-muted" style="margin-top:6px;"><strong>Task</strong>: non creati (${this.escapeHtml(tasksSkippedReason)})</div>` : ``}
        <div class="planning-muted">Milestone: +${this.escapeHtml(String(created.events ?? 0))} (esistenti: ${this.escapeHtml(String(reused.events ?? 0))})</div>
        ${storageAvailable ? '' : `<div style="margin-top:10px;" class="planning-muted"><strong>Nota</strong>: storage mapping non disponibile (migrazione 44 non applicata) — dedup best-effort.</div>`}
        ${warnings.length ? `<div style="margin-top:10px;"><div style="font-weight:700;">Warning</div><div class="planning-muted">${this.escapeHtml(warnings.slice(0, 6).join(' | '))}</div></div>` : ''}
        <div style="margin-top:10px;" class="planning-actions">
          <a class="btn btn-secondary btn-sm" href="files.php" target="_blank" rel="noopener">Apri File Manager</a>
          <a class="btn btn-secondary btn-sm" href="compliance.php${payload?.program_id ? `?program_id=${encodeURIComponent(String(payload.program_id))}` : ''}" target="_blank" rel="noopener">Apri compilazione (Sistema documentale)</a>
        </div>
      `);

      this.toast('Provisioning completato', 'success');
      // Also show a compact summary in the Compliance area (so we can auto-close the modal without losing info)
      try {
        const clientTenantId = payload?.client_tenant_id || prov?.data?.data?.client_tenant_id || 0;
        this.lastProvisioningSummary = { created, reused, warnings, storageAvailable, clientTenantId };
        this.renderLastProvisioningSummary?.();
      } catch (_) {}

      // UX: auto-close after success (requested)
      setTimeout(() => {
        try { this.closeModal('planningComplianceProvisionModal'); } catch (_) {}
      }, 650);
    } catch (e) {
      console.error('[Compliance] provision error:', e);
      this.finishProgress();
      showBox(`<div style="font-weight:700; margin-bottom:6px;">Errore</div><div class="planning-muted">${this.escapeHtml(String(e?.message || e))}</div>`);
      this.toast('Provisioning fallito', 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = prevLabel || 'Esegui provisioning'; }
    }
  }

  // ------------------------------
  // Guided wizard
  // ------------------------------
  openGuidedWizard() {
    this.wizardStep = 1;
    this.wizardCreatedPlanId = null;
    this.wizardProposal = null;
    this.wizardProposalItems = [];
    this.wizardClientActivityTypes = [];
    this.wizardClientActivityTypeById = new Map();
    this.wizardClientActivityTypeIdByName = new Map();
    this.wizardConsultantEffortDays = {};
    this.wizardConsultantEffortDirty = {};

    // Populate client select
    const clientSel = document.getElementById('planningWizardClient');
    if (clientSel) {
      clientSel.innerHTML = `<option value="">Seleziona...</option>` + (this.clients || []).map(c => {
        const name = (c.denominazione || c.name || `Tenant ${c.id}`);
        return `<option value="${c.id}">${this.escapeHtml(name)} (#${c.id})</option>`;
      }).join('');
    }

    // Populate consultants select
    const consSel = document.getElementById('planningWizardConsultants');
    if (consSel) {
      consSel.innerHTML = (this.consultants || []).map(u => `<option value="${u.id}">${this.escapeHtml(u.name || u.email || `#${u.id}`)}</option>`).join('');
      this.enableClickToggleMultiSelect(consSel);
    }

    // Reset fields
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    const objSel = document.getElementById('planningWizardObjectiveType');
    if (objSel) {
      objSel.innerHTML = `<option value="">Seleziona prima l’azienda...</option>`;
      objSel.value = '';
      objSel.disabled = true;
    }
    setVal('planningWizardDeadline', '');
    setVal('planningWizardNotes', '');
    setVal('planningWizardBusinessDescription', '');
    setVal('planningWizardDesiredScopeHint', '');
    setVal('planningWizardEmployeeCount', '');
    setVal('planningWizardSitesCount', '');
    const stdSel = document.getElementById('planningWizardStandards');
    if (stdSel) {
      [...stdSel.options].forEach(o => { o.selected = (o.value === 'ISO 9001'); });
      this.enableClickToggleMultiSelect(stdSel);
    }
    this.wizardResetObjectiveOverrides();
    const chk = document.getElementById('planningWizardGenerateSchedule');
    if (chk) chk.checked = false;
    const effortWrap = document.getElementById('planningWizardConsultantEffortWrap');
    if (effortWrap) effortWrap.innerHTML = '';
    const effortGroup = document.getElementById('planningWizardConsultantEffortGroup');
    if (effortGroup) effortGroup.style.display = 'none';
    setVal('planningWizardEffortTotalDays', '');

    // Reset proposal UI
    this.wizardResetProposalUI();

    this.renderWizard();
    this.openModal('planningGuidedWizardModal');
  }

  closeGuidedWizard() {
    this.closeModal('planningGuidedWizardModal');
  }

  // ------------------------------
  // Estimate wizard (Services/Norms -> Estimate days -> Generate items)
  // ------------------------------
  async openEstimateWizard() {
    this.estimateWizardStep = 1;
    this.estimateWizardEstimateJson = null;
    this.estimateWizardClientLocations = [];
    this.estimateWizardSelectedLocationKeys = [];
    this.estimateWizardLocationsLoadedForClient = 0;
    this.estimateWizardSessionId = 0;
    this.estimateWizardSessionsStorageAvailable = null;
    this.estimateWizardDraftLastSavedAt = '';
    this.estimateWizardDraftLastSource = '';
    this.estimateWizardDraftLastError = '';

    // Populate client select
    const clientSel = document.getElementById('planningEstimateWizardClient');
    if (clientSel) {
      clientSel.innerHTML = `<option value="">Seleziona...</option>` + (this.clients || []).map(c => {
        const name = (c.denominazione || c.name || `Tenant ${c.id}`);
        return `<option value="${c.id}">${this.escapeHtml(name)} (#${c.id})</option>`;
      }).join('');

      // Best-effort: preselect current filter (if any)
      const preferredClientId = parseInt(document.getElementById('planningClientFilter')?.value || '0', 10) || 0;
      if (preferredClientId) {
        clientSel.value = String(preferredClientId);
      }

      // Bind prefill once
      if (clientSel.dataset?.cnxPrefillBound !== '1') {
        clientSel.addEventListener('change', async () => {
          const id = parseInt(clientSel.value || '0', 10) || 0;
          this.estimateWizardSessionId = 0;
          this.estimateWizardSessionsStorageAvailable = null;
          this.estimateWizardDraftLastSavedAt = '';
          this.estimateWizardDraftLastSource = '';
          this.estimateWizardDraftLastError = '';
          this.estimateWizardEstimateJson = null;
          this.estimateWizardDocsLoadedForClient = 0;
          this.estimateWizardDocsSnapshot = null;
          this.estimateWizardDocProfileId = 0;
          this.estimateWizardDocProfile = null;
          try { this.estimateWizardUpdateDraftBanner(); } catch (_) {}
          try {
            const box = document.getElementById('planningEstimateWizardEstimateBox');
            if (box) { box.style.display = 'none'; box.innerHTML = ''; }
          } catch (_) {}
          this.estimateWizardPrefillCompanyProfileFromClient(id);
          try { await this.estimateWizardLoadClientLocations(id); } catch (_) {}
          try { await this.estimateWizardLoadClientDocsSnapshot(id); } catch (_) {}
          try { await this.estimateWizardTryRestoreDraft(id); } catch (_) {}
          try { this.estimateWizardUpdateDynamicAdvancedBlocks(); } catch (_) {}
          try { this.estimateWizardUpdateDraftBanner(); } catch (_) {}
        });
        clientSel.dataset.cnxPrefillBound = '1';
      }
    }

    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = String(v ?? ''); };
    setVal('planningEstimateWizardTitle', '');
    // Default schedule window is compact (30 days) when empty.
    setVal('planningEstimateWizardPeriodEnd', '');

    // Draft banner
    const draftBanner = document.getElementById('planningEstimateWizardDraftBanner');
    if (draftBanner) { draftBanner.style.display = 'none'; draftBanner.innerHTML = ''; }

    // Locations UI reset
    const locGroup = document.getElementById('planningEstimateWizardLocationsGroup');
    const locWrap = document.getElementById('planningEstimateWizardLocationsWrap');
    if (locWrap) locWrap.innerHTML = '';
    if (locGroup) locGroup.style.display = 'none';

    // Service filters
    const catSel = document.getElementById('planningEstimateWizardServiceCategory');
    const searchEl = document.getElementById('planningEstimateWizardServiceSearch');
    if (catSel) catSel.value = '';
    if (searchEl) searchEl.value = '';
    const showLegacyEl = document.getElementById('planningEstimateWizardShowLegacy');
    if (showLegacyEl) showLegacyEl.checked = false;

    const servicesSel = document.getElementById('planningEstimateWizardServices');
    if (servicesSel) {
      servicesSel.innerHTML = `<option value="" disabled>Caricamento...</option>`;
      this.enableClickToggleMultiSelect(servicesSel);
    }
    const svcWarn = document.getElementById('planningEstimateWizardServiceWarnings');
    if (svcWarn) { svcWarn.style.display = 'none'; svcWarn.innerHTML = ''; }

    // Company profile inputs
    setVal('planningEstimateWizardSector', '');
    setVal('planningEstimateWizardEmployeesRange', '');
    setVal('planningEstimateWizardSitesCount', '');
    setVal('planningEstimateWizardWebsite', '');
    setVal('planningEstimateWizardNotes', '');
    const regulatedEl = document.getElementById('planningEstimateWizardRegulated');
    if (regulatedEl) regulatedEl.checked = false;

    // Client docs intelligence (IMS/Knowledge) — reset per session
    this.estimateWizardDocsLoadedForClient = 0;
    this.estimateWizardDocsSnapshot = null;
    this.estimateWizardDocProfileId = 0;
    this.estimateWizardDocProfile = null;
    const docsStatus = document.getElementById('planningEstimateWizardDocsStatus');
    if (docsStatus) docsStatus.textContent = 'Seleziona un’azienda per vedere lo stato.';
    const docsWrap = document.getElementById('planningEstimateWizardDocsAnalysisWrap');
    if (docsWrap) { docsWrap.style.display = 'none'; docsWrap.innerHTML = ''; }
    const useDoc = document.getElementById('planningEstimateWizardUseDocEvidence');
    if (useDoc) useDoc.checked = true;

    // Advanced inputs (collapsed by default)
    setVal('planningEstimateWizardInterventionType', '');
    setVal('planningEstimateWizardQmsMaturity', '');
    setVal('planningEstimateWizardCoreProcessCount', '');
    setVal('planningEstimateWizardDepartmentsCount', '');
    setVal('planningEstimateWizardProductLinesCount', '');
    setVal('planningEstimateWizardCriticalSuppliersCount', '');
    setVal('planningEstimateWizardDesignApplicability', 'unknown');
    setVal('planningEstimateWizardOutsourcingLevel', '');
    setVal('planningEstimateWizardItMaturity', '');
    setVal('planningEstimateWizardPreferredDeliveryDeadline', '');
    setVal('planningEstimateWizardBlackoutPeriods', '');
    setVal('planningEstimateWizardOnSitePreferenceRatio', '');
    const langs = document.getElementById('planningEstimateWizardLanguagesNeeded');
    if (langs) [...langs.options].forEach(o => { o.selected = false; });
    const useAi = document.getElementById('planningEstimateWizardUseAiEnrichment');
    if (useAi) useAi.checked = false;
    const adv = document.getElementById('planningEstimateWizardAdvancedDetails');
    if (adv) adv.open = false;

    // ISO 14001 advanced block (dynamic)
    setVal('planningEstimateWizardIso14001AspectsComplexity', '');
    setVal('planningEstimateWizardIso14001PermitsPresence', 'unknown');
    setVal('planningEstimateWizardIso14001HazardousSubstances', 'unknown');
    const iso14001Wrap = document.getElementById('planningEstimateWizardIso14001Advanced');
    if (iso14001Wrap) iso14001Wrap.style.display = 'none';

    // Conditional advanced blocks (dynamic)
    setVal('planningEstimateWizardFoodHaccpStudiesCount', '');
    setVal('planningEstimateWizardFoodShiftsCount', '');
    setVal('planningEstimateWizardFoodHighRiskProducts', 'unknown');
    setVal('planningEstimateWizardFoodProductionAreaSizeClass', '');
    setVal('planningEstimateWizardFoodProductionAreaM2', '');
    setVal('planningEstimateWizardFoodLabsInHouse', '');
    const foodWrap = document.getElementById('planningEstimateWizardFoodAdvanced');
    if (foodWrap) foodWrap.style.display = 'none';

    const d17025 = document.getElementById('planningEstimateWizard17025Disciplines');
    if (d17025) [...d17025.options].forEach(o => { o.selected = false; });
    setVal('planningEstimateWizard17025MethodsCount', '');
    setVal('planningEstimateWizard17025SamplingInScope', 'unknown');
    setVal('planningEstimateWizard17025MultiSiteLab', '');
    const w17025 = document.getElementById('planningEstimateWizard17025Advanced');
    if (w17025) w17025.style.display = 'none';

    setVal('planningEstimateWizardCeRegulation', '');
    setVal('planningEstimateWizardCeRiskClass', '');
    setVal('planningEstimateWizardCeNotifiedBodyRequired', 'unknown');
    const ceWrap = document.getElementById('planningEstimateWizardCeAdvanced');
    if (ceWrap) ceWrap.style.display = 'none';

    setVal('planningEstimateWizardGdprProcessingActivitiesCount', '');
    setVal('planningEstimateWizardGdprMainItSystemsCount', '');
    setVal('planningEstimateWizardGdprSpecialCategoriesData', 'unknown');
    setVal('planningEstimateWizardGdprExtraEuTransfers', 'unknown');
    const gdprWrap = document.getElementById('planningEstimateWizardGdprAdvanced');
    if (gdprWrap) gdprWrap.style.display = 'none';

    setVal('planningEstimateWizard231MogExisting', 'unknown');
    setVal('planningEstimateWizard231RiskAreasCount', '');
    setVal('planningEstimateWizard231SubsidiariesCount', '');
    const w231 = document.getElementById('planningEstimateWizard231Advanced');
    if (w231) w231.style.display = 'none';

    setVal('planningEstimateWizardAccredFacilitiesUnitsCount', '');
    setVal('planningEstimateWizardAccredRegionalRequirementsComplexity', '');
    const wAccred = document.getElementById('planningEstimateWizardAccredAdvanced');
    if (wAccred) wAccred.style.display = 'none';

    // Auto-prefill from azienda (best-effort) AFTER reset, only if fields are still empty
    const selectedClientId = parseInt(clientSel?.value || '0', 10) || 0;
    if (selectedClientId) {
      this.estimateWizardPrefillCompanyProfileFromClient(selectedClientId);
      this.estimateWizardLoadClientLocations(selectedClientId);
      this.estimateWizardLoadClientDocsSnapshot(selectedClientId);
    }

    // Estimate UI boxes
    const box = document.getElementById('planningEstimateWizardEstimateBox');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
    const summary = document.getElementById('planningEstimateWizardSummary');
    if (summary) { summary.style.display = 'none'; summary.innerHTML = ''; }

    // Bind filters once
    if (catSel && catSel.dataset?.cnxBound !== '1') {
      catSel.addEventListener('change', () => this.estimateWizardRenderServicesOptions());
      catSel.dataset.cnxBound = '1';
    }
    if (searchEl && searchEl.dataset?.cnxBound !== '1') {
      searchEl.addEventListener('input', this.debounce(() => this.estimateWizardRenderServicesOptions(), 160));
      searchEl.dataset.cnxBound = '1';
    }
    const showLegacyFilterEl = document.getElementById('planningEstimateWizardShowLegacy');
    if (showLegacyFilterEl && showLegacyFilterEl.dataset?.cnxBound !== '1') {
      showLegacyFilterEl.addEventListener('change', () => {
        try { this.estimateWizardRenderServicesOptions(); } catch (_) {}
        try { this.estimateWizardUpdateServiceWarnings(); } catch (_) {}
      });
      showLegacyFilterEl.dataset.cnxBound = '1';
    }

    await this.estimateWizardLoadCatalog({ force: false });
    this.estimateWizardRenderServicesOptions();
    this.estimateWizardBindEstimateDraftAutosaveOnce();
    this.estimateWizardUpdateDynamicAdvancedBlocks();
    this.renderEstimateWizard();
    this.openModal('planningEstimateWizardModal');

    // Best-effort: restore a previous draft/session for selected client
    try {
      const currentClientId = parseInt(clientSel?.value || '0', 10) || 0;
      if (currentClientId) {
        await this.estimateWizardTryRestoreDraft(currentClientId);
      } else {
        this.estimateWizardUpdateDraftBanner();
      }
    } catch (_) {
      this.estimateWizardUpdateDraftBanner();
    }
  }

  // ------------------------------
  // Estimate wizard: client docs intelligence (IMS/Knowledge)
  // ------------------------------

  estimateWizardGetActiveClientId() {
    return parseInt(document.getElementById('planningEstimateWizardClient')?.value || '0', 10) || 0;
  }

  estimateWizardGetSelectedStandardCodesForDocs() {
    try {
      const ids = this.estimateWizardGetSelectedServiceIds();
      const byId = new Map((this.estimateWizardCatalog || []).map(b => [parseInt(b?.id, 10) || 0, b]));
      const out = [];
      for (const sid of ids) {
        const b = byId.get(parseInt(sid, 10) || 0);
        if (!b) continue;
        const code = String(b?.service_code || '').trim().toUpperCase();
        if (code) out.push(code);
      }
      const uniq = Array.from(new Set(out)).filter(Boolean).slice(0, 6);
      return uniq.length ? uniq : ['ISO9001'];
    } catch (_) {
      return ['ISO9001'];
    }
  }

  estimateWizardRenderClientDocsBox() {
    const statusEl = document.getElementById('planningEstimateWizardDocsStatus');
    const wrap = document.getElementById('planningEstimateWizardDocsAnalysisWrap');
    if (!statusEl || !wrap) return;

    const clientId = this.estimateWizardGetActiveClientId();
    if (!clientId) {
      statusEl.textContent = 'Seleziona un’azienda per vedere lo stato.';
      wrap.style.display = 'none';
      wrap.innerHTML = '';
      return;
    }

    const snap = (this.estimateWizardDocsLoadedForClient === clientId) ? this.estimateWizardDocsSnapshot : null;
    if (!snap) {
      statusEl.textContent = 'Caricamento…';
      wrap.style.display = (this.estimateWizardDocProfile ? 'block' : 'none');
    } else {
      const k = snap.knowledge || {};
      const li = String(k.last_indexed_at || '').trim();
      const files = parseInt(k.file_count_indexed || '0', 10) || 0;
      const stale = !!snap.stale;
      const ims = !!snap.ims_folder_present;
      const kn = !!snap.knowledge_folder_present;
      const parts = [];
      parts.push(ims ? 'IMS: sì' : 'IMS: no');
      parts.push(kn ? 'Knowledge: sì' : 'Knowledge: no');
      parts.push(li ? `Ultimo indice: ${li}` : 'Indice: mai eseguito');
      parts.push(`File indicizzati: ${files}`);
      if (stale && li) parts.push('Stato: NON aggiornato (stale)');
      else if (li) parts.push('Stato: aggiornato');
      statusEl.textContent = parts.join(' • ');
    }

    // Analysis summary (structured only)
    const prof = this.estimateWizardDocProfile;
    if (!prof || typeof prof !== 'object') {
      wrap.style.display = 'none';
      wrap.innerHTML = '';
      return;
    }
    const maturity = String(prof.maturity_suggested || '').trim();
    const conf = parseInt(prof.confidence || '0', 10) || 0;
    const notes = String(prof?.planning_adjustments?.notes || '').trim();
    const gaps = Array.isArray(prof.gaps) ? prof.gaps : [];
    const gapsTop = gaps.slice(0, 4).map(g => {
      const area = String(g?.area || '').trim();
      const sev = String(g?.severity || '').trim();
      const det = String(g?.detail || '').trim();
      const line = [area, sev].filter(Boolean).join(' / ');
      return (line ? (line + ': ') : '') + det;
    }).filter(Boolean);

    wrap.style.display = 'block';
    wrap.innerHTML = `
      <div style="font-weight:700; margin-bottom:6px;">Analisi (best-effort)</div>
      <div class="planning-muted">Maturità suggerita: <strong>${this.escapeHtml(maturity || '—')}</strong> • Confidenza: <strong>${this.escapeHtml(String(conf))}%</strong></div>
      ${notes ? `<div class="planning-muted" style="margin-top:6px;">${this.escapeHtml(notes)}</div>` : ``}
      ${gapsTop.length ? `<div style="margin-top:10px;"><div style="font-weight:700;">Gap (sintesi)</div><div class="planning-muted">${this.escapeHtml(gapsTop.join(' | '))}</div></div>` : ``}
    `;
  }

  async estimateWizardLoadClientDocsSnapshot(clientTenantId) {
    const clientId = parseInt(String(clientTenantId || '0'), 10) || 0;
    if (!clientId) return;
    this.estimateWizardDocsLoadedForClient = clientId;
    this.estimateWizardDocsSnapshot = null;
    this.estimateWizardRenderClientDocsBox();
    try {
      const res = await this.apiFetch(`consulting_plans/client_docs_snapshot.php?client_tenant_id=${encodeURIComponent(String(clientId))}&csrf_token=${encodeURIComponent(String(this.csrfToken || ''))}`, { method: 'GET', json: true });
      this.estimateWizardDocsSnapshot = res?.data || null;
      this.estimateWizardDocsLoadedForClient = clientId;
    } catch (e) {
      this.estimateWizardDocsSnapshot = null;
      this.estimateWizardDocsLoadedForClient = clientId;
    }
    this.estimateWizardRenderClientDocsBox();
  }

  async estimateWizardReindexClientDocs() {
    const clientId = this.estimateWizardGetActiveClientId();
    if (!clientId) return this.toast('Seleziona un’azienda cliente', 'error');
    try {
      this.startProgress('Reindicizzazione documenti…');
      const res = await this.apiFetch('consulting_plans/client_docs_reindex.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, client_tenant_id: clientId, force: true }),
      });
      const st = String(res?.data?.status || '').trim();
      if (st === 'fresh_skip') this.toast('Indicizzazione già aggiornata (≤10 min)', 'success');
      else if (st === 'locked') this.toast('Indicizzazione già in corso (lock attivo)', 'warning');
      else this.toast('Reindicizzazione avviata (best-effort)', 'success');
    } catch (e) {
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Reindicizzazione non disponibile';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.finishProgress();
      try { await this.estimateWizardLoadClientDocsSnapshot(clientId); } catch (_) {}
    }
  }

  async estimateWizardAnalyzeClientDocs() {
    const clientId = this.estimateWizardGetActiveClientId();
    if (!clientId) return this.toast('Seleziona un’azienda cliente', 'error');

    // Require intervention type (used as context for analysis)
    const it = (document.getElementById('planningEstimateWizardInterventionType')?.value || '').trim();
    if (!it) {
      try {
        const adv = document.getElementById('planningEstimateWizardAdvancedDetails');
        if (adv) adv.open = true;
      } catch (_) {}
      this.toast('Seleziona “Tipo intervento” (obbligatorio) prima di analizzare', 'error');
      return;
    }

    const std = this.estimateWizardGetSelectedStandardCodesForDocs();
    try {
      this.startProgress('Analisi documenti…');
      const res = await this.apiFetch('consulting_plans/client_docs_analyze.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_tenant_id: clientId,
          standard_codes: std,
          intervention_type: it,
        }),
      });
      const data = res?.data || {};
      this.estimateWizardDocProfileId = parseInt(String(data.doc_profile_id || '0'), 10) || 0;
      this.estimateWizardDocProfile = (data && typeof data.payload === 'object') ? data.payload : null;

      // Prefill QMS maturity (best-effort, do not override if already chosen by user)
      try {
        const useDoc = !!document.getElementById('planningEstimateWizardUseDocEvidence')?.checked;
        const q = document.getElementById('planningEstimateWizardQmsMaturity');
        const cur = q ? String(q.value || '').trim() : '';
        const sug = String(this.estimateWizardDocProfile?.maturity_suggested || '').trim();
        const map = {
          none: 'none',
          partial: 'partial_informal',
          structured_non_certified: 'structured_not_certified',
          already_certified: 'already_certified',
          integrated_existing: 'integrated_system_existing',
        };
        const v = map[sug] || '';
        if (useDoc && q && !cur && v) q.value = v;
      } catch (_) {}

      this.estimateWizardRenderClientDocsBox();
      this.toast('Analisi completata', 'success');
    } catch (e) {
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Analisi non disponibile';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.finishProgress();
    }
  }

  async estimateWizardLoadClientLocations(clientTenantId) {
    const tid = parseInt(String(clientTenantId || '0'), 10) || 0;
    const group = document.getElementById('planningEstimateWizardLocationsGroup');
    const wrap = document.getElementById('planningEstimateWizardLocationsWrap');
    if (!group || !wrap) return;

    if (!tid) {
      this.estimateWizardClientLocations = [];
      this.estimateWizardSelectedLocationKeys = [];
      this.estimateWizardLocationsLoadedForClient = 0;
      group.style.display = 'none';
      wrap.innerHTML = '';
      return;
    }

    if (this.estimateWizardLocationsLoadedForClient === tid && Array.isArray(this.estimateWizardClientLocations) && this.estimateWizardClientLocations.length) {
      group.style.display = 'block';
      this.estimateWizardRenderClientLocations();
      return;
    }

    group.style.display = 'block';
    wrap.innerHTML = `<div class="planning-muted">Caricamento sedi…</div>`;

    try {
      const res = await this.apiFetch(`consulting_plans/client_locations.php?client_tenant_id=${encodeURIComponent(String(tid))}`, { method: 'GET', json: true });
      const locs = Array.isArray(res?.data?.locations) ? res.data.locations : [];
      this.estimateWizardClientLocations = locs.map((l, idx) => {
        const id = parseInt(l?.id || '0', 10) || 0;
        const legacyKey = String(l?.legacy_key || '').trim();
        const key = id > 0 ? `id:${id}` : `legacy:${legacyKey || idx}`;
        return Object.assign({}, l || {}, { _key: key });
      });
      this.estimateWizardLocationsLoadedForClient = tid;

      // Default selection: primary sede_legale if present, else first item.
      if (!Array.isArray(this.estimateWizardSelectedLocationKeys) || !this.estimateWizardSelectedLocationKeys.length) {
        const primary = this.estimateWizardClientLocations.find(l => l && l.location_type === 'sede_legale' && (l.is_primary === true || l.is_primary === 1)) || null;
        const first = this.estimateWizardClientLocations[0] || null;
        const chosen = primary || first;
        this.estimateWizardSelectedLocationKeys = chosen && chosen._key ? [String(chosen._key)] : [];
      } else {
        // Keep only keys that still exist
        const allowed = new Set(this.estimateWizardClientLocations.map(l => String(l._key || '')));
        this.estimateWizardSelectedLocationKeys = this.estimateWizardSelectedLocationKeys.filter(k => allowed.has(String(k)));
        if (!this.estimateWizardSelectedLocationKeys.length && this.estimateWizardClientLocations.length) {
          this.estimateWizardSelectedLocationKeys = [String(this.estimateWizardClientLocations[0]._key || '')].filter(Boolean);
        }
      }

      this.estimateWizardRenderClientLocations();
    } catch (e) {
      console.warn('[EstimateWizard] load client locations failed:', e?.message || e);
      wrap.innerHTML = `<div class="planning-muted">Sedi non disponibili (verifica anagrafica azienda).</div>`;
    }
  }

  estimateWizardRenderClientLocations() {
    const group = document.getElementById('planningEstimateWizardLocationsGroup');
    const wrap = document.getElementById('planningEstimateWizardLocationsWrap');
    if (!group || !wrap) return;
    const locs = Array.isArray(this.estimateWizardClientLocations) ? this.estimateWizardClientLocations : [];
    if (!locs.length) {
      group.style.display = 'none';
      wrap.innerHTML = '';
      return;
    }

    group.style.display = 'block';
    const selected = new Set((this.estimateWizardSelectedLocationKeys || []).map(String));

    wrap.innerHTML = `
      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:8px;">
        <span class="planning-pill">Totale sedi: ${locs.length}</span>
        <span class="planning-pill">Selezionate: ${selected.size}</span>
      </div>
      <div style="display:flex; flex-direction:column; gap:8px;">
        ${locs.map(l => {
          const key = String(l._key || '');
          const checked = selected.has(key) ? 'checked' : '';
          const label = String(l.label || (l.location_type === 'sede_operativa' ? 'Sede operativa' : 'Sede legale'));
          const addr = String(l.indirizzo_completo || '').trim();
          const muniValid = (l.municipality_valid === false)
            ? `<div style="margin-top:4px; color:#9a3412; font-weight:600;">Attenzione: comune/provincia non validi (correggi in Aziende).</div>`
            : '';
          return `
            <label class="form-checkbox-label" style="display:flex; gap:10px; align-items:flex-start;">
              <input type="checkbox" class="form-checkbox" data-loc-key="${this.escapeAttr(key)}" ${checked} />
              <span>
                <div style="font-weight:700;">${this.escapeHtml(label)}</div>
                <div class="planning-muted">${this.escapeHtml(addr || '—')}</div>
                ${muniValid}
              </span>
            </label>
          `;
        }).join('')}
      </div>
    `;

    // Bind checkbox changes
    wrap.querySelectorAll('input[data-loc-key]').forEach(chk => {
      if (chk.dataset?.cnxBound === '1') return;
      chk.addEventListener('change', () => {
        const key = String(chk.getAttribute('data-loc-key') || '');
        const set = new Set((this.estimateWizardSelectedLocationKeys || []).map(String));
        if (chk.checked) set.add(key); else set.delete(key);
        // Enforce at least one selection
        if (!set.size) {
          set.add(key);
          chk.checked = true;
          this.toast('Seleziona almeno 1 sede', 'error');
        }
        this.estimateWizardSelectedLocationKeys = Array.from(set);
        try { this.estimateWizardAutoSaveSoon(); } catch (_) {}
        // Update sites_count field best-effort if empty
        try {
          const sitesEl = document.getElementById('planningEstimateWizardSitesCount');
          if (sitesEl && !String(sitesEl.value || '').trim()) {
            sitesEl.value = String(this.estimateWizardSelectedLocationKeys.length);
          }
        } catch (_) {}
        this.estimateWizardRenderClientLocations();
      });
      chk.dataset.cnxBound = '1';
    });
  }

  estimateWizardGetSelectedLocationsSnapshot() {
    const locs = Array.isArray(this.estimateWizardClientLocations) ? this.estimateWizardClientLocations : [];
    const selected = new Set((this.estimateWizardSelectedLocationKeys || []).map(String));
    return locs
      .filter(l => l && selected.has(String(l._key || '')))
      .map(l => ({
        id: l.id ?? null,
        location_type: String(l.location_type || ''),
        is_primary: !!l.is_primary,
        comune: String(l.comune || ''),
        provincia: String(l.provincia || ''),
        indirizzo_completo: String(l.indirizzo_completo || ''),
        label: String(l.label || ''),
        key: String(l._key || ''),
      }));
  }

  closeEstimateWizard() {
    try {
      const cid = parseInt(document.getElementById('planningEstimateWizardClient')?.value || '0', 10) || 0;
      if (cid) this.estimateWizardSaveDraftNow({ clientId: cid, includeEstimate: true, reason: 'close' });
    } catch (_) {}
    this.closeModal('planningEstimateWizardModal');
  }

  renderEstimateWizard() {
    const indicator = document.getElementById('planningEstimateWizardStepIndicator');
    const steps = 5;
    if (indicator) indicator.innerHTML = `<strong>Step ${this.estimateWizardStep}/${steps}</strong>`;

    for (let i = 1; i <= steps; i++) {
      const el = document.getElementById(`planningEstimateWizardStep${i}`);
      if (el) el.style.display = (i === this.estimateWizardStep) ? 'block' : 'none';
    }

    const backBtn = document.getElementById('planningEstimateWizardBackBtn');
    const nextBtn = document.getElementById('planningEstimateWizardNextBtn');
    if (backBtn) backBtn.style.display = this.estimateWizardStep === 1 ? 'none' : 'inline-flex';
    if (nextBtn) {
      nextBtn.disabled = false;
      if (this.estimateWizardStep === 4) {
        // Require estimate to proceed
        nextBtn.disabled = !(this.estimateWizardEstimateJson && this.estimateWizardEstimateJson.estimates && this.estimateWizardEstimateJson.estimates.length);
      }
      nextBtn.textContent = (this.estimateWizardStep === 5) ? 'Crea piano e genera attività' : 'Avanti';
    }
  }

  estimateWizardPrev() {
    this.estimateWizardStep = Math.max(1, this.estimateWizardStep - 1);
    this.renderEstimateWizard();
  }

  async estimateWizardNext() {
    const steps = 5;

    if (this.estimateWizardStep === 1) {
      const client = parseInt(document.getElementById('planningEstimateWizardClient')?.value || '0', 10) || 0;
      if (!client) return this.toast('Seleziona un’azienda cliente', 'error');
      // Ensure locations are loaded (best-effort) before moving on
      try { await this.estimateWizardLoadClientLocations(client); } catch (_) {}
    }
    if (this.estimateWizardStep === 2) {
      const ids = this.estimateWizardGetSelectedServiceIds();
      if (!ids.length) return this.toast('Seleziona almeno 1 servizio/norma', 'error');
    }
    if (this.estimateWizardStep === 3) {
      // Planning 2026: intervention type is REQUIRED (it drives which phases/tasks are created)
      const it = (document.getElementById('planningEstimateWizardInterventionType')?.value || '').trim();
      if (!it) {
        try {
          const adv = document.getElementById('planningEstimateWizardAdvancedDetails');
          if (adv) adv.open = true;
        } catch (_) {}
        this.toast('Seleziona “Tipo intervento” (obbligatorio)', 'error');
        try { document.getElementById('planningEstimateWizardInterventionType')?.focus(); } catch (_) {}
        return;
      }
    }
    if (this.estimateWizardStep === 4) {
      if (!this.estimateWizardEstimateJson || !Array.isArray(this.estimateWizardEstimateJson.estimates) || !this.estimateWizardEstimateJson.estimates.length) {
        return this.toast('Calcola prima la stima (Step 4)', 'error');
      }
    }

    if (this.estimateWizardStep < steps) {
      this.estimateWizardStep++;
      if (this.estimateWizardStep === 2) {
        await this.estimateWizardLoadCatalog({ force: false });
        this.estimateWizardRenderServicesOptions();
      }
      if (this.estimateWizardStep === 5) {
        this.estimateWizardRenderSummary();
      }
      this.renderEstimateWizard();
      return;
    }

    // Step 5: create plan + generate items
    await this.estimateWizardCreatePlanAndGenerate();
  }

  estimateWizardGetSelectedServiceIds() {
    const sel = document.getElementById('planningEstimateWizardServices');
    const ids = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];
    return Array.from(new Set(ids));
  }

  estimateWizardIsIso14001Selected() {
    try {
      const ids = this.estimateWizardGetSelectedServiceIds();
      if (!ids.length) return false;
      const byId = new Map((this.estimateWizardCatalog || []).map(b => [parseInt(b?.id, 10) || 0, b]));
      for (const sid of ids) {
        const b = byId.get(parseInt(sid, 10) || 0);
        if (!b) continue;
        const code = String(b.service_code || '').toUpperCase();
        const name = String(b.name || '').toUpperCase();
        if (code.includes('ISO14001') || name.includes('14001')) return true;
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  estimateWizardUpdateDynamicAdvancedBlocks() {
    try {
      const ids = this.estimateWizardGetSelectedServiceIds();
      const byId = new Map((this.estimateWizardCatalog || []).map(b => [parseInt(b?.id, 10) || 0, b]));
      const codes = new Set();
      const scheme = new Set();
      for (const sid of ids) {
        const b = byId.get(parseInt(sid, 10) || 0);
        if (!b) continue;
        const codeUp = String(b.service_code || '').trim().toUpperCase();
        const nameUp = String(b.name || '').trim().toUpperCase();
        const schemeUp = String(b.scheme_type || '').trim().toUpperCase();
        if (codeUp) codes.add(codeUp);
        if (schemeUp) scheme.add(schemeUp);
        // Fallback inference when service_code missing
        if (!codeUp && nameUp) {
          if (nameUp.includes('14001')) codes.add('ISO14001');
          if (nameUp.includes('17025')) codes.add('ISOIEC17025');
          if (nameUp.includes('HACCP')) codes.add('HACCP');
          if (nameUp.includes('22000')) codes.add('ISO22000');
          if (nameUp.includes('BRC')) codes.add('BRC');
          if (nameUp.includes('IFS')) codes.add('IFS');
          if (nameUp.includes('GDPR') || nameUp.includes('PRIVACY') || nameUp.includes('DPO')) codes.add('PRIVACY');
          if (nameUp.includes('231') || nameUp.includes('ODV')) codes.add('ODV231');
          if (nameUp.includes('ACCRED')) codes.add('ACCRED');
          if (nameUp.includes('CE')) codes.add('CE');
        }
      }

      const hasIso14001 = codes.has('ISO14001');
      const hasFood = scheme.has('FSMS') || scheme.has('GFSI') || ['HACCP','ISO22000','BRC','IFS','BRCBROKERS','BIO'].some(c => codes.has(c));
      const has17025 = scheme.has('LAB') || codes.has('ISOIEC17025');
      const hasCe = codes.has('CE');
      const hasGdpr = scheme.has('PRIVACY') || codes.has('PRIVACY');
      const has231 = scheme.has('231') || codes.has('ODV231');
      const hasAccred = codes.has('ACCRED');

      const iso14001Wrap = document.getElementById('planningEstimateWizardIso14001Advanced');
      if (iso14001Wrap) iso14001Wrap.style.display = hasIso14001 ? 'block' : 'none';

      const foodWrap = document.getElementById('planningEstimateWizardFoodAdvanced');
      if (foodWrap) foodWrap.style.display = hasFood ? 'block' : 'none';

      const w17025 = document.getElementById('planningEstimateWizard17025Advanced');
      if (w17025) w17025.style.display = has17025 ? 'block' : 'none';

      const ceWrap = document.getElementById('planningEstimateWizardCeAdvanced');
      if (ceWrap) ceWrap.style.display = hasCe ? 'block' : 'none';

      const gdprWrap = document.getElementById('planningEstimateWizardGdprAdvanced');
      if (gdprWrap) gdprWrap.style.display = hasGdpr ? 'block' : 'none';

      const w231 = document.getElementById('planningEstimateWizard231Advanced');
      if (w231) w231.style.display = has231 ? 'block' : 'none';

      const wAccred = document.getElementById('planningEstimateWizardAccredAdvanced');
      if (wAccred) wAccred.style.display = hasAccred ? 'block' : 'none';
    } catch (_) {}
  }

  estimateWizardBindEstimateDraftAutosaveOnce() {
    // Prepare debounced autosave function once per page load
    if (!this._estimateWizardAutoSaveFn) {
      this._estimateWizardAutoSaveFn = this.debounce(() => {
        try { this.estimateWizardSaveDraftNow({ reason: 'auto' }); } catch (_) {}
      }, 650);
    }

    const bind = (el, evt) => {
      if (!el) return;
      if (el.dataset?.cnxEstimateAutoBound === '1') return;
      el.addEventListener(evt, () => {
        try {
          if (el.id === 'planningEstimateWizardServices') {
            this.estimateWizardUpdateDynamicAdvancedBlocks();
            this.estimateWizardUpdateServiceWarnings();
          }
        } catch (_) {}
        this.estimateWizardAutoSaveSoon();
      });
      el.dataset.cnxEstimateAutoBound = '1';
    };

    // Step 1
    bind(document.getElementById('planningEstimateWizardTitle'), 'input');
    bind(document.getElementById('planningEstimateWizardPeriodEnd'), 'change');

    // Step 2
    bind(document.getElementById('planningEstimateWizardServices'), 'change');

    // Step 3 (base)
    bind(document.getElementById('planningEstimateWizardSector'), 'input');
    bind(document.getElementById('planningEstimateWizardEmployeesRange'), 'change');
    bind(document.getElementById('planningEstimateWizardSitesCount'), 'input');
    bind(document.getElementById('planningEstimateWizardRegulated'), 'change');
    bind(document.getElementById('planningEstimateWizardWebsite'), 'input');
    bind(document.getElementById('planningEstimateWizardNotes'), 'input');

    // Step 3 (advanced)
    bind(document.getElementById('planningEstimateWizardInterventionType'), 'change');
    bind(document.getElementById('planningEstimateWizardQmsMaturity'), 'change');
    bind(document.getElementById('planningEstimateWizardCoreProcessCount'), 'input');
    bind(document.getElementById('planningEstimateWizardDepartmentsCount'), 'input');
    bind(document.getElementById('planningEstimateWizardProductLinesCount'), 'input');
    bind(document.getElementById('planningEstimateWizardCriticalSuppliersCount'), 'input');
    bind(document.getElementById('planningEstimateWizardDesignApplicability'), 'change');
    bind(document.getElementById('planningEstimateWizardOutsourcingLevel'), 'change');
    bind(document.getElementById('planningEstimateWizardItMaturity'), 'change');
    bind(document.getElementById('planningEstimateWizardPreferredDeliveryDeadline'), 'change');
    bind(document.getElementById('planningEstimateWizardBlackoutPeriods'), 'input');
    bind(document.getElementById('planningEstimateWizardLanguagesNeeded'), 'change');
    bind(document.getElementById('planningEstimateWizardOnSitePreferenceRatio'), 'input');
    bind(document.getElementById('planningEstimateWizardUseAiEnrichment'), 'change');

    // ISO 14001 advanced (dynamic)
    bind(document.getElementById('planningEstimateWizardIso14001AspectsComplexity'), 'change');
    bind(document.getElementById('planningEstimateWizardIso14001PermitsPresence'), 'change');
    bind(document.getElementById('planningEstimateWizardIso14001HazardousSubstances'), 'change');

    // Conditional advanced blocks (dynamic)
    // Food
    bind(document.getElementById('planningEstimateWizardFoodHaccpStudiesCount'), 'input');
    bind(document.getElementById('planningEstimateWizardFoodShiftsCount'), 'change');
    bind(document.getElementById('planningEstimateWizardFoodHighRiskProducts'), 'change');
    bind(document.getElementById('planningEstimateWizardFoodProductionAreaSizeClass'), 'change');
    bind(document.getElementById('planningEstimateWizardFoodProductionAreaM2'), 'input');
    bind(document.getElementById('planningEstimateWizardFoodLabsInHouse'), 'change');
    // ISO/IEC 17025
    bind(document.getElementById('planningEstimateWizard17025Disciplines'), 'change');
    bind(document.getElementById('planningEstimateWizard17025MethodsCount'), 'input');
    bind(document.getElementById('planningEstimateWizard17025SamplingInScope'), 'change');
    bind(document.getElementById('planningEstimateWizard17025MultiSiteLab'), 'change');
    // CE
    bind(document.getElementById('planningEstimateWizardCeRegulation'), 'input');
    bind(document.getElementById('planningEstimateWizardCeRiskClass'), 'input');
    bind(document.getElementById('planningEstimateWizardCeNotifiedBodyRequired'), 'change');
    // GDPR
    bind(document.getElementById('planningEstimateWizardGdprProcessingActivitiesCount'), 'input');
    bind(document.getElementById('planningEstimateWizardGdprMainItSystemsCount'), 'input');
    bind(document.getElementById('planningEstimateWizardGdprSpecialCategoriesData'), 'change');
    bind(document.getElementById('planningEstimateWizardGdprExtraEuTransfers'), 'change');
    // 231
    bind(document.getElementById('planningEstimateWizard231MogExisting'), 'change');
    bind(document.getElementById('planningEstimateWizard231RiskAreasCount'), 'input');
    bind(document.getElementById('planningEstimateWizard231SubsidiariesCount'), 'input');
    // Accreditamenti
    bind(document.getElementById('planningEstimateWizardAccredFacilitiesUnitsCount'), 'input');
    bind(document.getElementById('planningEstimateWizardAccredRegionalRequirementsComplexity'), 'change');

    // Advanced collapse state
    const adv = document.getElementById('planningEstimateWizardAdvancedDetails');
    if (adv && adv.dataset?.cnxEstimateAutoBound !== '1') {
      adv.addEventListener('toggle', () => this.estimateWizardAutoSaveSoon());
      adv.dataset.cnxEstimateAutoBound = '1';
    }
  }

  estimateWizardAutoSaveSoon() {
    try {
      if (this._estimateWizardAutoSaveFn) this._estimateWizardAutoSaveFn();
      this.estimateWizardUpdateDraftBanner();
    } catch (_) {}
  }

  estimateWizardGetDraftLocalKey(clientId) {
    const uid = (document.getElementById('planningCurrentUserId')?.value || '').trim() || '0';
    const nonce = (document.getElementById('planningLoginNonce')?.value || '').trim() || '';
    const base = `cnx_planning_estimate_wizard_draft_v2_${uid}_c${String(clientId || '0')}`;
    return nonce ? `${base}_${nonce}` : base;
  }

  estimateWizardReadLocalDraft(clientId) {
    try {
      const key = this.estimateWizardGetDraftLocalKey(clientId);
      const raw = localStorage.getItem(key);
      if (!raw) return null;
      const draft = JSON.parse(raw);
      if (!draft || typeof draft !== 'object') return null;
      const cid = parseInt(draft.client_id || '0', 10) || 0;
      if (cid !== (parseInt(clientId || '0', 10) || 0)) return null;
      return draft;
    } catch (_) {
      return null;
    }
  }

  estimateWizardWriteLocalDraft(clientId, draft) {
    try {
      const key = this.estimateWizardGetDraftLocalKey(clientId);
      localStorage.setItem(key, JSON.stringify(draft));
      return true;
    } catch (_) {
      return false;
    }
  }

  estimateWizardClearLocalDraft(clientId) {
    try {
      const key = this.estimateWizardGetDraftLocalKey(clientId);
      localStorage.removeItem(key);
      return true;
    } catch (_) {
      return false;
    }
  }

  estimateWizardSetSelectedServices(serviceIds) {
    const sel = document.getElementById('planningEstimateWizardServices');
    if (!sel) return;
    const set = new Set((Array.isArray(serviceIds) ? serviceIds : []).map(v => String(parseInt(v, 10) || 0)));
    [...sel.options].forEach(o => {
      const id = String(parseInt(o.value || '0', 10) || 0);
      o.selected = set.has(id);
    });
    this.enableClickToggleMultiSelect(sel);
  }

  estimateWizardApplyDraftToUi(draft) {
    if (!draft || typeof draft !== 'object') return;
    const clientId = parseInt(draft.client_id || '0', 10) || 0;
    if (!clientId) return;

    // Step 1 fields (kept under draft, not always present in server session)
    const titleEl = document.getElementById('planningEstimateWizardTitle');
    if (titleEl && draft.title !== undefined && draft.title !== null) titleEl.value = String(draft.title || '');
    const periodEl = document.getElementById('planningEstimateWizardPeriodEnd');
    if (periodEl && draft.period_end !== undefined && draft.period_end !== null) periodEl.value = String(draft.period_end || '');

    // Locations
    if (Array.isArray(draft.selected_location_keys)) {
      this.estimateWizardSelectedLocationKeys = draft.selected_location_keys.map(String).filter(Boolean);
    }

    // Services selection
    if (Array.isArray(draft.services)) {
      try {
        const catSel = document.getElementById('planningEstimateWizardServiceCategory');
        const searchEl = document.getElementById('planningEstimateWizardServiceSearch');
        if (catSel) catSel.value = '';
        if (searchEl) searchEl.value = '';
      } catch (_) {}
      this.estimateWizardRenderServicesOptions();
      this.estimateWizardSetSelectedServices(draft.services);
    }

    // Profile fields (base + advanced)
    const p = (draft.profile && typeof draft.profile === 'object') ? draft.profile : {};
    // Apply draft values only if present and non-empty (do NOT override prefill with empty draft values)
    const setVal = (id, v) => {
      const el = document.getElementById(id);
      if (!el) return;
      if (v === undefined || v === null) return;
      if (typeof v === 'string' && !v.trim()) return;
      el.value = String(v);
    };

    setVal('planningEstimateWizardSector', p.sector);
    setVal('planningEstimateWizardEmployeesRange', p.employees_range);
    setVal('planningEstimateWizardSitesCount', p.sites_count);
    const regulatedEl = document.getElementById('planningEstimateWizardRegulated');
    if (regulatedEl && p.regulated !== undefined && p.regulated !== null) regulatedEl.checked = !!p.regulated;
    setVal('planningEstimateWizardWebsite', p.website);
    setVal('planningEstimateWizardNotes', p.notes);

    const it = String(p.intervention_type || '').trim();
    const intervention = (it === 'transition') ? 'transition_update' : it;
    setVal('planningEstimateWizardInterventionType', intervention);
    const qm = String(p.qms_maturity || '').trim();
    const maturity = (qm === 'partial') ? 'partial_informal' : qm;
    setVal('planningEstimateWizardQmsMaturity', maturity);

    setVal('planningEstimateWizardCoreProcessCount', p.core_process_count);
    setVal('planningEstimateWizardDepartmentsCount', p.departments_or_units_count);
    setVal('planningEstimateWizardProductLinesCount', p.product_service_lines_count);
    setVal('planningEstimateWizardCriticalSuppliersCount', p.critical_suppliers_count);
    setVal('planningEstimateWizardDesignApplicability', p.design_applicability);
    setVal('planningEstimateWizardOutsourcingLevel', p.outsourcing_level);
    setVal('planningEstimateWizardItMaturity', p.it_maturity);
    setVal('planningEstimateWizardPreferredDeliveryDeadline', p.preferred_delivery_deadline);
    setVal('planningEstimateWizardBlackoutPeriods', p.blackout_periods);
    setVal('planningEstimateWizardOnSitePreferenceRatio', p.on_site_preference_ratio);

    const langsEl = document.getElementById('planningEstimateWizardLanguagesNeeded');
    if (langsEl) {
      const s = new Set(Array.isArray(p.languages_needed) ? p.languages_needed.map(String) : []);
      [...langsEl.options].forEach(o => { o.selected = s.has(String(o.value || '')); });
    }
    const useAi = document.getElementById('planningEstimateWizardUseAiEnrichment');
    if (useAi) useAi.checked = !!p.use_ai_enrichment;

    // Advanced collapse state
    const adv = document.getElementById('planningEstimateWizardAdvancedDetails');
    if (adv && draft.ui && typeof draft.ui === 'object' && draft.ui.advanced_open !== undefined) {
      adv.open = !!draft.ui.advanced_open;
    }

    // ISO14001 block (only when ISO14001 selected)
    this.estimateWizardUpdateDynamicAdvancedBlocks();
    const isoWrap = document.getElementById('planningEstimateWizardIso14001Advanced');
    if (isoWrap && isoWrap.style.display !== 'none') {
      const iso = (p.iso14001 && typeof p.iso14001 === 'object') ? p.iso14001 : {};
      setVal('planningEstimateWizardIso14001AspectsComplexity', iso.environmental_aspects_complexity);
      setVal('planningEstimateWizardIso14001PermitsPresence', iso.permits_presence);
      setVal('planningEstimateWizardIso14001HazardousSubstances', iso.hazardous_substances);
    }

    const foodWrap = document.getElementById('planningEstimateWizardFoodAdvanced');
    if (foodWrap && foodWrap.style.display !== 'none') {
      const food = (p.food && typeof p.food === 'object') ? p.food : {};
      setVal('planningEstimateWizardFoodHaccpStudiesCount', food.haccp_studies_count);
      setVal('planningEstimateWizardFoodShiftsCount', food.shifts_count);
      setVal('planningEstimateWizardFoodHighRiskProducts', food.high_risk_products);
      setVal('planningEstimateWizardFoodProductionAreaSizeClass', food.production_area_size_class);
      setVal('planningEstimateWizardFoodProductionAreaM2', food.production_area_m2);
      setVal('planningEstimateWizardFoodLabsInHouse', food.labs_in_house);
    }

    const w17025 = document.getElementById('planningEstimateWizard17025Advanced');
    if (w17025 && w17025.style.display !== 'none') {
      const iso = (p.iso17025 && typeof p.iso17025 === 'object') ? p.iso17025 : {};
      const d = document.getElementById('planningEstimateWizard17025Disciplines');
      if (d) {
        const s = new Set(Array.isArray(iso.disciplines_json) ? iso.disciplines_json.map(String) : []);
        [...d.options].forEach(o => { o.selected = s.has(String(o.value || '')); });
      }
      setVal('planningEstimateWizard17025MethodsCount', iso.methods_count);
      setVal('planningEstimateWizard17025SamplingInScope', iso.sampling_in_scope);
      setVal('planningEstimateWizard17025MultiSiteLab', iso.multi_site_lab);
    }

    const ceWrap = document.getElementById('planningEstimateWizardCeAdvanced');
    if (ceWrap && ceWrap.style.display !== 'none') {
      const ce = (p.ce && typeof p.ce === 'object') ? p.ce : {};
      setVal('planningEstimateWizardCeRegulation', ce.ce_regulation);
      setVal('planningEstimateWizardCeRiskClass', ce.ce_risk_class);
      setVal('planningEstimateWizardCeNotifiedBodyRequired', ce.notified_body_required);
    }

    const gdprWrap = document.getElementById('planningEstimateWizardGdprAdvanced');
    if (gdprWrap && gdprWrap.style.display !== 'none') {
      const gdpr = (p.gdpr && typeof p.gdpr === 'object') ? p.gdpr : {};
      setVal('planningEstimateWizardGdprProcessingActivitiesCount', gdpr.processing_activities_count);
      setVal('planningEstimateWizardGdprMainItSystemsCount', gdpr.main_it_systems_count);
      setVal('planningEstimateWizardGdprSpecialCategoriesData', gdpr.special_categories_data);
      setVal('planningEstimateWizardGdprExtraEuTransfers', gdpr.extra_eu_transfers);
    }

    const w231 = document.getElementById('planningEstimateWizard231Advanced');
    if (w231 && w231.style.display !== 'none') {
      const odv = (p.odv231 && typeof p.odv231 === 'object') ? p.odv231 : {};
      setVal('planningEstimateWizard231MogExisting', odv.mog_existing);
      setVal('planningEstimateWizard231RiskAreasCount', odv.risk_areas_count);
      setVal('planningEstimateWizard231SubsidiariesCount', odv.subsidiaries_count);
    }

    const wAccred = document.getElementById('planningEstimateWizardAccredAdvanced');
    if (wAccred && wAccred.style.display !== 'none') {
      const acc = (p.accred && typeof p.accred === 'object') ? p.accred : {};
      setVal('planningEstimateWizardAccredFacilitiesUnitsCount', acc.facilities_units_count);
      setVal('planningEstimateWizardAccredRegionalRequirementsComplexity', acc.regional_requirements_complexity);
    }
    try { this.estimateWizardUpdateServiceWarnings(); } catch (_) {}

    // Estimate JSON restore
    if (draft.estimate && typeof draft.estimate === 'object') {
      // If estimate was generated by an older engine (pre 2026-01-17), force recalculation.
      // Heuristic: new engine includes `scheme_type` key per estimate.
      let legacyEstimate = false;
      try {
        const arr = Array.isArray(draft.estimate.estimates) ? draft.estimate.estimates : [];
        if (arr.length) {
          const e0 = arr[0];
          if (e0 && typeof e0 === 'object') {
            if (!Object.prototype.hasOwnProperty.call(e0, 'scheme_type')) legacyEstimate = true;
            const rat = String(e0.rationale || '');
            if (rat.startsWith('Baseline:')) legacyEstimate = true;
          }
        }
      } catch (_) {}
      if (!legacyEstimate) {
        this.estimateWizardEstimateJson = draft.estimate;
        try { this.estimateWizardRenderEstimateBox(); } catch (_) {}
      } else {
        this.estimateWizardEstimateJson = null;
      }
    }

    // Session id (server)
    const sid = parseInt(draft?.server?.session_id || '0', 10) || 0;
    if (sid > 0) {
      this.estimateWizardSessionId = sid;
      this.estimateWizardSessionsStorageAvailable = true;
    }

    // Step
    let step = parseInt(draft.step || '1', 10) || 1;
    if (step < 1) step = 1;
    if (step > 5) step = 5;
    if (!this.estimateWizardEstimateJson) step = Math.min(step, 3);
    this.estimateWizardStep = step;
    if (this.estimateWizardStep === 5) {
      try { this.estimateWizardRenderSummary(); } catch (_) {}
    }
    this.renderEstimateWizard();
    try { this.estimateWizardRenderClientLocations(); } catch (_) {}
  }

  async estimateWizardTryRestoreDraft(clientId) {
    const cid = parseInt(String(clientId || '0'), 10) || 0;
    if (!cid) return;

    let local = this.estimateWizardReadLocalDraft(cid);

    let serverDraft = null;
    try {
      const res = await this.apiFetch(`consulting_plans/estimate_session.php?action=get&client_id=${encodeURIComponent(String(cid))}`, { method: 'GET', json: true });
      const d = res?.data || {};
      if (d.storage_available === false) {
        this.estimateWizardSessionsStorageAvailable = false;
      } else if (d.storage_available === true) {
        this.estimateWizardSessionsStorageAvailable = true;
      }
      const s = d.session || null;
      if (s && (parseInt(s.id || '0', 10) || 0) > 0) {
        const profile = (s.profile && typeof s.profile === 'object') ? s.profile : {};
        const w = (profile.__wizard && typeof profile.__wizard === 'object') ? profile.__wizard : {};
        serverDraft = {
          v: 2,
          saved_at: w.saved_at || s.updated_at || '',
          client_id: cid,
          step: w.step || (s.estimate ? 4 : 3),
          title: w.title || null,
          period_end: w.period_end || null,
          services: Array.isArray(s.services) ? s.services : [],
          selected_location_keys: Array.isArray(w.selected_location_keys) ? w.selected_location_keys : [],
          profile: Object.assign({}, profile),
          estimate: (s.estimate && typeof s.estimate === 'object') ? s.estimate : null,
          ui: { advanced_open: !!w.advanced_open },
          server: {
            session_id: parseInt(s.id || '0', 10) || 0,
            updated_at: s.updated_at || '',
            linked_plan_id: (s.linked_plan_id === null || s.linked_plan_id === undefined) ? null : s.linked_plan_id,
          },
        };
      }
    } catch (e) {
      // non-blocking: local draft is still usable
      this.estimateWizardDraftLastError = e?.message || 'restore_failed';
    }

    const parseTs = (s) => {
      if (!s) return 0;
      const t = Date.parse(String(s));
      return Number.isFinite(t) ? t : 0;
    };

    let chosen = null;
    if (local && serverDraft) {
      const lt = parseTs(local.saved_at || local.server?.updated_at || '');
      const st = parseTs(serverDraft.saved_at || serverDraft.server?.updated_at || '');
      chosen = (lt >= st) ? local : serverDraft;
    } else {
      chosen = local || serverDraft;
    }

    if (!chosen) {
      this.estimateWizardUpdateDraftBanner();
      return;
    }

    // Keep local in sync if restoring from server
    if (chosen === serverDraft) {
      try { this.estimateWizardWriteLocalDraft(cid, chosen); } catch (_) {}
    }

    this.estimateWizardApplyDraftToUi(chosen);
    // Fill missing profile fields from Aziende (doesn't override user-provided values)
    try { this.estimateWizardPrefillCompanyProfileFromClient(cid); } catch (_) {}
    this.estimateWizardDraftLastSavedAt = String(chosen.saved_at || '');
    this.estimateWizardDraftLastSource = (chosen === serverDraft) ? 'server' : 'localStorage';
    this.estimateWizardUpdateDraftBanner();
  }

  estimateWizardUpdateDraftBanner() {
    const banner = document.getElementById('planningEstimateWizardDraftBanner');
    if (!banner) return;
    const clientId = parseInt(document.getElementById('planningEstimateWizardClient')?.value || '0', 10) || 0;
    if (!clientId) {
      banner.style.display = 'none';
      banner.innerHTML = '';
      return;
    }

    const savedAt = this.estimateWizardDraftLastSavedAt || (this.estimateWizardReadLocalDraft(clientId)?.saved_at || '');
    const when = savedAt ? (new Date(savedAt)).toLocaleString() : '—';
    const storage = this.estimateWizardSessionsStorageAvailable;
    const serverLabel = storage === false
      ? 'Server: non disponibile (usa solo browser)'
      : (storage === true ? `Server: disponibile${this.estimateWizardSessionId ? ` (sessione #${this.estimateWizardSessionId})` : ''}` : 'Server: verifica in corso…');
    const err = String(this.estimateWizardDraftLastError || '').trim();

    banner.style.display = 'block';
    banner.innerHTML = `
      <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px; flex-wrap:wrap;">
        <div>
          <div style="font-weight:700; margin-bottom:4px;">Bozza stima</div>
          <div class="planning-muted">Ultimo salvataggio: <strong>${this.escapeHtml(when)}</strong> (${this.escapeHtml(this.estimateWizardDraftLastSource || '—')})</div>
          <div class="planning-muted" style="margin-top:4px;">${this.escapeHtml(serverLabel)}</div>
          ${err ? `<div class="planning-muted" style="margin-top:4px;"><strong>Nota:</strong> ${this.escapeHtml(err)}</div>` : ''}
        </div>
        <div class="planning-actions" style="gap:8px;">
          <button type="button" class="btn btn-secondary btn-sm" id="planningEstimateWizardDraftSaveBtn">Salva ora</button>
          <button type="button" class="btn btn-secondary btn-sm" id="planningEstimateWizardDraftNewBtn" title="Azzera i campi e avvia una nuova bozza (mantiene l’azienda selezionata)">Nuova stima</button>
        </div>
      </div>
    `;

    const saveBtn = document.getElementById('planningEstimateWizardDraftSaveBtn');
    if (saveBtn && saveBtn.dataset?.cnxBound !== '1') {
      saveBtn.addEventListener('click', async () => {
        const cid = parseInt(document.getElementById('planningEstimateWizardClient')?.value || '0', 10) || 0;
        if (!cid) return;
        try {
          await this.estimateWizardSaveDraftNow({ clientId: cid, includeEstimate: true, reason: 'manual_save' });
          this.toast('Bozza salvata', 'success');
        } catch (e) {
          this.toast(e?.message || 'Errore salvataggio bozza', 'error');
        }
      });
      saveBtn.dataset.cnxBound = '1';
    }

    const newBtn = document.getElementById('planningEstimateWizardDraftNewBtn');
    if (newBtn && newBtn.dataset?.cnxBound !== '1') {
      newBtn.addEventListener('click', async () => {
        const cid = parseInt(document.getElementById('planningEstimateWizardClient')?.value || '0', 10) || 0;
        if (!cid) return;
        this.estimateWizardSessionId = 0;
        this.estimateWizardDraftLastError = '';
        this.estimateWizardClearLocalDraft(cid);

        // Reset fields but keep the selected client
        try {
          const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = String(v ?? ''); };
          setVal('planningEstimateWizardTitle', '');
          setVal('planningEstimateWizardPeriodEnd', '');
          setVal('planningEstimateWizardSector', '');
          setVal('planningEstimateWizardEmployeesRange', '');
          setVal('planningEstimateWizardSitesCount', '');
          setVal('planningEstimateWizardWebsite', '');
          setVal('planningEstimateWizardNotes', '');
          const regulatedEl = document.getElementById('planningEstimateWizardRegulated');
          if (regulatedEl) regulatedEl.checked = false;

          setVal('planningEstimateWizardInterventionType', '');
          setVal('planningEstimateWizardQmsMaturity', '');
          setVal('planningEstimateWizardCoreProcessCount', '');
          setVal('planningEstimateWizardDepartmentsCount', '');
          setVal('planningEstimateWizardProductLinesCount', '');
          setVal('planningEstimateWizardCriticalSuppliersCount', '');
          setVal('planningEstimateWizardDesignApplicability', 'unknown');
          setVal('planningEstimateWizardOutsourcingLevel', '');
          setVal('planningEstimateWizardItMaturity', '');
          setVal('planningEstimateWizardPreferredDeliveryDeadline', '');
          setVal('planningEstimateWizardBlackoutPeriods', '');
          setVal('planningEstimateWizardOnSitePreferenceRatio', '');
          const langs = document.getElementById('planningEstimateWizardLanguagesNeeded');
          if (langs) [...langs.options].forEach(o => { o.selected = false; });
          const useAi = document.getElementById('planningEstimateWizardUseAiEnrichment');
          if (useAi) useAi.checked = false;

          setVal('planningEstimateWizardIso14001AspectsComplexity', '');
          setVal('planningEstimateWizardIso14001PermitsPresence', 'unknown');
          setVal('planningEstimateWizardIso14001HazardousSubstances', 'unknown');

          const adv = document.getElementById('planningEstimateWizardAdvancedDetails');
          if (adv) adv.open = false;

          const servicesSel = document.getElementById('planningEstimateWizardServices');
          if (servicesSel) [...servicesSel.options].forEach(o => { o.selected = false; });

          this.estimateWizardEstimateJson = null;
          const box = document.getElementById('planningEstimateWizardEstimateBox');
          if (box) { box.style.display = 'none'; box.innerHTML = ''; }
          const summary = document.getElementById('planningEstimateWizardSummary');
          if (summary) { summary.style.display = 'none'; summary.innerHTML = ''; }
          this.estimateWizardStep = 2;
          this.renderEstimateWizard();

          // Reset locations to default (best-effort)
          this.estimateWizardSelectedLocationKeys = [];
          try { await this.estimateWizardLoadClientLocations(cid); } catch (_) {}
          this.estimateWizardUpdateDynamicAdvancedBlocks();
        } catch (_) {}

        // Save immediately to create a new "most recent" server session (if available)
        // Prefill from azienda before saving the new draft (best-effort)
        try { this.estimateWizardPrefillCompanyProfileFromClient(cid); } catch (_) {}
        try { await this.estimateWizardSaveDraftNow({ clientId: cid, includeEstimate: false, reason: 'reset_new' }); } catch (_) {}
        this.toast('Nuova bozza avviata', 'success');
      });
      newBtn.dataset.cnxBound = '1';
    }
  }

  async estimateWizardSaveDraftNow({ clientId = null, serviceIds = null, companyProfile = null, includeEstimate = undefined, reason = 'auto' } = {}) {
    const cid = parseInt(String(clientId || (document.getElementById('planningEstimateWizardClient')?.value || '0')), 10) || 0;
    if (!cid) return;

    const title = (document.getElementById('planningEstimateWizardTitle')?.value || '').trim();
    const periodEnd = (document.getElementById('planningEstimateWizardPeriodEnd')?.value || '').trim();
    const services = Array.isArray(serviceIds) ? serviceIds : this.estimateWizardGetSelectedServiceIds();
    const profile = (companyProfile && typeof companyProfile === 'object') ? companyProfile : this.estimateWizardCollectCompanyProfile();
    const advOpen = !!document.getElementById('planningEstimateWizardAdvancedDetails')?.open;

    const selectedLocationKeys = Array.isArray(this.estimateWizardSelectedLocationKeys)
      ? this.estimateWizardSelectedLocationKeys.map(String).filter(Boolean)
      : [];

    const shouldIncludeEstimate = (includeEstimate === undefined) ? !!this.estimateWizardEstimateJson : !!includeEstimate;
    const draft = {
      v: 2,
      saved_at: new Date().toISOString(),
      client_id: cid,
      step: this.estimateWizardStep || 1,
      title: title || null,
      period_end: periodEnd || null,
      services,
      selected_location_keys: selectedLocationKeys,
      profile,
      estimate: shouldIncludeEstimate ? (this.estimateWizardEstimateJson || null) : null,
      ui: { advanced_open: advOpen },
      server: { session_id: this.estimateWizardSessionId || 0 },
      meta: { reason: String(reason || '') },
    };

    // Local draft is always attempted (safe fallback)
    this.estimateWizardWriteLocalDraft(cid, draft);
    this.estimateWizardDraftLastSavedAt = draft.saved_at;
    this.estimateWizardDraftLastSource = 'localStorage';
    this.estimateWizardDraftLastError = '';
    this.estimateWizardUpdateDraftBanner();

    // Server draft (optional)
    if (this.estimateWizardSessionsStorageAvailable === false) return;

    // Throttle server writes during continuous typing
    const now = Date.now();
    if (reason === 'auto' && this._estimateWizardLastServerSaveTs && (now - this._estimateWizardLastServerSaveTs) < 1500) {
      return;
    }

    const profileForSession = Object.assign({}, profile, {
      __wizard: {
        title: title || null,
        period_end: periodEnd || null,
        selected_location_keys: selectedLocationKeys,
        step: this.estimateWizardStep || 1,
        advanced_open: advOpen,
        saved_at: draft.saved_at,
      },
    });

    try {
      const saveProfileRes = await this.apiFetch('consulting_plans/estimate_session.php?action=save_profile', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_id: cid,
          session_id: this.estimateWizardSessionId || 0,
          services,
          profile: profileForSession,
        }),
      });

      const storageOk = saveProfileRes?.data?.storage_available;
      if (storageOk === false) {
        this.estimateWizardSessionsStorageAvailable = false;
        this.estimateWizardDraftLastSource = 'localStorage';
        this.estimateWizardUpdateDraftBanner();
        return;
      }
      this.estimateWizardSessionsStorageAvailable = true;
      const sid = parseInt(saveProfileRes?.data?.session_id || '0', 10) || 0;
      if (sid > 0) {
        this.estimateWizardSessionId = sid;
        draft.server.session_id = sid;
        this.estimateWizardWriteLocalDraft(cid, draft);
      }
      this._estimateWizardLastServerSaveTs = Date.now();
      this.estimateWizardDraftLastSource = 'server';
      this.estimateWizardUpdateDraftBanner();

      if (shouldIncludeEstimate && this.estimateWizardEstimateJson && this.estimateWizardSessionId > 0) {
        if (reason !== 'auto' || !this._estimateWizardLastServerEstimateTs || (Date.now() - this._estimateWizardLastServerEstimateTs) > 2000) {
          await this.apiFetch('consulting_plans/estimate_session.php?action=save_estimate', {
            method: 'POST',
            json: true,
            body: JSON.stringify({
              csrf_token: this.csrfToken,
              client_id: cid,
              session_id: this.estimateWizardSessionId,
              estimate: this.estimateWizardEstimateJson,
            }),
          });
          this._estimateWizardLastServerEstimateTs = Date.now();
        }
      }
    } catch (e) {
      this.estimateWizardDraftLastError = e?.message || 'Server save failed';
      this.estimateWizardUpdateDraftBanner();
    }
  }

  async estimateWizardLinkSessionToPlan({ clientId = 0, planId = 0 } = {}) {
    const cid = parseInt(String(clientId || '0'), 10) || 0;
    const pid = parseInt(String(planId || '0'), 10) || 0;
    if (cid <= 0 || pid <= 0) return;
    if (this.estimateWizardSessionsStorageAvailable === false) return;
    if (!this.estimateWizardSessionId) return;
    try {
      const res = await this.apiFetch('consulting_plans/estimate_session.php?action=link_plan', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_id: cid,
          session_id: this.estimateWizardSessionId,
          linked_plan_id: pid,
        }),
      });
      if (res?.data?.storage_available === false) {
        this.estimateWizardSessionsStorageAvailable = false;
      } else {
        this.estimateWizardSessionsStorageAvailable = true;
      }
    } catch (_) {
      // non-blocking
    }
  }

  async estimateWizardLoadCatalog({ force = false } = {}) {
    const maxAgeMs = 60_000;
    if (!force && this.estimateWizardCatalogLoadedAt && (Date.now() - this.estimateWizardCatalogLoadedAt) < maxAgeMs && Array.isArray(this.estimateWizardCatalog) && this.estimateWizardCatalog.length) {
      return;
    }
    try {
      // Keep catalog aligned with base set (idempotent) - attempt once per session
      if (!this.baseActivitySeedAttempted) {
        this.baseActivitySeedAttempted = true;
        try { await this.seedServiceCatalog({ showToast: false }); } catch (_) {}
      }
      const data = await this.apiFetch('consulting_plans/activity_types.php', { method: 'GET', json: true });
      const types = Array.isArray(data?.data?.types) ? data.data.types : [];
      // types[] are {base,effective}; we want base for selection
      this.estimateWizardCatalog = types.map(t => t?.base).filter(b => b && typeof b === 'object' && (parseInt(b.id, 10) || 0) > 0);
      this.estimateWizardCatalogLoadedAt = Date.now();

      // Render category options
      const cats = new Set();
      for (const b of this.estimateWizardCatalog) {
        const c = String(b.category || '').trim();
        if (c) cats.add(c);
      }
      const catSel = document.getElementById('planningEstimateWizardServiceCategory');
      if (catSel) {
        const sorted = Array.from(cats).sort((a, b) => a.localeCompare(b));
        catSel.innerHTML = `<option value="">Tutte</option>` + sorted.map(c => `<option value="${this.escapeAttr(c)}">${this.escapeHtml(c)}</option>`).join('');
      }
    } catch (e) {
      console.warn('[EstimateWizard] catalog load failed:', e?.message || e);
      this.estimateWizardCatalog = [];
      this.estimateWizardCatalogLoadedAt = Date.now();
      const catSel = document.getElementById('planningEstimateWizardServiceCategory');
      if (catSel) catSel.innerHTML = `<option value="">Tutte</option>`;
    }
  }

  estimateWizardRenderServicesOptions() {
    const sel = document.getElementById('planningEstimateWizardServices');
    if (!sel) return;
    const prevSelected = new Set(this.estimateWizardGetSelectedServiceIds().map(String));

    const cat = (document.getElementById('planningEstimateWizardServiceCategory')?.value || '').trim();
    const q = String(document.getElementById('planningEstimateWizardServiceSearch')?.value || '').trim().toLowerCase();
    const showLegacy = !!document.getElementById('planningEstimateWizardShowLegacy')?.checked;

    const matchesQuery = (b) => {
      if (!q) return true;
      const name = String(b.name || '').toLowerCase();
      const code = String(b.service_code || '').toLowerCase();
      const aliases = Array.isArray(b.aliases) ? b.aliases.map(x => String(x || '').toLowerCase()) : [];
      return name.includes(q) || code.includes(q) || aliases.some(a => a.includes(q));
    };

    const isLegacy = (b) => {
      const codeUp = String(b?.service_code || '').trim().toUpperCase();
      return !!b?.legacy_combo || !!b?.is_legacy_composite || codeUp.includes('_') || codeUp === 'ACCREDIA';
    };

    let list = Array.isArray(this.estimateWizardCatalog) ? this.estimateWizardCatalog : [];
    list = list.filter(b => b && typeof b === 'object');
    if (cat) list = list.filter(b => String(b.category || '').trim() === cat);
    list = list.filter(b => matchesQuery(b));
    // Hide legacy by default (but never hide already-selected options)
    if (!showLegacy) {
      list = list.filter(b => {
        const id = parseInt(b.id, 10) || 0;
        if (id && prevSelected.has(String(id))) return true;
        return !isLegacy(b);
      });
    }

    // Prefer active services first
    list.sort((a, b) => {
      const aa = a.is_active ? 1 : 0;
      const bb = b.is_active ? 1 : 0;
      if (aa !== bb) return bb - aa;
      return String(a.name || '').localeCompare(String(b.name || ''));
    });

    if (!list.length) {
      sel.innerHTML = `<option value="" disabled>Nessun servizio trovato</option>`;
      return;
    }

    sel.innerHTML = list.map(b => {
      const id = parseInt(b.id, 10) || 0;
      const name = String(b.name || `#${id}`);
      const code = String(b.service_code || '').trim();
      const min = (b.base_days_min !== null && b.base_days_min !== undefined) ? parseInt(b.base_days_min, 10) : NaN;
      const max = (b.base_days_max !== null && b.base_days_max !== undefined) ? parseInt(b.base_days_max, 10) : NaN;
      const range = (Number.isFinite(min) && Number.isFinite(max) && max > 0) ? ` — ${min}-${max}g` : '';
      const label = code ? `${name} (${code})${range}` : `${name}${range}`;
      const selected = prevSelected.has(String(id)) ? ' selected' : '';
      const legacy = isLegacy(b) ? ' — LEGACY (non consigliato)' : '';
      const inactive = b.is_active ? '' : ' (non attivo)';
      return `<option value="${id}"${selected}>${this.escapeHtml(label + legacy + inactive)}</option>`;
    }).join('');
    this.enableClickToggleMultiSelect(sel);
    try { this.estimateWizardUpdateServiceWarnings(); } catch (_) {}
  }

  estimateWizardUpdateServiceWarnings() {
    const box = document.getElementById('planningEstimateWizardServiceWarnings');
    if (!box) return;

    const ids = this.estimateWizardGetSelectedServiceIds();
    if (!ids.length) {
      box.style.display = 'none';
      box.innerHTML = '';
      return;
    }

    const byId = new Map((this.estimateWizardCatalog || []).map(b => [parseInt(b?.id, 10) || 0, b]));
    const selected = ids.map(id => byId.get(parseInt(id, 10) || 0)).filter(Boolean);

    const isLegacy = (b) => {
      const codeUp = String(b?.service_code || '').trim().toUpperCase();
      return !!b?.legacy_combo || !!b?.is_legacy_composite || codeUp.includes('_') || codeUp === 'ACCREDIA';
    };

    const codes = selected.map(b => String(b?.service_code || '').trim().toUpperCase()).filter(Boolean);
    const legacySelected = selected.filter(isLegacy);

    const codeToId = new Map((this.estimateWizardCatalog || [])
      .map(b => [String(b?.service_code || '').trim().toUpperCase(), parseInt(b?.id, 10) || 0])
      .filter(([c, id]) => c && id > 0)
    );

    const tokenAlias = {
      'GDPR': 'PRIVACY',
      'DPO': 'PRIVACY',
      'ACCREDIA': 'ACCRED',
      'ISO17025': 'ISOIEC17025',
    };
    const explicitMap = {
      'ISO9001_HACCP': ['ISO9001', 'HACCP'],
      'ISO22000_HACCP': ['ISO22000', 'HACCP'],
      'BRC_IFS': ['BRC', 'IFS'],
      'ISO9001_PRIVACY': ['ISO9001', 'PRIVACY'],
      'ISO9001_GDPR': ['ISO9001', 'PRIVACY'],
      'ISO9001_UNI16636': ['ISO9001', 'UNI16636'],
    };

    const convertPlan = [];
    for (const b of legacySelected) {
      const legacyCode = String(b?.service_code || '').trim().toUpperCase();
      if (!legacyCode) continue;
      let targets = explicitMap[legacyCode] || null;
      if (!targets && legacyCode.includes('_')) {
        targets = legacyCode.split('_').map(x => String(x || '').trim().toUpperCase()).filter(Boolean);
      }
      targets = (targets || []).map(t => tokenAlias[t] || t);
      targets = Array.from(new Set(targets.filter(Boolean)));
      const targetIds = targets.map(t => codeToId.get(t) || 0).filter(x => x > 0);
      if (targetIds.length) {
        convertPlan.push({ legacy_id: parseInt(b?.id, 10) || 0, legacy_code: legacyCode, targets, target_ids: targetIds });
      }
    }

    const hasEmas = codes.includes('EMAS');
    const hasIso14001 = codes.includes('ISO14001');
    const iso14001Id = codeToId.get('ISO14001') || 0;

    const parts = [];
    if (legacySelected.length) {
      const legacyLabels = legacySelected.map(b => String(b?.service_code || b?.name || '').trim()).filter(Boolean);
      parts.push(`<div style="font-weight:700; margin-bottom:6px;">Attenzione: servizi LEGACY selezionati</div>`);
      parts.push(`<div class="planning-muted">Questi servizi sono combinazioni storiche e non sono consigliati per nuovi piani: <strong>${this.escapeHtml(legacyLabels.slice(0, 6).join(', '))}${legacyLabels.length > 6 ? '…' : ''}</strong></div>`);
      if (convertPlan.length) {
        const preview = convertPlan.slice(0, 4).map(x => `${x.legacy_code} → ${x.targets.join(' + ')}`).join(' | ');
        parts.push(`<div class="planning-muted" style="margin-top:6px;">Conversione proposta: ${this.escapeHtml(preview)}${convertPlan.length > 4 ? '…' : ''}</div>`);
        parts.push(`<div class="planning-actions" style="margin-top:8px; gap:8px;"><button type="button" class="btn btn-primary btn-sm" data-action="convert-legacy">Converti in servizi atomici</button></div>`);
      } else {
        parts.push(`<div class="planning-muted" style="margin-top:6px;">Conversione non disponibile (mancano servizi atomici corrispondenti nel catalogo).</div>`);
      }
    }

    if (hasEmas && !hasIso14001) {
      parts.push(`<div style="margin-top:${parts.length ? 10 : 0}px;"><div style="font-weight:700; margin-bottom:6px;">Suggerimento EMAS</div><div class="planning-muted">EMAS è un add-on: in genere conviene selezionare anche <strong>ISO 14001</strong>.</div>${iso14001Id ? `<div class="planning-actions" style="margin-top:8px; gap:8px;"><button type="button" class="btn btn-secondary btn-sm" data-action="add-iso14001">Aggiungi ISO 14001</button></div>` : ''}</div>`);
    }

    if (!parts.length) {
      box.style.display = 'none';
      box.innerHTML = '';
      return;
    }

    box.style.display = 'block';
    box.innerHTML = parts.join('');

    const convertBtn = box.querySelector('button[data-action="convert-legacy"]');
    if (convertBtn) {
      convertBtn.addEventListener('click', () => {
        try {
          const sel = document.getElementById('planningEstimateWizardServices');
          if (!sel) return;
          const current = new Set(this.estimateWizardGetSelectedServiceIds().map(v => parseInt(v, 10) || 0).filter(v => v > 0));
          for (const row of convertPlan) {
            if (row.legacy_id > 0) current.delete(row.legacy_id);
            for (const tid of row.target_ids) current.add(tid);
          }
          this.estimateWizardRenderServicesOptions();
          this.estimateWizardSetSelectedServices(Array.from(current));
          const showLegacyEl = document.getElementById('planningEstimateWizardShowLegacy');
          if (showLegacyEl) showLegacyEl.checked = false;
          this.estimateWizardRenderServicesOptions();
          this.estimateWizardUpdateDynamicAdvancedBlocks();
          this.estimateWizardUpdateServiceWarnings();
          this.estimateWizardAutoSaveSoon();
        } catch (e) {
          console.warn('[EstimateWizard] convert legacy failed:', e?.message || e);
        }
      });
    }

    const addIsoBtn = box.querySelector('button[data-action="add-iso14001"]');
    if (addIsoBtn) {
      addIsoBtn.addEventListener('click', () => {
        if (!iso14001Id) return;
        try {
          const current = new Set(this.estimateWizardGetSelectedServiceIds().map(v => parseInt(v, 10) || 0).filter(v => v > 0));
          current.add(iso14001Id);
          this.estimateWizardRenderServicesOptions();
          this.estimateWizardSetSelectedServices(Array.from(current));
          this.estimateWizardUpdateDynamicAdvancedBlocks();
          this.estimateWizardUpdateServiceWarnings();
          this.estimateWizardAutoSaveSoon();
        } catch (e) {
          console.warn('[EstimateWizard] add ISO14001 failed:', e?.message || e);
        }
      });
    }
  }

  estimateWizardCollectCompanyProfile() {
    const sector = (document.getElementById('planningEstimateWizardSector')?.value || '').trim();
    const employeesRange = (document.getElementById('planningEstimateWizardEmployeesRange')?.value || '').trim();
    const sitesCountRaw = (document.getElementById('planningEstimateWizardSitesCount')?.value || '').trim();
    const sitesCount = sitesCountRaw === '' ? null : (parseInt(sitesCountRaw, 10) || 0);
    const regulated = !!document.getElementById('planningEstimateWizardRegulated')?.checked;
    const notes = (document.getElementById('planningEstimateWizardNotes')?.value || '').trim();
    const website = (document.getElementById('planningEstimateWizardWebsite')?.value || '').trim();

    // Advanced (optional)
    const interventionType = (document.getElementById('planningEstimateWizardInterventionType')?.value || '').trim();
    const qmsMaturity = (document.getElementById('planningEstimateWizardQmsMaturity')?.value || '').trim();
    const coreProcRaw = (document.getElementById('planningEstimateWizardCoreProcessCount')?.value || '').trim();
    const deptRaw = (document.getElementById('planningEstimateWizardDepartmentsCount')?.value || '').trim();
    const prodLinesRaw = (document.getElementById('planningEstimateWizardProductLinesCount')?.value || '').trim();
    const suppliersRaw = (document.getElementById('planningEstimateWizardCriticalSuppliersCount')?.value || '').trim();
    const core_process_count = coreProcRaw === '' ? null : (parseInt(coreProcRaw, 10) || 0);
    const departments_or_units_count = deptRaw === '' ? null : (parseInt(deptRaw, 10) || 0);
    const product_service_lines_count = prodLinesRaw === '' ? null : (parseInt(prodLinesRaw, 10) || 0);
    const critical_suppliers_count = suppliersRaw === '' ? null : (parseInt(suppliersRaw, 10) || 0);

    const design_applicability = (document.getElementById('planningEstimateWizardDesignApplicability')?.value || 'unknown').trim() || 'unknown';
    const outsourcing_level = (document.getElementById('planningEstimateWizardOutsourcingLevel')?.value || '').trim();
    const it_maturity = (document.getElementById('planningEstimateWizardItMaturity')?.value || '').trim();

    const preferred_delivery_deadline = (document.getElementById('planningEstimateWizardPreferredDeliveryDeadline')?.value || '').trim();
    const blackout_periods = (document.getElementById('planningEstimateWizardBlackoutPeriods')?.value || '').trim();
    const onSitePrefRaw = (document.getElementById('planningEstimateWizardOnSitePreferenceRatio')?.value || '').trim();
    const on_site_preference_ratio = onSitePrefRaw === '' ? null : (parseInt(onSitePrefRaw, 10) || 0);

    const langsEl = document.getElementById('planningEstimateWizardLanguagesNeeded');
    const languages_needed = langsEl ? [...langsEl.selectedOptions].map(o => String(o.value || '').trim()).filter(Boolean) : [];

    const useAiEnrichment = !!document.getElementById('planningEstimateWizardUseAiEnrichment')?.checked;

    // Derived: selected on-site locations
    const on_site_locations_count = Array.isArray(this.estimateWizardSelectedLocationKeys) ? this.estimateWizardSelectedLocationKeys.length : 0;

    // ISO 14001 advanced block (only when ISO14001 is selected)
    let iso14001 = null;
    if (this.estimateWizardIsIso14001Selected()) {
      iso14001 = {
        environmental_aspects_complexity: (document.getElementById('planningEstimateWizardIso14001AspectsComplexity')?.value || '').trim() || null,
        permits_presence: (document.getElementById('planningEstimateWizardIso14001PermitsPresence')?.value || 'unknown').trim() || 'unknown',
        hazardous_substances: (document.getElementById('planningEstimateWizardIso14001HazardousSubstances')?.value || 'unknown').trim() || 'unknown',
      };
    }

    // Conditional blocks (only when shown)
    const foodWrap = document.getElementById('planningEstimateWizardFoodAdvanced');
    const food = (foodWrap && foodWrap.style.display !== 'none') ? {
      haccp_studies_count: (() => {
        const v = String(document.getElementById('planningEstimateWizardFoodHaccpStudiesCount')?.value || '').trim();
        return v === '' ? null : (parseInt(v, 10) || 0);
      })(),
      shifts_count: (() => {
        const v = String(document.getElementById('planningEstimateWizardFoodShiftsCount')?.value || '').trim();
        return v === '' ? null : (parseInt(v, 10) || 0);
      })(),
      high_risk_products: (document.getElementById('planningEstimateWizardFoodHighRiskProducts')?.value || 'unknown').trim() || 'unknown',
      production_area_size_class: (document.getElementById('planningEstimateWizardFoodProductionAreaSizeClass')?.value || '').trim() || null,
      production_area_m2: (() => {
        const v = String(document.getElementById('planningEstimateWizardFoodProductionAreaM2')?.value || '').trim();
        return v === '' ? null : (parseInt(v, 10) || 0);
      })(),
      labs_in_house: (document.getElementById('planningEstimateWizardFoodLabsInHouse')?.value || '').trim() || null,
    } : null;

    const w17025 = document.getElementById('planningEstimateWizard17025Advanced');
    const iso17025 = (w17025 && w17025.style.display !== 'none') ? {
      disciplines_json: (() => {
        const el = document.getElementById('planningEstimateWizard17025Disciplines');
        if (!el) return [];
        return [...el.selectedOptions].map(o => String(o.value || '').trim()).filter(Boolean);
      })(),
      methods_count: (() => {
        const v = String(document.getElementById('planningEstimateWizard17025MethodsCount')?.value || '').trim();
        return v === '' ? null : (parseInt(v, 10) || 0);
      })(),
      sampling_in_scope: (document.getElementById('planningEstimateWizard17025SamplingInScope')?.value || 'unknown').trim() || 'unknown',
      multi_site_lab: (document.getElementById('planningEstimateWizard17025MultiSiteLab')?.value || '').trim() || null,
    } : null;

    const ceWrap = document.getElementById('planningEstimateWizardCeAdvanced');
    const ce = (ceWrap && ceWrap.style.display !== 'none') ? {
      ce_regulation: (document.getElementById('planningEstimateWizardCeRegulation')?.value || '').trim() || null,
      ce_risk_class: (document.getElementById('planningEstimateWizardCeRiskClass')?.value || '').trim() || null,
      notified_body_required: (document.getElementById('planningEstimateWizardCeNotifiedBodyRequired')?.value || 'unknown').trim() || 'unknown',
    } : null;

    const gdprWrap = document.getElementById('planningEstimateWizardGdprAdvanced');
    const gdpr = (gdprWrap && gdprWrap.style.display !== 'none') ? {
      processing_activities_count: (() => {
        const v = String(document.getElementById('planningEstimateWizardGdprProcessingActivitiesCount')?.value || '').trim();
        return v === '' ? null : (parseInt(v, 10) || 0);
      })(),
      main_it_systems_count: (() => {
        const v = String(document.getElementById('planningEstimateWizardGdprMainItSystemsCount')?.value || '').trim();
        return v === '' ? null : (parseInt(v, 10) || 0);
      })(),
      special_categories_data: (document.getElementById('planningEstimateWizardGdprSpecialCategoriesData')?.value || 'unknown').trim() || 'unknown',
      extra_eu_transfers: (document.getElementById('planningEstimateWizardGdprExtraEuTransfers')?.value || 'unknown').trim() || 'unknown',
    } : null;

    const w231 = document.getElementById('planningEstimateWizard231Advanced');
    const odv231 = (w231 && w231.style.display !== 'none') ? {
      mog_existing: (document.getElementById('planningEstimateWizard231MogExisting')?.value || 'unknown').trim() || 'unknown',
      risk_areas_count: (() => {
        const v = String(document.getElementById('planningEstimateWizard231RiskAreasCount')?.value || '').trim();
        return v === '' ? null : (parseInt(v, 10) || 0);
      })(),
      subsidiaries_count: (() => {
        const v = String(document.getElementById('planningEstimateWizard231SubsidiariesCount')?.value || '').trim();
        return v === '' ? null : (parseInt(v, 10) || 0);
      })(),
    } : null;

    const wAccred = document.getElementById('planningEstimateWizardAccredAdvanced');
    const accred = (wAccred && wAccred.style.display !== 'none') ? {
      facilities_units_count: (() => {
        const v = String(document.getElementById('planningEstimateWizardAccredFacilitiesUnitsCount')?.value || '').trim();
        return v === '' ? null : (parseInt(v, 10) || 0);
      })(),
      regional_requirements_complexity: (document.getElementById('planningEstimateWizardAccredRegionalRequirementsComplexity')?.value || '').trim() || null,
    } : null;

    return {
      sector: sector || null,
      employees_range: employeesRange || null,
      sites_count: (sitesCountRaw === '' ? null : sitesCount),
      regulated,
      intervention_type: interventionType || null,
      qms_maturity: qmsMaturity || null,
      core_process_count,
      departments_or_units_count,
      product_service_lines_count,
      critical_suppliers_count,
      design_applicability,
      outsourcing_level: outsourcing_level || null,
      it_maturity: it_maturity || null,
      preferred_delivery_deadline: preferred_delivery_deadline || null,
      blackout_periods: blackout_periods || null,
      languages_needed,
      on_site_preference_ratio: (onSitePrefRaw === '' ? null : on_site_preference_ratio),
      on_site_locations_count,
      use_ai_enrichment: useAiEnrichment ? true : false,
      iso14001,
      food,
      iso17025,
      ce,
      gdpr,
      odv231,
      accred,
      notes: notes || null,
      website: website || null,
    };
  }

  estimateWizardEmployeesRangeFromCount(n) {
    const v = parseInt(String(n || '0'), 10) || 0;
    if (v <= 0) return '';
    if (v <= 10) return '1-10';
    if (v <= 50) return '11-50';
    if (v <= 200) return '51-200';
    if (v <= 500) return '201-500';
    return '500+';
  }

  estimateWizardSectorLabel(code) {
    const k = String(code || '').trim();
    if (!k) return '';
    const map = {
      agricoltura: "Agricoltura e Allevamento",
      alimentare: "Alimentare e Bevande",
      chimico: "Chimico e Farmaceutico",
      commercio: "Commercio all'ingrosso",
      commercio_dettaglio: "Commercio al dettaglio",
      costruzioni: "Costruzioni ed Edilizia",
      consulenza: "Consulenza e Servizi Professionali",
      energia: "Energia e Utilities",
      finanza: "Finanza e Assicurazioni",
      immobiliare: "Immobiliare",
      informatica: "Informatica e Tecnologia",
      logistica: "Logistica e Trasporti",
      manifatturiero: "Manifatturiero",
      meccanico: "Meccanico e Metalmeccanico",
      media: "Media e Comunicazione",
      moda: "Moda e Tessile",
      ristorazione: "Ristorazione e Hospitality",
      sanita: "Sanità e Servizi Sociali",
      servizi: "Servizi alle Imprese",
      turismo: "Turismo e Viaggi",
      altro: "Altro",
    };
    return map[k] || k;
  }

  estimateWizardPrefillCompanyProfileFromClient(clientTenantId) {
    const id = parseInt(String(clientTenantId || '0'), 10) || 0;
    if (!id) return;
    const c = (this.clients || []).find(x => (parseInt(x?.id || '0', 10) || 0) === id);
    if (!c) return;

    // Only fill if empty so user remains in control
    const sectorEl = document.getElementById('planningEstimateWizardSector');
    if (sectorEl && !String(sectorEl.value || '').trim()) {
      const code = String(c.settore_merceologico || '').trim();
      if (code) sectorEl.value = this.estimateWizardSectorLabel(code);
    }

    const employeesEl = document.getElementById('planningEstimateWizardEmployeesRange');
    if (employeesEl && !String(employeesEl.value || '').trim()) {
      const rng = this.estimateWizardEmployeesRangeFromCount(c.numero_dipendenti);
      if (rng) employeesEl.value = rng;
    }

    const sitesEl = document.getElementById('planningEstimateWizardSitesCount');
    if (sitesEl && !String(sitesEl.value || '').trim()) {
      const sc = (c.sites_count !== undefined && c.sites_count !== null) ? (parseInt(String(c.sites_count), 10) || 0) : 0;
      if (sc > 0) sitesEl.value = String(sc);
    }

    const websiteEl = document.getElementById('planningEstimateWizardWebsite');
    if (websiteEl && !String(websiteEl.value || '').trim()) {
      const raw = String(c.website || c.domain || '').trim();
      if (raw) {
        // Normalize best-effort: add https:// if missing scheme
        let url = raw;
        if (!/^https?:\/\//i.test(url) && !/\s/.test(url)) {
          url = 'https://' + url;
        }
        if (!/\s/.test(url)) websiteEl.value = url;
      }
    }
  }

  estimateWizardRenderEstimateBox() {
    const box = document.getElementById('planningEstimateWizardEstimateBox');
    if (!box) return;
    const data = this.estimateWizardEstimateJson;
    if (!data) return;

    const roundToStep = (value, step = 0.5) => {
      const n = this.num(value);
      if (!Number.isFinite(n)) return 0;
      const s = Number(step) > 0 ? Number(step) : 0.5;
      const r = Math.round(n / s) * s;
      // stabilize floating point
      return Math.round(r * 100) / 100;
    };

    const cpi = data.company_profile_inferred || {};
    const estimates = Array.isArray(data.estimates) ? data.estimates : [];
    const assumptions = Array.isArray(data.assumptions) ? data.assumptions : [];
    const openQ = Array.isArray(data.open_questions) ? data.open_questions : [];
    const integrationNotes = Array.isArray(data.integration_notes) ? data.integration_notes : [];
    const aiEnr = (data.ai_enrichment && typeof data.ai_enrichment === 'object') ? data.ai_enrichment : null;

    const missing = Array.isArray(cpi.missing_fields) ? cpi.missing_fields : [];
    const sources = Array.isArray(cpi.sources) ? cpi.sources : [];
    const conf = (cpi.confidence !== undefined && cpi.confidence !== null) ? Math.round(parseFloat(String(cpi.confidence)) * 100) : null;
    const missingBasic = missing.filter(x => ['employees_range', 'sites_count'].includes(String(x)));
    const missingAdv = missing.filter(x => ['intervention_type', 'qms_maturity'].includes(String(x)));

    const serviceNameById = new Map((this.estimateWizardCatalog || []).map(b => [parseInt(b.id, 10) || 0, (b.service_code ? `${b.name} (${b.service_code})` : b.name)]));

    const previewGroups = this.estimateWizardBuildActivitiesPreview(estimates, serviceNameById);
    const previewHtml = previewGroups.length
      ? `
        <div style="margin-top:10px;">
          <div style="font-weight:700; margin-bottom:6px;">Attività proposte (anteprima)</div>
          <div class="planning-muted">Queste attività verranno create nel piano quando premi <strong>Avanti</strong> (generazione per fasi dal catalogo).</div>
          <div id="planningEstimateWizardActivitiesPreviewWrap" style="margin-top:8px;">
            ${this.estimateWizardBuildActivitiesPreviewHtml(previewGroups)}
          </div>
        </div>
      `
      : '';

    box.style.display = 'block';
    box.innerHTML = `
      <div style="font-weight:700; margin-bottom:8px;">Profilo azienda (best-effort)</div>
      <div class="planning-muted">Confidenza profilo: <strong>${conf !== null ? this.escapeHtml(String(conf)) + '%' : '—'}</strong></div>
      ${missing.length ? `<div class="planning-muted" style="margin-top:6px;"><strong>Mancano dati:</strong> ${this.escapeHtml(missing.join(', '))}</div>` : `<div class="planning-muted" style="margin-top:6px;">Dati sufficienti per una stima più affidabile.</div>`}
      ${(missingBasic.length || missingAdv.length) ? `
        <div style="margin-top:10px; padding:10px; border:1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
          <div style="font-weight:700; margin-bottom:6px;">Suggerimento</div>
          <div class="planning-muted">Per aumentare la confidenza compila:</div>
          <ul class="planning-muted" style="margin:6px 0 0 18px;">
            ${missingBasic.length ? `<li>Profilo (Step 3): <strong>${this.escapeHtml(missingBasic.join(', '))}</strong></li>` : ''}
            ${missingAdv.length ? `<li>Avanzato: <strong>${this.escapeHtml(missingAdv.join(', '))}</strong></li>` : ''}
          </ul>
        </div>
      ` : ''}
      ${sources.length ? `<div class="planning-muted" style="margin-top:6px;"><strong>Fonti:</strong> ${this.escapeHtml(sources.slice(0, 3).join(' | '))}</div>` : ''}
      ${aiEnr && aiEnr.enabled ? `<div class="planning-muted" style="margin-top:6px;"><strong>AI:</strong> ${aiEnr.applied ? 'applicata (best-effort)' : 'non applicata'}${Array.isArray(aiEnr.notes) && aiEnr.notes.length ? ` — ${this.escapeHtml(aiEnr.notes.slice(0, 2).join(' | '))}` : ''}</div>` : ''}
      <div style="height: 10px;"></div>
      <div style="font-weight:700; margin-bottom:8px;">Stima per servizio</div>
      <div style="overflow:auto;">
        <table class="planning-table">
          <thead>
            <tr>
              <th>Servizio</th>
              <th style="width:120px;">Suggerite (gg)</th>
              <th style="width:110px;">On-site</th>
              <th style="width:110px;">Remoto</th>
              <th style="width:120px;">Range (gg)</th>
              <th style="width:120px;">Confidenza</th>
              <th>Motivazione</th>
            </tr>
          </thead>
          <tbody>
            ${estimates.map((e, idx) => {
              const sid = parseInt(e.service_type_id || '0', 10) || 0;
              const label = serviceNameById.get(sid) || (`#${sid}`);
              // NOTE: allow half-day overrides (0.5) in UI
              const sug = roundToStep(e.suggested_days ?? 0, 0.5);
              let onSite = roundToStep(e.suggested_on_site_days ?? 0, 0.5);
              if (onSite < 0) onSite = 0;
              if (onSite > sug) onSite = sug;
              let remote = roundToStep(e.suggested_remote_days ?? (sug - onSite), 0.5);
              if (remote < 0) remote = 0;
              if (remote > sug) remote = sug;
              // Keep sum consistent (remote is derived from suggested_days - on_site)
              remote = roundToStep(sug - onSite, 0.5);
              const min = this.num(e.min_days ?? 0);
              const max = this.num(e.max_days ?? 0);
              const c = (e.confidence !== undefined && e.confidence !== null) ? Math.round(parseFloat(String(e.confidence)) * 100) : null;
              const rat = String(e.rationale || '');
              return `
                <tr>
                  <td>${this.escapeHtml(label)}</td>
                  <td>
                    <input type="number" min="0" step="0.5"
                      data-estimate-idx="${idx}" data-field="suggested_days" value="${this.escapeAttr(String(sug))}" />
                  </td>
                  <td>
                    <input type="number" min="0" step="0.5"
                      data-estimate-idx="${idx}" data-field="suggested_on_site_days" value="${this.escapeAttr(String(onSite))}" />
                  </td>
                  <td>
                    <input type="number" min="0" step="0.5"
                      data-estimate-idx="${idx}" data-field="suggested_remote_days" value="${this.escapeAttr(String(remote))}" />
                  </td>
                  <td>${this.escapeHtml(`${min}-${max}`)}</td>
                  <td>${c !== null ? this.escapeHtml(String(c) + '%') : '—'}</td>
                  <td class="planning-muted">${this.escapeHtml(rat)}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
      ${previewHtml}
      ${integrationNotes.length ? `<div style="margin-top:10px;"><div style="font-weight:700;">Note integrazione</div><div class="planning-muted">${this.escapeHtml(integrationNotes.join(' | '))}</div></div>` : ''}
      ${assumptions.length ? `<div style="margin-top:10px;"><div style="font-weight:700;">Assunzioni</div><ul class="planning-muted" style="margin:6px 0 0 18px;">${assumptions.slice(0, 6).map(x => `<li>${this.escapeHtml(String(x))}</li>`).join('')}</ul></div>` : ''}
      ${openQ.length ? `<div style="margin-top:10px;"><div style="font-weight:700;">Domande aperte / info mancanti</div><ul class="planning-muted" style="margin:6px 0 0 18px;">${openQ.slice(0, 10).map(x => `<li>${this.escapeHtml(String(x))}</li>`).join('')}</ul></div>` : ''}
    `;

    // Bind override inputs
    box.querySelectorAll('input[data-field="suggested_days"]').forEach(inp => {
      if (inp.dataset?.cnxBound === '1') return;
      inp.addEventListener('input', () => {
        const idx = parseInt(inp.getAttribute('data-estimate-idx') || '0', 10) || 0;
        const v = roundToStep(inp.value, 0.5);
        if (!this.estimateWizardEstimateJson || !Array.isArray(this.estimateWizardEstimateJson.estimates)) return;
        const e = this.estimateWizardEstimateJson.estimates[idx];
        if (e) {
          e.suggested_days = Math.max(0, v);
          const ratio = (e.on_site_ratio !== undefined && e.on_site_ratio !== null) ? parseFloat(String(e.on_site_ratio)) : 0.0;
          const r = (!Number.isFinite(ratio) || ratio < 0) ? 0.0 : (ratio > 1 ? 1.0 : ratio);
          const onSiteDays = Math.max(0, Math.min(e.suggested_days, roundToStep(e.suggested_days * r, 0.5)));
          const remoteDays = Math.max(0, Math.round((e.suggested_days - onSiteDays) * 100) / 100);
          e.suggested_on_site_days = onSiteDays;
          e.suggested_remote_days = remoteDays;
          try {
            const onEl = box.querySelector(`input[data-field="suggested_on_site_days"][data-estimate-idx="${idx}"]`);
            if (onEl) onEl.value = String(onSiteDays);
            const remEl = box.querySelector(`input[data-field="suggested_remote_days"][data-estimate-idx="${idx}"]`);
            if (remEl) remEl.value = String(remoteDays);
          } catch (_) {}
          try { this.estimateWizardUpdateActivitiesPreview(); } catch (_) {}
          try { this.estimateWizardAutoSaveSoon(); } catch (_) {}
        }
      });
      inp.dataset.cnxBound = '1';
    });

    // Manual on-site override: updates remote immediately (remote = suggested_days - on_site)
    box.querySelectorAll('input[data-field="suggested_on_site_days"]').forEach(inp => {
      if (inp.dataset?.cnxBound === '1') return;
      inp.addEventListener('input', () => {
        const idx = parseInt(inp.getAttribute('data-estimate-idx') || '0', 10) || 0;
        if (!this.estimateWizardEstimateJson || !Array.isArray(this.estimateWizardEstimateJson.estimates)) return;
        const e = this.estimateWizardEstimateJson.estimates[idx];
        if (!e) return;
        const total = roundToStep(e.suggested_days ?? 0, 0.5);
        let on = roundToStep(inp.value, 0.5);
        if (on < 0) on = 0;
        if (on > total) on = total;
        // Planning 2026: for RECERT keep at least 0.5g on-site (audit interno + supporto audit esterno).
        try {
          const itType = String(this.estimateWizardCollectCompanyProfile()?.intervention_type || '').trim().toLowerCase();
          if (itType === 'recertification' && total > 0) {
            const minOn = Math.min(total, 0.5);
            if (on < minOn) on = minOn;
          }
        } catch (_) {}
        const rem = roundToStep(total - on, 0.5);
        e.suggested_on_site_days = on;
        e.suggested_remote_days = rem;
        e.on_site_ratio = total > 0 ? Math.max(0, Math.min(1, on / total)) : 0.0;
        try {
          const remEl = box.querySelector(`input[data-field="suggested_remote_days"][data-estimate-idx="${idx}"]`);
          if (remEl) remEl.value = String(rem);
        } catch (_) {}
        try { this.estimateWizardUpdateActivitiesPreview(); } catch (_) {}
        try { this.estimateWizardAutoSaveSoon(); } catch (_) {}
      });
      inp.dataset.cnxBound = '1';
    });

    // Manual remote override: updates on-site immediately (on_site = suggested_days - remote)
    box.querySelectorAll('input[data-field="suggested_remote_days"]').forEach(inp => {
      if (inp.dataset?.cnxBound === '1') return;
      inp.addEventListener('input', () => {
        const idx = parseInt(inp.getAttribute('data-estimate-idx') || '0', 10) || 0;
        if (!this.estimateWizardEstimateJson || !Array.isArray(this.estimateWizardEstimateJson.estimates)) return;
        const e = this.estimateWizardEstimateJson.estimates[idx];
        if (!e) return;
        const total = roundToStep(e.suggested_days ?? 0, 0.5);
        let rem = roundToStep(inp.value, 0.5);
        if (rem < 0) rem = 0;
        if (rem > total) rem = total;
        let on = roundToStep(total - rem, 0.5);
        // Planning 2026: for RECERT keep at least 0.5g on-site (audit interno + supporto audit esterno).
        try {
          const itType = String(this.estimateWizardCollectCompanyProfile()?.intervention_type || '').trim().toLowerCase();
          if (itType === 'recertification' && total > 0) {
            const minOn = Math.min(total, 0.5);
            if (on < minOn) {
              on = minOn;
              rem = roundToStep(total - on, 0.5);
              inp.value = String(rem);
            }
          }
        } catch (_) {}
        e.suggested_remote_days = rem;
        e.suggested_on_site_days = on;
        e.on_site_ratio = total > 0 ? Math.max(0, Math.min(1, on / total)) : 0.0;
        try {
          const onEl = box.querySelector(`input[data-field="suggested_on_site_days"][data-estimate-idx="${idx}"]`);
          if (onEl) onEl.value = String(on);
        } catch (_) {}
        try { this.estimateWizardUpdateActivitiesPreview(); } catch (_) {}
        try { this.estimateWizardAutoSaveSoon(); } catch (_) {}
      });
      inp.dataset.cnxBound = '1';
    });
  }

  estimateWizardDefaultPhasesTemplate() {
    return [
      { phase_key: 'kickoff', label: 'Kickoff / Pianificazione', default_activity_type: 'call', share_of_total: 0.06 },
      { phase_key: 'gap_analysis', label: 'Analisi gap / Analisi contesto', default_activity_type: 'remote', share_of_total: 0.16 },
      { phase_key: 'documentation', label: 'Documentazione (manuale/procedure/moduli)', default_activity_type: 'remote', share_of_total: 0.30 },
      { phase_key: 'implementation', label: 'Implementazione / Affiancamento', default_activity_type: 'onsite', share_of_total: 0.22 },
      { phase_key: 'internal_audit', label: 'Audit interno', default_activity_type: 'onsite', share_of_total: 0.10 },
      { phase_key: 'management_review', label: 'Riesame di direzione', default_activity_type: 'call', share_of_total: 0.06 },
      { phase_key: 'cert_support', label: 'Supporto verifica / certificazione', default_activity_type: 'onsite', share_of_total: 0.10 },
    ];
  }

  estimateWizardAllocateHalfUnits(totalUnits, phases) {
    const tu = Math.max(0, parseInt(totalUnits, 10) || 0);
    const ph = Array.isArray(phases) ? phases : [];
    if (!ph.length) return [];

    const eligibleIdx = [];
    const weights = ph.map((p, idx) => {
      const act = String(p?.default_activity_type || 'remote').toLowerCase();
      if (act === 'call' || act === 'communication') return 0;
      eligibleIdx.push(idx);
      const w = (p && typeof p === 'object' && p.share_of_total !== undefined) ? parseFloat(String(p.share_of_total)) : 0;
      return (Number.isFinite(w) && w > 0) ? w : 0;
    });

    if (!eligibleIdx.length) return new Array(ph.length).fill(0);

    let sumW = eligibleIdx.reduce((a, i) => a + (weights[i] || 0), 0);
    if (!sumW) {
      eligibleIdx.forEach(i => { weights[i] = 1; });
      sumW = eligibleIdx.length;
    }

    const units = new Array(ph.length).fill(0);
    const remainders = {};
    let used = 0;
    eligibleIdx.forEach((i) => {
      const raw = sumW ? (tu * (weights[i] || 0)) / sumW : 0;
      const u = Math.floor(raw);
      units[i] = u;
      remainders[i] = raw - u;
      used += u;
    });

    let left = tu - used;
    if (left > 0) {
      const idxs = eligibleIdx.slice().sort((a, b) => (remainders[b] || 0) - (remainders[a] || 0));
      let k = 0;
      while (left > 0 && idxs.length) {
        const i = idxs[k % idxs.length];
        units[i] = (units[i] || 0) + 1;
        left--;
        k++;
      }
    }
    return units;
  }

  estimateWizardBuildActivitiesPreview(estimates, serviceNameById) {
    const est = Array.isArray(estimates) ? estimates : [];
    const nameById = serviceNameById instanceof Map ? serviceNameById : new Map();
    const catalogById = new Map((this.estimateWizardCatalog || []).map(b => [parseInt(b?.id, 10) || 0, b]));

    let interventionType = '';
    try {
      interventionType = String(this.estimateWizardCollectCompanyProfile()?.intervention_type || '').trim().toLowerCase();
    } catch (_) {}
    const isRecert = interventionType === 'recertification';
    const forcedOnsitePhaseKeys = isRecert ? new Set(['internal_audit', 'cert_support', 'external_audit_support']) : null;

    const groups = [];
    for (const e of est) {
      const sid = parseInt(e?.service_type_id || '0', 10) || 0;
      if (!sid) continue;
      const days = this.num(e?.suggested_days ?? 0);
      if (!Number.isFinite(days) || days <= 0) continue;

      const b = catalogById.get(sid) || null;
      const code = String(b?.service_code || '').trim();
      const svcLabel = nameById.get(sid) || (code ? code : `#${sid}`);

      // Prefer server-provided preview phases (coherent with items_generate_from_estimate.php workplans)
      let phases = Array.isArray(e?.preview_phases) ? e.preview_phases : null;
      if (!phases || !phases.length) phases = Array.isArray(b?.default_phases) ? b.default_phases : null;
      if (!phases || !phases.length) phases = this.estimateWizardDefaultPhasesTemplate();

      const norm = [];
      for (const p of phases) {
        const label = String(p?.label || '').trim();
        if (!label) continue;
        const phase_key = String(p?.phase_key || 'phase').trim() || 'phase';
        const poRaw = (p && typeof p === 'object' && p.phase_order !== undefined) ? parseInt(String(p.phase_order), 10) : NaN;
        const phase_order = Number.isFinite(poRaw) ? poRaw : null;
        const activity_type = String(p?.default_activity_type || 'remote').trim().toLowerCase();
        const valid = ['onsite', 'remote', 'call', 'communication', 'travel'];
        const act = valid.includes(activity_type) ? activity_type : 'remote';
        const share = parseFloat(String(p?.share_of_total ?? 0)) || 0;
        norm.push({ phase_key, phase_order, label, default_activity_type: act, share_of_total: (share > 0 ? share : 0) });
      }
      if (!norm.length) {
        const d = this.estimateWizardDefaultPhasesTemplate();
        for (const p of d) norm.push({ ...p });
      }
      // Ensure deterministic phase order for preview (matches server workplans as much as possible)
      norm.sort((a, b) => ((a.phase_order ?? 99) - (b.phase_order ?? 99)));

      const totalU = Math.max(1, Math.round(days * 2));
      const us = this.estimateWizardAllocateHalfUnits(totalU, norm);

      // Planning 2026: RECERT must include internal audit + external audit support (>=0.5d each), best-effort.
      if (isRecert) {
        const keyByIdx = norm.map(p => String(p?.phase_key || '').trim().toLowerCase());
        const isDayBased = (idx) => {
          const act = String(norm[idx]?.default_activity_type || 'remote').toLowerCase();
          return !(act === 'call' || act === 'communication');
        };
        const mustSupport = keyByIdx.findIndex(k => k === 'external_audit_support' || k === 'cert_support');
        const mustInternal = keyByIdx.findIndex(k => k === 'internal_audit');
        const mandatory = [];
        if (mustSupport >= 0 && isDayBased(mustSupport)) mandatory.push(mustSupport);
        if (mustInternal >= 0 && isDayBased(mustInternal) && totalU >= 2) mandatory.push(mustInternal);

        const donors = () => {
          const out = [];
          for (let i = 0; i < us.length; i++) {
            if (!isDayBased(i)) continue;
            const u = parseInt(us[i] || '0', 10) || 0;
            if (u <= 0) continue;
            const k = keyByIdx[i];
            if (k === 'internal_audit' || k === 'external_audit_support' || k === 'cert_support') continue;
            const share = parseFloat(String(norm[i]?.share_of_total ?? 0)) || 0;
            out.push({ i, u, share });
          }
          out.sort((a, b) => (a.share - b.share) || (b.u - a.u));
          return out;
        };

        for (const mi of mandatory) {
          const cur = parseInt(us[mi] || '0', 10) || 0;
          if (cur >= 1) continue;
          const ds = donors();
          if (!ds.length) break;
          const d = ds[0];
          us[d.i] = (parseInt(us[d.i] || '0', 10) || 0) - 1;
          us[mi] = (parseInt(us[mi] || '0', 10) || 0) + 1;
        }
      }

      // Respect on-site vs remote override by re-typing phases (onsite/remote) to best match target.
      let targetOnSiteDays = this.num(e?.suggested_on_site_days ?? 0);
      if (!Number.isFinite(targetOnSiteDays) || targetOnSiteDays < 0) targetOnSiteDays = 0;
      if (targetOnSiteDays > days) targetOnSiteDays = days;
      let targetOnSiteU = Math.round(targetOnSiteDays * 2);

      const conv = [];
      let convTotalU = 0;
      for (let i = 0; i < norm.length; i++) {
        const t = String(norm[i]?.default_activity_type || 'remote');
        if (t === 'onsite' || t === 'remote') {
          const u = parseInt(us[i] || '0', 10) || 0;
          conv.push({ i, u, def: t, k: String(norm[i]?.phase_key || '').trim().toLowerCase() });
          convTotalU += u;
        }
      }
      if (targetOnSiteU < 0) targetOnSiteU = 0;
      if (targetOnSiteU > convTotalU) targetOnSiteU = convTotalU;
      if (isRecert && forcedOnsitePhaseKeys) {
        // Ensure target includes forced on-site phases (audit interno + supporto audit esterno)
        const forcedU = conv.reduce((acc, x) => acc + (forcedOnsitePhaseKeys.has(x.k) ? (x.u || 0) : 0), 0);
        if (forcedU > targetOnSiteU) targetOnSiteU = forcedU;
      }

      const onsiteSet = new Set();
      if (conv.length) {
        if (conv.length <= 16) {
          let bestMask = 0;
          let bestCost = null;
          const maxMask = 1 << conv.length;
          for (let mask = 0; mask < maxMask; mask++) {
            let sum = 0;
            let flips = 0;
            for (let j = 0; j < conv.length; j++) {
              const isOn = ((mask >> j) & 1) === 1;
              if (isOn) sum += conv[j].u;
              const desired = isOn ? 'onsite' : 'remote';
              if (desired !== conv[j].def) flips++;
            }
            const diff = Math.abs(sum - targetOnSiteU);
            const cost = diff * 1000 + flips;
            if (bestCost === null || cost < bestCost) {
              bestCost = cost;
              bestMask = mask;
              if (diff === 0 && flips === 0) break;
            }
          }
          for (let j = 0; j < conv.length; j++) {
            if (((bestMask >> j) & 1) === 1) onsiteSet.add(conv[j].i);
          }
        } else {
          // Greedy fallback for unusual custom phase lists (keeps perf stable)
          const curOn = conv.filter(x => x.def === 'onsite');
          const curRemote = conv.filter(x => x.def !== 'onsite');
          let curOnU = curOn.reduce((a, x) => a + x.u, 0);
          // Start from default
          for (const x of curOn) onsiteSet.add(x.i);
          if (curOnU > targetOnSiteU) {
            curOn.sort((a, b) => a.u - b.u);
            for (const x of curOn) {
              if (curOnU <= targetOnSiteU) break;
              onsiteSet.delete(x.i);
              curOnU -= x.u;
            }
          } else if (curOnU < targetOnSiteU) {
            curRemote.sort((a, b) => a.u - b.u);
            for (const x of curRemote) {
              if (curOnU >= targetOnSiteU) break;
              onsiteSet.add(x.i);
              curOnU += x.u;
            }
          }
        }
      }
      if (isRecert && forcedOnsitePhaseKeys && conv.length) {
        // Force on-site for key phases, without blowing up the target (remove other phases if needed).
        const forced = conv.filter(x => forcedOnsitePhaseKeys.has(x.k)).map(x => x.i);
        forced.forEach(i => onsiteSet.add(i));
        const sumOnU = () => conv.reduce((acc, x) => acc + (onsiteSet.has(x.i) ? (x.u || 0) : 0), 0);
        let curOnU = sumOnU();
        if (curOnU > targetOnSiteU) {
          const removable = conv
            .filter(x => onsiteSet.has(x.i) && !forcedOnsitePhaseKeys.has(x.k))
            .slice()
            .sort((a, b) => (a.u || 0) - (b.u || 0));
          for (const x of removable) {
            if (curOnU <= targetOnSiteU) break;
            onsiteSet.delete(x.i);
            curOnU -= (x.u || 0);
          }
        } else if (curOnU < targetOnSiteU) {
          const addable = conv
            .filter(x => !onsiteSet.has(x.i) && !forcedOnsitePhaseKeys.has(x.k))
            .slice()
            .sort((a, b) => (a.u || 0) - (b.u || 0));
          for (const x of addable) {
            if (curOnU >= targetOnSiteU) break;
            onsiteSet.add(x.i);
            curOnU += (x.u || 0);
          }
        }
      }

      // Merge 0-unit day-based phases into adjacent ones (so preview stays faithful to server generation).
      const finalActByIdx = {};
      for (let i = 0; i < norm.length; i++) {
        let act = String(norm[i]?.default_activity_type || 'remote').toLowerCase();
        if (act === 'onsite' || act === 'remote') {
          act = onsiteSet.has(i) ? 'onsite' : 'remote';
        }
        const pk = String(norm[i]?.phase_key || '').trim().toLowerCase();
        if (isRecert && forcedOnsitePhaseKeys && forcedOnsitePhaseKeys.has(pk) && (act === 'onsite' || act === 'remote')) {
          act = 'onsite';
        }
        finalActByIdx[i] = act;
      }
      const mergedLabelsByIdx = {};
      for (let i = 0; i < norm.length; i++) {
        const u = parseInt(us[i] || '0', 10) || 0;
        const act = String(finalActByIdx[i] || 'remote');
        if (act === 'call' || act === 'communication') continue;
        if (u > 0) continue;
        const label = String(norm[i]?.label || '').trim();
        if (!label) continue;

        // Find merge target (prefer adjacent with same act).
        let target = null;
        for (let j = i - 1; j >= 0; j--) {
          const ju = parseInt(us[j] || '0', 10) || 0;
          if (ju <= 0) continue;
          const ja = String(finalActByIdx[j] || 'remote');
          if (ja === 'call' || ja === 'communication') continue;
          if (ja === act) { target = j; break; }
        }
        if (target === null) {
          for (let j = i + 1; j < norm.length; j++) {
            const ju = parseInt(us[j] || '0', 10) || 0;
            if (ju <= 0) continue;
            const ja = String(finalActByIdx[j] || 'remote');
            if (ja === 'call' || ja === 'communication') continue;
            if (ja === act) { target = j; break; }
          }
        }
        if (target === null) {
          for (let j = i - 1; j >= 0; j--) {
            const ju = parseInt(us[j] || '0', 10) || 0;
            if (ju <= 0) continue;
            const ja = String(finalActByIdx[j] || 'remote');
            if (ja === 'call' || ja === 'communication') continue;
            target = j;
            break;
          }
        }
        if (target === null) {
          for (let j = i + 1; j < norm.length; j++) {
            const ju = parseInt(us[j] || '0', 10) || 0;
            if (ju <= 0) continue;
            const ja = String(finalActByIdx[j] || 'remote');
            if (ja === 'call' || ja === 'communication') continue;
            target = j;
            break;
          }
        }
        if (target === null) continue;
        if (!mergedLabelsByIdx[target]) mergedLabelsByIdx[target] = [];
        mergedLabelsByIdx[target].push(label);
      }

      const items = [];
      for (let i = 0; i < norm.length; i++) {
        const actRaw = norm[i].default_activity_type;
        const share = parseFloat(String(norm[i]?.share_of_total ?? 0)) || 0;
        let act = String(finalActByIdx[i] || actRaw || 'remote');

        if (act === 'call' || act === 'communication') {
          // Call phases: hours/minutes do NOT reduce day-based days (best-effort preview).
          let minutesTotal = Math.round(days * Math.max(0, share) * 240);
          const pk = String(norm[i]?.phase_key || '').toLowerCase();
          const minMinutes = (pk === 'kickoff' || pk === 'management_review') ? 30 : 0;
          if (minutesTotal < minMinutes) minutesTotal = minMinutes;
          if (minutesTotal <= 0) continue;

          // Preview as compact "30m/60m x N"
          const parts = [];
          let left = minutesTotal;
          while (left >= 60) { parts.push(60); left -= 60; }
          if (left >= 30) { parts.push(30); left -= 30; }
          if (!parts.length) parts.push(30);
          const labelDur = (parts.length === 1) ? `${parts[0]}m` : `${parts.length}x${parts[0]}m`;

          items.push({
            phase_key: norm[i].phase_key,
            label: norm[i].label,
            activity_type: act,
            duration_text: labelDur,
            days: 0,
            description: `[Servizio: ${code || svcLabel}] Fase: ${norm[i].label}`,
          });
          continue;
        }

        const u = parseInt(us[i] || '0', 10) || 0;
        const d = u / 2;
        if (d <= 0) continue;
        let label = String(norm[i]?.label || '').trim();
        if (mergedLabelsByIdx[i] && Array.isArray(mergedLabelsByIdx[i]) && mergedLabelsByIdx[i].length) {
          const extras = mergedLabelsByIdx[i].map(x => String(x || '').trim()).filter(Boolean);
          if (extras.length) label = `${label} + ${extras.join(' + ')}`;
        }
        const desc = `[Servizio: ${code || svcLabel}] Fase: ${label}`;
        items.push({
          phase_key: norm[i].phase_key,
          label,
          activity_type: act,
          days: Math.round(d * 100) / 100,
          duration_text: `${this.formatHalfDayDays(d)}g`,
          description: desc,
        });
      }

      groups.push({
        service_type_id: sid,
        service_label: svcLabel,
        total_days: Math.round(days * 100) / 100,
        items,
      });
    }
    return groups;
  }

  estimateWizardBuildActivitiesPreviewHtml(groups) {
    const gs = Array.isArray(groups) ? groups : [];
    return gs.map(g => {
      const items = Array.isArray(g.items) ? g.items : [];
      if (!items.length) return '';
      return `
        <details open style="margin-top:8px; padding:10px; border:1px solid var(--color-gray-200); border-radius: var(--radius-md); background: var(--color-gray-50);">
          <summary style="cursor:pointer; font-weight:700;">
            ${this.escapeHtml(String(g.service_label || 'Servizio'))} — ${this.escapeHtml(String(g.total_days ?? 0))}g
          </summary>
          <div style="margin-top:10px; overflow:auto;">
            <table class="planning-table">
              <thead>
                <tr>
                  <th>Fase</th>
                  <th style="width:120px;">Tipo</th>
                  <th style="width:130px;">Durata</th>
                </tr>
              </thead>
              <tbody>
                ${items.map(it => `
                  <tr>
                    <td>${this.escapeHtml(String(it.label || ''))}</td>
                    <td class="planning-muted">${this.escapeHtml(String(it.activity_type || 'remote').toUpperCase())}</td>
                    <td class="planning-muted">${this.escapeHtml(String(it.duration_text || (it.days ? it.days : '—')))}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>
          </div>
        </details>
      `;
    }).filter(Boolean).join('');
  }

  estimateWizardUpdateActivitiesPreview() {
    const wrap = document.getElementById('planningEstimateWizardActivitiesPreviewWrap');
    if (!wrap) return;
    if (!this.estimateWizardEstimateJson || !Array.isArray(this.estimateWizardEstimateJson.estimates)) {
      wrap.innerHTML = '';
      return;
    }
    const serviceNameById = new Map((this.estimateWizardCatalog || []).map(b => [parseInt(b?.id, 10) || 0, (b?.service_code ? `${b.name} (${b.service_code})` : b?.name)]));
    const groups = this.estimateWizardBuildActivitiesPreview(this.estimateWizardEstimateJson.estimates, serviceNameById);
    wrap.innerHTML = this.estimateWizardBuildActivitiesPreviewHtml(groups);
  }

  async estimateWizardRunEstimate() {
    try {
      const client = parseInt(document.getElementById('planningEstimateWizardClient')?.value || '0', 10) || 0;
      const serviceIds = this.estimateWizardGetSelectedServiceIds();
      if (!client) return this.toast('Seleziona un’azienda cliente', 'error');
      if (!serviceIds.length) return this.toast('Seleziona almeno 1 servizio/norma', 'error');

      const companyProfile = this.estimateWizardCollectCompanyProfile();
      if (!companyProfile?.intervention_type) {
        try {
          const adv = document.getElementById('planningEstimateWizardAdvancedDetails');
          if (adv) adv.open = true;
        } catch (_) {}
        return this.toast('Seleziona “Tipo intervento” (obbligatorio) prima di calcolare la stima', 'error');
      }
      try { await this.estimateWizardSaveDraftNow({ clientId: client, serviceIds, companyProfile, includeEstimate: false, reason: 'before_estimate' }); } catch (_) {}
      this.startProgress('Stima giornate…');

      // Optional: use document intelligence (doc_profile_id) to improve estimate (best-effort)
      const useDocEvidence = !!document.getElementById('planningEstimateWizardUseDocEvidence')?.checked;
      const docProfileId = useDocEvidence ? (parseInt(String(this.estimateWizardDocProfileId || '0'), 10) || 0) : 0;

      const res = await this.apiFetch('consulting_plans/estimate_days.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_tenant_id: client,
          service_type_ids: serviceIds,
          company_profile: companyProfile,
          doc_profile_id: docProfileId > 0 ? docProfileId : null,
        }),
      });

      const data = res?.data || null;
      if (!data || !Array.isArray(data.estimates)) throw new Error('Stima non valida (manca estimates)');

      this.estimateWizardEstimateJson = {
        kind: 'service_estimate_v1',
        generated_at: new Date().toISOString(),
        client_tenant_id: client,
        service_type_ids: serviceIds,
        input_company_profile: companyProfile,
        meta: {
          doc_profile_id: docProfileId > 0 ? docProfileId : null,
        },
        ...data,
      };

      this.estimateWizardRenderEstimateBox();
      try { await this.estimateWizardSaveDraftNow({ clientId: client, serviceIds, companyProfile, includeEstimate: true, reason: 'after_estimate' }); } catch (_) {}
      this.toast('Stima calcolata', 'success');
      this.renderEstimateWizard();
    } catch (e) {
      console.error('[EstimateWizard] estimate error:', e);
      this.toast(e.message || 'Errore stima giornate', 'error');
    } finally {
      this.finishProgress();
    }
  }

  estimateWizardRenderSummary() {
    const box = document.getElementById('planningEstimateWizardSummary');
    if (!box) return;
    const est = this.estimateWizardEstimateJson;
    if (!est) {
      box.style.display = 'none';
      box.innerHTML = '';
      return;
    }
    const estimates = Array.isArray(est.estimates) ? est.estimates : [];
    const serviceNameById = new Map((this.estimateWizardCatalog || []).map(b => [parseInt(b.id, 10) || 0, (b.service_code ? `${b.name} (${b.service_code})` : b.name)]));
    const lines = estimates.map(e => {
      const sid = parseInt(e.service_type_id || '0', 10) || 0;
      const label = serviceNameById.get(sid) || (`#${sid}`);
      const d = Math.round(this.num(e.suggested_days) * 100) / 100;
      return `${label}: ${d}g`;
    });
    box.style.display = 'block';
    box.innerHTML = `
      <div style="font-weight:700; margin-bottom:6px;">Riepilogo stima</div>
      <div class="planning-muted">${this.escapeHtml(lines.join(' | '))}</div>
    `;
  }

  async estimateWizardCreatePlanAndGenerate() {
    const nextBtn = document.getElementById('planningEstimateWizardNextBtn');
    const prevLabel = nextBtn ? nextBtn.textContent : '';
    if (nextBtn) { nextBtn.disabled = true; nextBtn.textContent = 'Creazione...'; }
    try {
      const client = parseInt(document.getElementById('planningEstimateWizardClient')?.value || '0', 10) || 0;
      if (!client) throw new Error('Azienda cliente mancante');
      if (!this.estimateWizardEstimateJson) throw new Error('Stima mancante');

      // Planning 2026: intervention type is required (must be persisted in estimate_json)
      try {
        const companyProfile = this.estimateWizardCollectCompanyProfile();
        if (!companyProfile?.intervention_type) {
          const adv = document.getElementById('planningEstimateWizardAdvancedDetails');
          if (adv) adv.open = true;
          throw new Error('Tipo intervento obbligatorio');
        }
      } catch (e) {
        if (String(e?.message || '').includes('Tipo intervento')) throw e;
      }

      const titleRaw = (document.getElementById('planningEstimateWizardTitle')?.value || '').trim();
      const periodStart = this.todayLocalDate();
      const periodEndEl = document.getElementById('planningEstimateWizardPeriodEnd');
      const periodEndInput = (periodEndEl?.value || '').trim();
      // Planning 2026: if empty, keep NULL (backend will use a compact default window for schedule proposal).
      const periodEnd = periodEndInput || null;

      // Fallback title from services
      const estimates = Array.isArray(this.estimateWizardEstimateJson.estimates) ? this.estimateWizardEstimateJson.estimates : [];
      const serviceNameById = new Map((this.estimateWizardCatalog || []).map(b => [parseInt(b.id, 10) || 0, (b.service_code ? `${b.name}` : b.name)]));
      const svcNames = estimates.map(e => {
        const sid = parseInt(e.service_type_id || '0', 10) || 0;
        return serviceNameById.get(sid) || `Servizio ${sid}`;
      }).filter(Boolean);
      const title = (titleRaw || svcNames.join(' + ') || 'Piano consulenza').slice(0, 255);

      const notesLines = estimates.map(e => {
        const sid = parseInt(e.service_type_id || '0', 10) || 0;
        const nm = serviceNameById.get(sid) || `Servizio ${sid}`;
        const d = Math.round(this.num(e.suggested_days) * 100) / 100;
        const rng = `${parseInt(e.min_days || '0', 10) || 0}-${parseInt(e.max_days || '0', 10) || 0}`;
        return `- ${nm}: ${d}g (range ${rng})`;
      });
      const openQ = Array.isArray(this.estimateWizardEstimateJson.open_questions) ? this.estimateWizardEstimateJson.open_questions : [];
      const notes = [
        'Stima giornate (best-effort) per servizi/norme:',
        ...notesLines,
        openQ.length ? '' : '',
        openQ.length ? 'Domande aperte:' : '',
        ...openQ.slice(0, 10).map(q => `- ${q}`),
      ].filter(Boolean).join('\n').slice(0, 4000);

      // Plan scopes (best-effort): store selected services + estimated days as first-class rows when storage is available.
      const scopes = estimates.map((e, idx) => {
        const sid = parseInt(e?.service_type_id || '0', 10) || 0;
        const d = (e?.suggested_days === null || e?.suggested_days === undefined || e?.suggested_days === '')
          ? null
          : (parseFloat(String(e.suggested_days)) || 0);
        return {
          activity_type_id: sid,
          estimated_days: d,
          planned_days_override: null,
          notes: null,
          sort_order: idx,
        };
      }).filter(s => (parseInt(s.activity_type_id || '0', 10) || 0) > 0);

      // Persist selected client locations into estimate_json (safe namespace).
      try {
        const selectedLocations = this.estimateWizardGetSelectedLocationsSnapshot();
        if (this.estimateWizardEstimateJson && typeof this.estimateWizardEstimateJson === 'object') {
          if (!this.estimateWizardEstimateJson.meta || typeof this.estimateWizardEstimateJson.meta !== 'object') {
            this.estimateWizardEstimateJson.meta = {};
          }
          this.estimateWizardEstimateJson.meta.client_locations = {
            selected: selectedLocations,
            selected_count: selectedLocations.length,
            saved_at: new Date().toISOString(),
          };
          // Persist doc_profile_id (best-effort) so backend can adapt phases/items later
          try {
            const useDocEvidence = !!document.getElementById('planningEstimateWizardUseDocEvidence')?.checked;
            const docProfileId = useDocEvidence ? (parseInt(String(this.estimateWizardDocProfileId || '0'), 10) || 0) : 0;
            this.estimateWizardEstimateJson.meta.doc_profile_id = (docProfileId > 0 ? docProfileId : null);
          } catch (_) {}
        }
      } catch (_) {}

      this.startProgress('Creazione piano…');
      const created = await this.apiFetch('consulting_plans/create.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_tenant_id: client,
          title,
          status: 'draft',
          period_start: periodStart || null,
          period_end: periodEnd || null,
          notes: notes || null,
          estimate_json: this.estimateWizardEstimateJson,
          scopes: scopes,
        }),
      });
      const planId = created?.data?.id;
      if (!planId) throw new Error('Creazione piano fallita (id mancante)');

      // Link estimate session -> plan (best-effort, migration 57 optional)
      try { await this.estimateWizardLinkSessionToPlan({ clientId: client, planId: parseInt(planId, 10) || 0 }); } catch (_) {}
      try { this.estimateWizardClearLocalDraft(client); } catch (_) {}

      this.startProgress('Generazione attività…');
      await this.apiFetch('consulting_plans/items_generate_from_estimate.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          plan_id: parseInt(planId, 10),
          estimate_json: this.estimateWizardEstimateJson,
          replace_existing: false,
        }),
      });

      this.toast('Piano creato e attività generate', 'success');
      this.closeEstimateWizard();
      await this.loadPlans();
      await this.selectPlan(planId);
      try { this.setPlanningActiveTab('consultants'); } catch (_) {}
    } catch (e) {
      console.error('[EstimateWizard] create error:', e);
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Errore creazione piano';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.finishProgress();
      if (nextBtn) { nextBtn.disabled = false; nextBtn.textContent = prevLabel || 'Crea piano e genera attività'; }
    }
  }

  wizardResetObjectiveOverrides() {
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    setVal('planningWizardObjectiveWeight', '');
    setVal('planningWizardObjectiveCallEvery', '');
    setVal('planningWizardObjectiveCallDur', '');
  }

  wizardGetObjectiveOverrides() {
    const weightRaw = (document.getElementById('planningWizardObjectiveWeight')?.value || '').trim();
    const callEveryRaw = (document.getElementById('planningWizardObjectiveCallEvery')?.value || '').trim();
    const callDurRaw = (document.getElementById('planningWizardObjectiveCallDur')?.value || '').trim();
    const weight = weightRaw === '' ? null : (parseFloat(weightRaw) || 0);
    const callEvery = callEveryRaw === '' ? null : (parseInt(callEveryRaw, 10) || 0);
    const callDur = callDurRaw === '' ? null : (parseInt(callDurRaw, 10) || 0);
    return { weight, callEvery, callDur };
  }

  async wizardLoadActivityTypesForClient(clientTenantId) {
    const sel = document.getElementById('planningWizardObjectiveType');
    if (!clientTenantId) {
      this.wizardClientActivityTypes = [];
      this.wizardClientActivityTypeById = new Map();
      this.wizardClientActivityTypeIdByName = new Map();
      this.wizardLoadedClientTenantId = 0;
      if (sel) {
        sel.innerHTML = `<option value="">Seleziona prima l’azienda...</option>`;
        sel.value = '';
        sel.disabled = true;
      }
      return;
    }

    if (this.wizardLoadedClientTenantId === clientTenantId && this.wizardClientActivityTypes.length) {
      if (sel) sel.disabled = false;
      return;
    }

    try {
      const res = await this.apiFetch(`consulting_plans/activity_types.php?client_tenant_id=${encodeURIComponent(String(clientTenantId))}`, {
        method: 'GET',
        json: true,
      });
      const types = res.data?.types;
      this.wizardClientActivityTypes = Array.isArray(types) ? types : [];
      this.wizardClientActivityTypeById = new Map();
      this.wizardClientActivityTypeIdByName = new Map();
      for (const t of this.wizardClientActivityTypes) {
        const base = t?.base || null;
        const eff = t?.effective || null;
        const hasOverride = !!t?.has_override;
        const id = parseInt(base?.id || eff?.id || '0', 10) || 0;
        const name = String(eff?.name || base?.name || '').trim();
        if (!id || !name) continue;
        this.wizardClientActivityTypeById.set(id, { base, effective: eff, hasOverride });
        this.wizardClientActivityTypeIdByName.set(name.toLowerCase(), id);
      }
      this.wizardLoadedClientTenantId = clientTenantId;
      if (sel) sel.disabled = false;
    } catch (e) {
      console.error('[PlanningWizard] load activity types error:', e);
      this.wizardClientActivityTypes = [];
      this.wizardClientActivityTypeById = new Map();
      this.wizardClientActivityTypeIdByName = new Map();
      this.wizardLoadedClientTenantId = clientTenantId;
      if (sel) {
        sel.innerHTML = `<option value="">Errore caricamento catalogo</option>`;
        sel.value = '';
        sel.disabled = true;
      }
      this.toast('Errore caricamento catalogo servizi', 'error');
    }
  }

  wizardRenderObjectiveOptions() {
    const sel = document.getElementById('planningWizardObjectiveType');
    if (!sel) return;
    const prev = sel.value;
    const active = (this.wizardClientActivityTypes || [])
      .map(x => x?.effective || x?.base || null)
      .filter(x => x && (x.is_active === true || x.is_active === 1));

    if (!active.length) {
      sel.innerHTML = `<option value="">Catalogo vuoto (usa “Catalogo servizi”)</option>`;
      sel.value = '';
      sel.disabled = true;
      return;
    }

    sel.innerHTML = `<option value="">Seleziona...</option>` + active.map(t => {
      const id = parseInt(t.id || '0', 10) || 0;
      const name = String(t.name || '').trim();
      return `<option value="${id}">${this.escapeHtml(name)}</option>`;
    }).join('');
    sel.disabled = false;
    sel.value = prev;
  }

  wizardApplyObjectiveDefaultsFromSelection() {
    const objTypeId = parseInt(document.getElementById('planningWizardObjectiveType')?.value || '0', 10) || 0;
    const t = objTypeId ? (this.wizardClientActivityTypeById.get(objTypeId) || null) : null;
    const eff = t?.effective || null;
    const base = t?.base || null;
    const hasOverride = !!t?.hasOverride;
    const w = eff ? (eff.weight_factor ?? null) : null;
    const ce = eff ? (eff.call_every_days_default ?? null) : null;
    const cd = eff ? (eff.call_duration_minutes_default ?? null) : null;

    const wEl = document.getElementById('planningWizardObjectiveWeight');
    const ceEl = document.getElementById('planningWizardObjectiveCallEvery');
    const cdEl = document.getElementById('planningWizardObjectiveCallDur');
    if (wEl) wEl.placeholder = (w !== null && w !== undefined) ? `Default: ${w}` : 'Default catalogo';
    if (ceEl) ceEl.placeholder = (ce !== null && ce !== undefined) ? `Default: ${ce}` : 'Default catalogo';
    if (cdEl) cdEl.placeholder = (cd !== null && cd !== undefined) ? `Default: ${cd}` : 'Default catalogo';

    // Show explicit defaults to help users (not only placeholders)
    const fmt = (v) => (v === null || v === undefined || v === '') ? '—' : String(v);
    const baseW = base ? (base.weight_factor ?? null) : null;
    const baseCe = base ? (base.call_every_days_default ?? null) : null;
    const baseCd = base ? (base.call_duration_minutes_default ?? null) : null;

    const weightTxt = document.getElementById('planningWizardObjectiveWeightDefaultText');
    const callEveryTxt = document.getElementById('planningWizardObjectiveCallEveryDefaultText');
    const callDurTxt = document.getElementById('planningWizardObjectiveCallDurDefaultText');

    const suffix = hasOverride ? ' (override cliente attivo)' : '';
    if (weightTxt) {
      weightTxt.innerHTML = `Default: <strong>${this.escapeHtml(fmt(w))}</strong>${suffix}` +
        ((baseW !== null && baseW !== undefined && fmt(baseW) !== fmt(w)) ? ` — Catalogo base: ${this.escapeHtml(fmt(baseW))}` : '');
    }
    if (callEveryTxt) {
      callEveryTxt.innerHTML = `Default: <strong>${this.escapeHtml(fmt(ce))}</strong>${suffix}` +
        ((baseCe !== null && baseCe !== undefined && fmt(baseCe) !== fmt(ce)) ? ` — Catalogo base: ${this.escapeHtml(fmt(baseCe))}` : '');
    }
    if (callDurTxt) {
      callDurTxt.innerHTML = `Default: <strong>${this.escapeHtml(fmt(cd))}</strong>${suffix}` +
        ((baseCd !== null && baseCd !== undefined && fmt(baseCd) !== fmt(cd)) ? ` — Catalogo base: ${this.escapeHtml(fmt(baseCd))}` : '');
    }

    // Best-effort: infer standards selection from objective name (only if user hasn't selected any)
    this.wizardMaybeInferStandardsFromObjectiveName();
  }

  wizardMaybeInferStandardsFromObjectiveName() {
    const stdSel = document.getElementById('planningWizardStandards');
    if (!stdSel) return;
    const currentSelected = this.getSelectedValues(stdSel);
    if (currentSelected.length) return;

    const objSel = document.getElementById('planningWizardObjectiveType');
    const name = objSel ? String(objSel.selectedOptions?.[0]?.textContent || '').toLowerCase() : '';
    const picked = [];
    if (name.includes('9001')) picked.push('ISO 9001');
    if (name.includes('14001')) picked.push('ISO 14001');
    if (name.includes('45001')) picked.push('ISO 45001');
    if (name.includes('27001')) picked.push('ISO/IEC 27001');
    const final = picked.length ? picked : ['ISO 9001'];
    [...stdSel.options].forEach(o => { o.selected = final.includes(o.value); });
  }

  wizardGetConsultantEffortDays(selectedIds) {
    const ids = Array.isArray(selectedIds) ? selectedIds : [];
    const out = {};
    const wrap = document.getElementById('planningWizardConsultantEffortWrap');
    if (!wrap) {
      for (const id of ids) out[id] = parseFloat(this.wizardConsultantEffortDays[id] || 0) || 0;
      return out;
    }
    for (const id of ids) {
      const el = wrap.querySelector(`input[data-user-id="${id}"]`);
      const v = el ? parseFloat(el.value || '0') : (parseFloat(this.wizardConsultantEffortDays[id] || 0) || 0);
      out[id] = Number.isFinite(v) ? v : 0;
    }
    return out;
  }

  wizardRenderConsultantEfforts() {
    const sel = document.getElementById('planningWizardConsultants');
    const ids = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];

    const group = document.getElementById('planningWizardConsultantEffortGroup');
    const wrap = document.getElementById('planningWizardConsultantEffortWrap');
    if (!group || !wrap) return;

    if (!ids.length) {
      group.style.display = 'none';
      wrap.innerHTML = '';
      this.wizardConsultantEffortDays = {};
      const totalEl = document.getElementById('planningWizardEffortTotalDays');
      if (totalEl) totalEl.value = '';
      return;
    }

    group.style.display = 'block';
    const consultantById = new Map((this.consultants || []).map(u => [parseInt(u.id, 10), u]));

    // Keep existing values, but drop deselected ones
    const nextMap = {};
    for (const id of ids) {
      const v = this.wizardConsultantEffortDays[id];
      nextMap[id] = (v === null || v === undefined) ? 1 : (parseFloat(v) || 1);
    }
    this.wizardConsultantEffortDays = nextMap;
    // Drop dirty flags for deselected
    const nextDirty = {};
    for (const id of ids) if (this.wizardConsultantEffortDirty[id]) nextDirty[id] = true;
    this.wizardConsultantEffortDirty = nextDirty;

    // Default capacity labels (best-effort)
    const capById = new Map();
    if (this.consultantCapacityCache && Array.isArray(this.consultantCapacityCache.consultants)) {
      for (const c of this.consultantCapacityCache.consultants) {
        const id = parseInt(c?.id || '0', 10) || 0;
        if (!id) continue;
        const d = c?.available_days;
        const days = (d === null || d === undefined || d === '') ? null : (parseFloat(String(d)) || 0);
        capById.set(id, days);
      }
    }

    wrap.innerHTML = ids.map(id => {
      const u = consultantById.get(id) || {};
      const label = (u.name || u.email || `#${id}`);
      const v = this.wizardConsultantEffortDays[id] ?? 1;
      const def = capById.has(id) ? capById.get(id) : null;
      const defLabel = (def === null || def === undefined) ? '' : `Default salvato: ${def}g`;
      const isOverride = (def !== null && def !== undefined) ? (Math.abs((parseFloat(v) || 0) - (parseFloat(def) || 0)) > 1e-6) : false;
      return `
        <div class="planning-form-row" style="align-items:flex-end; margin-bottom:8px;">
          <div class="form-group" style="flex: 1;">
            <label style="font-weight:600;">${this.escapeHtml(label)}</label>
            <div class="planning-muted" style="margin-top:4px; font-size: 12px;">
              ${defLabel ? this.escapeHtml(defLabel) : 'Default salvato: —'}
              ${isOverride ? `<span style="margin-left:8px; font-weight:600;">(override piano)</span>` : ``}
              ${(def !== null && def !== undefined) ? `<button type="button" class="btn btn-secondary btn-sm" data-action="wizard-reset-default" data-user-id="${id}" style="margin-left:10px; padding:2px 8px;">Ripristina</button>` : ``}
            </div>
          </div>
          <div class="form-group" style="width: 160px;">
            <input type="number" min="0" step="0.5" class="form-control" data-user-id="${id}" value="${this.escapeAttr(String(v))}" />
          </div>
        </div>
      `;
    }).join('') + `<div class="planning-muted" id="planningWizardEffortTotalLabel" style="margin-top:6px;"></div>`;

    wrap.querySelectorAll('input[data-user-id]')?.forEach(inp => {
      inp.addEventListener('input', () => {
        const uid = parseInt(inp.getAttribute('data-user-id') || '0', 10) || 0;
        const v = parseFloat(inp.value || '0') || 0;
        if (uid) {
          this.wizardConsultantEffortDays[uid] = v;
          this.wizardConsultantEffortDirty[uid] = true;
        }
        this.wizardUpdateEffortTotals();
      });
    });

    wrap.querySelectorAll('button[data-action="wizard-reset-default"]')?.forEach(btn => {
      btn.addEventListener('click', async () => {
        const uid = parseInt(btn.getAttribute('data-user-id') || '0', 10) || 0;
        if (!uid) return;
        try {
          // Ensure we have fresh defaults (best-effort)
          await this.loadConsultantCapacityList({ force: true });
        } catch (_) {}
        const c = (this.consultantCapacityCache?.consultants || []).find(x => parseInt(x?.id || '0', 10) === uid) || null;
        const d = c && c.available_days !== null && c.available_days !== undefined ? parseFloat(String(c.available_days)) : NaN;
        if (!Number.isFinite(d)) return;
        this.wizardConsultantEffortDays[uid] = d;
        delete this.wizardConsultantEffortDirty[uid];
        this.wizardRenderConsultantEfforts();
      });
    });

    this.wizardUpdateEffortTotals();
  }

  wizardUpdateEffortTotals() {
    const ids = Object.keys(this.wizardConsultantEffortDays || {}).map(x => parseInt(x, 10)).filter(v => Number.isFinite(v) && v > 0);
    const totalDays = ids.reduce((acc, id) => acc + (parseFloat(this.wizardConsultantEffortDays[id] || 0) || 0), 0);
    const totalEl = document.getElementById('planningWizardEffortTotalDays');
    if (totalEl) totalEl.value = String(totalDays);
    const label = document.getElementById('planningWizardEffortTotalLabel');
    if (label) label.textContent = `Totale: ${totalDays} giornate (${totalDays * 8} ore)`;
  }

  wizardCheckProposalDaysAgainstAvailability() {
    const sel = document.getElementById('planningWizardConsultants');
    const selected = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];
    const avail = this.wizardGetConsultantEffortDays(selected);
    const used = {};
    for (const it of (this.wizardProposalItems || [])) {
      const uid = parseInt(it.assignee_user_id || '0', 10) || 0;
      const d = parseFloat(it.days || '0') || 0;
      if (!uid) continue;
      used[uid] = (used[uid] || 0) + d;
    }
    for (const uid of Object.keys(used)) {
      const id = parseInt(uid, 10) || 0;
      const a = parseFloat(avail[id] || 0) || 0;
      const u = parseFloat(used[id] || 0) || 0;
      if (!selected.includes(id)) {
        return { ok: false, error: `La proposta usa un consulente non selezionato (#${id}).` };
      }
      if (u - a > 1e-6) {
        return { ok: false, error: `Disponibilità superata per consulente #${id}: usate ${u}g su ${a}g.` };
      }
    }
    return { ok: true };
  }

  wizardRenderCapacityWarning() {
    const el = document.getElementById('planningWizardCapacityWarning');
    if (!el) return;

    const sel = document.getElementById('planningWizardConsultants');
    const selected = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];
    const avail = this.wizardGetConsultantEffortDays(selected);

    const used = {};
    for (const it of (this.wizardProposalItems || [])) {
      const uid = parseInt(it.assignee_user_id || '0', 10) || 0;
      const d = parseFloat(it.days || '0') || 0;
      if (!uid) continue;
      used[uid] = (used[uid] || 0) + d;
    }

    const overages = [];
    for (const uidStr of Object.keys(used)) {
      const uid = parseInt(uidStr, 10) || 0;
      const a = parseFloat(avail[uid] || 0) || 0;
      const u = parseFloat(used[uid] || 0) || 0;
      const over = u - a;
      if (over > 1e-6) {
        const c = (this.consultants || []).find(x => parseInt(x?.id || '0', 10) === uid) || null;
        const label = c ? (c.name || c.email || `#${uid}`) : `#${uid}`;
        overages.push({ uid, label, used: u, avail: a, over });
      }
    }
    overages.sort((a, b) => b.over - a.over);

    const notes = Array.isArray(this.wizardCapacityReport?.notes) ? this.wizardCapacityReport.notes : [];
    const rebalanceApplied = !!this.wizardCapacityReport?.rebalance_applied;

    if (!overages.length) {
      el.style.display = 'none';
      el.innerHTML = '';
      return;
    }

    el.style.display = 'block';
    el.innerHTML = `
      <div style="font-weight:700; margin-bottom:6px;">Attenzione: proposta oltre disponibilità consulenti</div>
      <div class="planning-muted" style="margin-bottom:8px;">
        La proposta è stata generata comunque. Puoi <strong>ridurre giornate</strong>, <strong>cambiare assegnatario</strong>, oppure <strong>aumentare la disponibilità (override piano)</strong>.
      </div>
      <div style="margin-bottom:8px;">
        ${overages.map(o => `<div style="margin-bottom:6px;"><strong>${this.escapeHtml(o.label)}</strong>: usate ${this.escapeHtml(String(o.used))}g su ${this.escapeHtml(String(o.avail))}g · <strong>+${this.escapeHtml(String(o.over.toFixed(2)))}g</strong></div>`).join('')}
      </div>
      ${(rebalanceApplied || notes.length) ? `<div class="planning-muted" style="margin-top:8px;">
        ${rebalanceApplied ? '<div><strong>Info:</strong> ribilanciamento automatico applicato (best-effort).</div>' : ''}
        ${notes.map(n => `<div>${this.escapeHtml(n)}</div>`).join('')}
      </div>` : ''}
    `;
  }

  renderWizard() {
    const indicator = document.getElementById('planningWizardStepIndicator');
    const steps = 5;
    if (indicator) {
      indicator.innerHTML = `<strong>Step ${this.wizardStep}/${steps}</strong>`;
    }

    for (let i = 1; i <= steps; i++) {
      const el = document.getElementById(`planningWizardStep${i}`);
      if (el) el.style.display = (i === this.wizardStep) ? 'block' : 'none';
    }

    const backBtn = document.getElementById('planningWizardBackBtn');
    const nextBtn = document.getElementById('planningWizardNextBtn');
    if (backBtn) backBtn.style.display = this.wizardStep === 1 ? 'none' : 'inline-flex';
    if (nextBtn) {
      if (this.wizardStep === steps) nextBtn.textContent = 'Salva piano';
      else nextBtn.textContent = 'Avanti';
      // On step 4 require a generated proposal
      if (this.wizardStep === 4) {
        nextBtn.disabled = !(this.wizardProposalItems && this.wizardProposalItems.length);
      } else {
        nextBtn.disabled = false;
      }
    }
  }

  wizardPrev() {
    this.wizardStep = Math.max(1, this.wizardStep - 1);
    this.renderWizard();
  }

  async wizardNext() {
    const steps = 5;

    // Validate current step
    if (this.wizardStep === 1) {
      const client = parseInt(document.getElementById('planningWizardClient')?.value || '0', 10) || 0;
      if (!client) return this.toast('Seleziona un’azienda cliente', 'error');
      await this.wizardLoadActivityTypesForClient(client);
    }
    if (this.wizardStep === 2) {
      const objTypeId = parseInt(document.getElementById('planningWizardObjectiveType')?.value || '0', 10) || 0;
      const dl = (document.getElementById('planningWizardDeadline')?.value || '').trim();
      if (!objTypeId) return this.toast('Seleziona un obiettivo dal catalogo', 'error');
      if (!dl) return this.toast('Seleziona una scadenza', 'error');
      const { weight, callEvery, callDur } = this.wizardGetObjectiveOverrides();
      if (weight !== null && weight <= 0) return this.toast('Peso non valido (> 0)', 'error');
      if (callEvery !== null && callEvery <= 0) return this.toast('Call ogni (giorni) non valido (> 0)', 'error');
      if (callDur !== null && callDur <= 0) return this.toast('Durata call (min) non valida (> 0)', 'error');
    }
    if (this.wizardStep === 3) {
      const sel = document.getElementById('planningWizardConsultants');
      const ids = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];
      if (!ids.length) return this.toast('Seleziona almeno 1 consulente', 'error');
      const effortDays = this.wizardGetConsultantEffortDays(ids);
      const totalDays = Object.values(effortDays).reduce((a, b) => a + (parseFloat(b) || 0), 0);
      if (totalDays <= 0) return this.toast('Inserisci la disponibilità (giornate) per ciascun consulente', 'error');
    }
    if (this.wizardStep === 4) {
      if (!this.wizardProposalItems || !this.wizardProposalItems.length) {
        return this.toast('Genera prima una proposta (Step 4)', 'error');
      }
      const availabilityCheck = this.wizardCheckProposalDaysAgainstAvailability();
      if (!availabilityCheck.ok) {
        // Do NOT block: the system should still allow saving, but must warn clearly.
        this.toast(availabilityCheck.error || 'Disponibilità insufficiente: la proposta supera la capienza.', 'warning');
        try { this.wizardRenderCapacityWarning(); } catch (_) {}
      }
    }

    if (this.wizardStep < steps) {
      this.wizardStep++;
      if (this.wizardStep === 2) {
        this.wizardRenderObjectiveOptions();
        this.wizardApplyObjectiveDefaultsFromSelection();
      }
      if (this.wizardStep === 3) {
        this.wizardRenderConsultantEfforts();
      }
      this.renderWizard();
      return;
    }

    // Step 5: create plan from proposal + optional schedule
    const nextBtn = document.getElementById('planningWizardNextBtn');
    const prevLabel = nextBtn ? nextBtn.textContent : '';
    if (nextBtn) {
      nextBtn.disabled = true;
      nextBtn.textContent = 'Creazione...';
    }

    try {
      const client = parseInt(document.getElementById('planningWizardClient')?.value || '0', 10) || 0;
      const objTypeId = parseInt(document.getElementById('planningWizardObjectiveType')?.value || '0', 10) || 0;
      const objType = objTypeId ? (this.wizardClientActivityTypeById.get(objTypeId) || null) : null;
      const objName = (objType?.effective?.name || objType?.base?.name || '').trim();
      const deadline = (document.getElementById('planningWizardDeadline')?.value || '').trim();
      const notes = document.getElementById('planningWizardNotes')?.value || '';
      const sel = document.getElementById('planningWizardConsultants');
      const consultantIds = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];
      const genSchedule = !!document.getElementById('planningWizardGenerateSchedule')?.checked;
      const objectiveOverrides = this.wizardGetObjectiveOverrides();
      const consultantEffortDays = this.wizardGetConsultantEffortDays(consultantIds);
      const effortTotalDays = Object.values(consultantEffortDays).reduce((a, b) => a + (parseFloat(b) || 0), 0);

      const proposalTitle = (this.wizardProposal?.proposal_title || '').trim();
      const title = (proposalTitle || objName || 'Piano consulenza').slice(0, 255);
      const periodStart = this.todayLocalDate();
      const periodEnd = deadline || null;

      // Client profile (wizard step 2) for later Blueprint prefills
      const wBusinessDescription = (document.getElementById('planningWizardBusinessDescription')?.value || '').trim();
      const wDesiredScopeHint = (document.getElementById('planningWizardDesiredScopeHint')?.value || '').trim();
      const wEmployeeCountRaw = (document.getElementById('planningWizardEmployeeCount')?.value || '').trim();
      const wSitesCountRaw = (document.getElementById('planningWizardSitesCount')?.value || '').trim();
      const wEmployeeCount = wEmployeeCountRaw === '' ? null : (parseInt(wEmployeeCountRaw, 10) || 0);
      const wSitesCount = wSitesCountRaw === '' ? null : (parseInt(wSitesCountRaw, 10) || 0);
      const wStandards = this.getSelectedValues(document.getElementById('planningWizardStandards'));

      const confidenceScore = this.wizardProposal?.confidence?.score;
      const risks = Array.isArray(this.wizardProposal?.risks) ? this.wizardProposal.risks : [];
      const risksText = risks.length ? ('Rischi:\n' + risks.map(r => `- ${r.risk || ''} | Mitigazione: ${r.mitigation || ''}`).join('\n')) : '';
      const confText = (confidenceScore !== undefined && confidenceScore !== null) ? `Confidence: ${confidenceScore}` : '';
      const objLine = objName ? `Obiettivo (catalogo): ${objName} (#${objTypeId})` : '';
      const ovParts = [];
      if (objectiveOverrides.weight !== null) ovParts.push(`peso=${objectiveOverrides.weight}`);
      if (objectiveOverrides.callEvery !== null) ovParts.push(`call_ogni_giorni=${objectiveOverrides.callEvery}`);
      if (objectiveOverrides.callDur !== null) ovParts.push(`call_durata_min=${objectiveOverrides.callDur}`);
      const ovLine = ovParts.length ? `Override piano: ${ovParts.join(', ')}` : '';
      const availabilityLine = effortTotalDays > 0 ? `Disponibilità totale (giornate): ${effortTotalDays}` : '';
      const fullNotes = [
        objLine,
        ovLine,
        availabilityLine,
        notes ? `Note:\n${notes}` : '',
        confText,
        risksText,
      ].filter(Boolean).join('\n\n').slice(0, 4000);

      const created = await this.apiFetch('consulting_plans/create.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_tenant_id: client,
          title,
          status: 'draft',
          period_start: periodStart || null,
          period_end: periodEnd || null,
          notes: fullNotes || null,
          consultant_user_ids: consultantIds,
          client_profile: {
            business_description: wBusinessDescription || '',
            employee_count: wEmployeeCount,
            sites_count: wSitesCount,
            desired_scope_hint: wDesiredScopeHint || '',
          },
          blueprint_standards: wStandards,
        }),
      });

      const planId = created.data?.id;
      if (!planId) throw new Error('Creazione piano fallita (id mancante)');
      this.wizardCreatedPlanId = planId;

      // Create proposal items (best-effort)
      const consultantNameById = new Map((this.consultants || []).map(c => [parseInt(c.id, 10), (c.name || c.email || `#${c.id}`)]));
      const domainIdByName = this.wizardClientActivityTypeIdByName || new Map();
      const objectiveCallEvery = objectiveOverrides.callEvery !== null ? objectiveOverrides.callEvery : (objType?.effective?.call_every_days_default || null);
      const objectiveCallDur = objectiveOverrides.callDur !== null ? objectiveOverrides.callDur : (objType?.effective?.call_duration_minutes_default || null);
      for (const it of (this.wizardProposalItems || [])) {
        const assigneeId = parseInt(it.assignee_user_id || '0', 10) || 0;
        const assigneeName = assigneeId ? (consultantNameById.get(assigneeId) || `#${assigneeId}`) : '';
        const descParts = [];
        if (it.title) descParts.push(String(it.title));
        if (assigneeName) descParts.push(`Consulente: ${assigneeName}`);
        if (it.description) descParts.push(String(it.description));
        const desc = descParts.join('\n');
        const domainName = (it.domain_activity_type_name || '').trim();
        const domainId = domainName ? (domainIdByName.get(domainName.toLowerCase()) || null) : null;
        const effectiveDomainId = domainId || (objTypeId || null);

        // Pricing defaults from catalog (per domain type)
        const catalogType = effectiveDomainId ? (this.wizardClientActivityTypeById.get(parseInt(effectiveDomainId, 10)) || null) : null;
        const basePricing = catalogType?.base || catalogType?.effective || null;
        const baseDayRate = basePricing ? (parseFloat(basePricing.default_day_rate || '0') || 0) : 0;
        const defaultDayRate = baseDayRate > 0 ? baseDayRate : this.dayRateDefault;
        const defaultFixed = basePricing ? (parseFloat(basePricing.default_fixed_amount || '0') || 0) : 0;
        const isTouchpoint = (it.activity_type === 'call' || it.activity_type === 'communication');

        await this.apiFetch('consulting_plans/items_upsert.php', {
          method: 'POST',
          json: true,
          body: JSON.stringify({
            csrf_token: this.csrfToken,
            plan_id: parseInt(planId, 10),
            activity_type: it.activity_type || 'remote',
            domain_activity_type_id: effectiveDomainId || null,
            activity_date: it.start_date || null,
            days: parseFloat(it.days || '0') || 0,
            km: parseFloat(it.km || '0') || 0,
            day_rate: (parseFloat(it.day_rate || '0') || 0) || defaultDayRate,
            fixed_amount: defaultFixed,
            extras_amount: parseFloat(it.extras_amount || '0') || 0,
            description: desc || null,
            call_every_days_override: isTouchpoint && objectiveCallEvery ? parseInt(objectiveCallEvery, 10) : null,
            call_duration_minutes_override: isTouchpoint && objectiveCallDur ? parseInt(objectiveCallDur, 10) : null,
          }),
        });
      }

      // Optional: generate schedule drafts immediately
      if (genSchedule) {
        try {
          await this.apiFetch('consulting_plans/schedule_generate.php', {
            method: 'POST',
            json: true,
            body: JSON.stringify({ csrf_token: this.csrfToken, plan_id: parseInt(planId, 10) }),
          });
        } catch (e) {
          console.warn('[PlanningWizard] schedule_generate failed:', e?.message || e);
        }
      }

      this.toast('Piano creato (procedura guidata)', 'success');
      this.closeGuidedWizard();
      await this.loadPlans();
      await this.selectPlan(planId);
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore creazione piano', 'error');
    } finally {
      if (nextBtn) {
        nextBtn.disabled = false;
        nextBtn.textContent = prevLabel || 'Crea piano';
      }
    }
  }

  wizardResetProposalUI() {
    const meta = document.getElementById('planningWizardAiMeta');
    const cap = document.getElementById('planningWizardCapacityWarning');
    const risks = document.getElementById('planningWizardRisks');
    const table = document.getElementById('planningWizardProposalTable');
    const tbody = document.getElementById('planningWizardProposalTbody');
    const addRowBtn = document.getElementById('planningWizardAddRowBtn');
    if (meta) { meta.style.display = 'none'; meta.innerHTML = ''; }
    if (cap) { cap.style.display = 'none'; cap.innerHTML = ''; }
    if (risks) { risks.style.display = 'none'; risks.innerHTML = ''; }
    if (table) table.style.display = 'none';
    if (tbody) tbody.innerHTML = '';
    if (addRowBtn) addRowBtn.style.display = 'none';
    this.wizardCapacityReport = null;
  }

  todayLocalDate() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  endOfCurrentYearLocalDate(refDateStr) {
    try {
      const m = String(refDateStr || '').match(/^(\d{4})-/);
      const y = m && m[1] ? (parseInt(m[1], 10) || new Date().getFullYear()) : new Date().getFullYear();
      return `${y}-12-31`;
    } catch (_) {
      return `${new Date().getFullYear()}-12-31`;
    }
  }

  wizardAddProposalRow() {
    const sel = document.getElementById('planningWizardConsultants');
    const consultantIds = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];
    const firstAssignee = consultantIds[0] || (this.selectedConsultantIds[0] || 0);
    this.wizardProposalItems.push({
      activity_type: 'remote',
      title: 'Nuova attività',
      start_date: this.todayLocalDate(),
      end_date: this.todayLocalDate(),
      assignee_user_id: firstAssignee,
      days: 1,
      day_rate: 0,
      km: 0,
      extras_amount: 0,
      description: '',
      domain_activity_type_name: '',
    });
    this.wizardRenderProposal();
    this.renderWizard();
  }

  wizardRemoveProposalRow(idx) {
    const i = parseInt(idx, 10);
    if (!Number.isFinite(i) || i < 0) return;
    this.wizardProposalItems.splice(i, 1);
    this.wizardRenderProposal();
    this.renderWizard();
  }

  async wizardGenerateProposal() {
    try {
      const client = parseInt(document.getElementById('planningWizardClient')?.value || '0', 10) || 0;
      const objectiveTypeId = parseInt(document.getElementById('planningWizardObjectiveType')?.value || '0', 10) || 0;
      const deadline = (document.getElementById('planningWizardDeadline')?.value || '').trim();
      const notes = (document.getElementById('planningWizardNotes')?.value || '').trim();
      const sel = document.getElementById('planningWizardConsultants');
      const consultantIds = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];

      if (!client) return this.toast('Seleziona un’azienda cliente', 'error');
      if (!objectiveTypeId) return this.toast('Seleziona un obiettivo dal catalogo', 'error');
      if (!deadline) return this.toast('Seleziona una scadenza', 'error');
      if (!consultantIds.length) return this.toast('Seleziona almeno 1 consulente', 'error');

      const consultantEffortDays = this.wizardGetConsultantEffortDays(consultantIds);
      const effortTotalDays = Object.values(consultantEffortDays).reduce((a, b) => a + (parseFloat(b) || 0), 0);
      if (effortTotalDays <= 0) return this.toast('Inserisci la disponibilità (giornate) per ciascun consulente', 'error');
      const effortHours = effortTotalDays * 8;
      const objectiveOverrides = this.wizardGetObjectiveOverrides();
      if (objectiveOverrides.weight !== null && objectiveOverrides.weight <= 0) return this.toast('Peso non valido (> 0)', 'error');
      if (objectiveOverrides.callEvery !== null && objectiveOverrides.callEvery <= 0) return this.toast('Call ogni (giorni) non valido (> 0)', 'error');
      if (objectiveOverrides.callDur !== null && objectiveOverrides.callDur <= 0) return this.toast('Durata call (min) non valida (> 0)', 'error');

      this.wizardResetProposalUI();
      const btn = document.getElementById('planningWizardGenerateBtn');
      const prevLabel = btn ? btn.textContent : '';
      if (btn) { btn.disabled = true; btn.textContent = 'Generazione...'; }

      this.startProgress('Generazione proposta AI…');
      const res = await this.apiFetch('consulting_plans/proposal_generate.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_tenant_id: client,
          objective_activity_type_id: objectiveTypeId,
          objective_overrides: {
            weight_factor: objectiveOverrides.weight,
            call_every_days: objectiveOverrides.callEvery,
            call_duration_minutes: objectiveOverrides.callDur,
          },
          deadline,
          effort_total_hours: effortHours,
          consultant_user_ids: consultantIds,
          consultant_effort_days: consultantEffortDays,
          notes: notes || null,
        }),
      });

      const proposal = res.data?.proposal;
      if (!proposal) throw new Error('Proposta non valida (manca proposal)');

      this.wizardProposal = proposal;
      this.wizardProposalItems = Array.isArray(proposal.items) ? proposal.items.map(x => ({ ...x })) : [];
      this.wizardCapacityReport = res.data?.capacity_report || null;

      this.wizardRenderProposal();
      this.renderWizard();
      if (Array.isArray(this.wizardCapacityReport?.overages) && this.wizardCapacityReport.overages.length) {
        this.toast('Proposta generata, ma supera la disponibilità di alcuni consulenti (vedi avviso).', 'warning');
      } else {
        this.toast('Proposta generata. Puoi modificare e poi salvare.', 'success');
      }

      if (btn) { btn.disabled = false; btn.textContent = prevLabel || 'Genera proposta'; }
    } catch (e) {
      console.error('[PlanningWizard] generate error:', e);
      const msg = (e?.message || '').trim();
      if (msg && msg.includes('OPENAI_API_KEY mancante')) {
        this.toast('OpenAI non configurato. Inserisci la chiave in config.secrets.php (copia privata) e riprova.', 'error');
      } else {
        this.toast(msg || 'Errore generazione proposta', 'error');
      }
      const btn = document.getElementById('planningWizardGenerateBtn');
      if (btn) { btn.disabled = false; btn.textContent = 'Genera proposta'; }
    }
    finally {
      this.finishProgress();
    }
  }

  wizardRenderProposal() {
    const meta = document.getElementById('planningWizardAiMeta');
    const capWarn = document.getElementById('planningWizardCapacityWarning');
    const risksBox = document.getElementById('planningWizardRisks');
    const table = document.getElementById('planningWizardProposalTable');
    const tbody = document.getElementById('planningWizardProposalTbody');
    const addRowBtn = document.getElementById('planningWizardAddRowBtn');
    const scrollHint = document.querySelector('#planningGuidedWizardModal .planning-wizard-scroll-hint');

    if (!tbody || !table) return;

    const proposal = this.wizardProposal || {};
    const items = this.wizardProposalItems || [];

    // Meta / confidence
    if (meta) {
      const c = proposal.confidence || {};
      const tl = proposal.timeline || {};
      const score = (c.score !== undefined && c.score !== null) ? String(c.score) : '—';
      const reason = c.reason ? String(c.reason) : '';
      meta.innerHTML = `<div><strong>Timeline:</strong> ${this.escapeHtml(tl.start_date || '')} → ${this.escapeHtml(tl.end_date || '')} · <strong>Confidence:</strong> ${this.escapeHtml(score)} ${reason ? '· ' + this.escapeHtml(reason) : ''}</div>`;
      meta.style.display = 'block';
    }

    // Risks
    const risks = Array.isArray(proposal.risks) ? proposal.risks : [];
    if (risksBox) {
      if (!risks.length) {
        risksBox.style.display = 'none';
        risksBox.innerHTML = '';
      } else {
        risksBox.style.display = 'block';
        risksBox.innerHTML = `<div style="font-weight:600; margin-bottom:6px;">Rischi (e mitigazioni)</div>` +
          risks.map(r => `<div style="margin-bottom:6px;"><strong>${this.escapeHtml(r.risk || '')}</strong><div class="planning-muted">${this.escapeHtml(r.mitigation || '')}</div></div>`).join('');
      }
    }

    if (capWarn) this.wizardRenderCapacityWarning();

    // Table rows
    const consultantOptions = (this.consultants || []).map(u => {
      const label = this.escapeHtml(u.name || u.email || `#${u.id}`);
      return `<option value="${u.id}">${label}</option>`;
    }).join('');

    tbody.innerHTML = items.map((it, idx) => {
      const type = it.activity_type || 'remote';
      const title = it.title || '';
      const sd = it.start_date || '';
      const ed = it.end_date || '';
      const assignee = parseInt(it.assignee_user_id || '0', 10) || 0;
      const days = this.num(it.days || 0);
      const desc = it.description || '';

      return `<tr data-widx="${idx}">
        <td><input class="form-control" data-field="title" value="${this.escapeAttr(title)}" /></td>
        <td>
          <select class="form-control" data-field="activity_type">
            <option value="remote" ${type === 'remote' ? 'selected' : ''}>Remoto</option>
            <option value="onsite" ${type === 'onsite' ? 'selected' : ''}>In sito</option>
            <option value="call" ${type === 'call' ? 'selected' : ''}>Call</option>
            <option value="communication" ${type === 'communication' ? 'selected' : ''}>Comunicazione</option>
            <option value="travel" ${type === 'travel' ? 'selected' : ''}>Trasferta</option>
          </select>
        </td>
        <td><input type="date" class="form-control" data-field="start_date" value="${this.escapeAttr(sd)}" /></td>
        <td><input type="date" class="form-control" data-field="end_date" value="${this.escapeAttr(ed)}" /></td>
        <td>
          <select class="form-control" data-field="assignee_user_id">
            ${consultantOptions}
          </select>
        </td>
        <td><input type="number" step="0.5" min="0" class="form-control" data-field="days" value="${this.escapeAttr(String(days))}" /></td>
        <td><input class="form-control" data-field="description" value="${this.escapeAttr(desc)}" /></td>
        <td><button type="button" class="btn btn-danger btn-sm" data-action="remove">×</button></td>
      </tr>`;
    }).join('');

    // Set selected assignee values after innerHTML (because options are reused)
    tbody.querySelectorAll('tr[data-widx]').forEach(tr => {
      const idx = parseInt(tr.getAttribute('data-widx') || '0', 10);
      const it = items[idx] || {};
      const sel = tr.querySelector('select[data-field="assignee_user_id"]');
      if (sel) sel.value = String(it.assignee_user_id || '');
    });

    // Bind changes (simple per-render binding)
    tbody.querySelectorAll('input,select,button').forEach(el => {
      const action = el.getAttribute('data-action');
      if (action === 'remove') {
        el.addEventListener('click', (e) => {
          const tr = e.target.closest('tr[data-widx]');
          const idx = tr ? tr.getAttribute('data-widx') : null;
          this.wizardRemoveProposalRow(idx);
        });
        return;
      }
      el.addEventListener('change', (e) => {
        const tr = e.target.closest('tr[data-widx]');
        if (!tr) return;
        const idx = parseInt(tr.getAttribute('data-widx') || '0', 10);
        const field = e.target.getAttribute('data-field');
        if (!field) return;
        const it = this.wizardProposalItems[idx];
        if (!it) return;
        let val = e.target.value;
        if (field === 'assignee_user_id') val = parseInt(val || '0', 10) || 0;
        if (field === 'days') val = parseFloat(val || '0') || 0;
        it[field] = val;
        try { this.wizardRenderCapacityWarning(); } catch (_) {}
        this.renderWizard(); // keep Next enabled state synced
      });
    });

    table.style.display = items.length ? 'table' : 'none';
    if (addRowBtn) addRowBtn.style.display = 'inline-flex';
    if (scrollHint) {
      // Show the hint only when the table is visible (helps users discover horizontal scroll)
      scrollHint.style.display = items.length ? 'block' : 'none';
    }
  }

  debounce(fn, ms) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  toast(msg, type = 'info') {
    const el = document.getElementById('planningToast');
    if (!el) return alert(msg);
    el.textContent = msg;
    // Clear any inline display forced by previous versions
    el.style.display = '';
    // Cancel previous hide timer
    if (this.toastTimer) {
      clearTimeout(this.toastTimer);
      this.toastTimer = null;
    }
    // Reset + force reflow so animation can restart
    el.className = `toast ${type}`;
    void el.offsetWidth; // eslint-disable-line no-unused-expressions
    el.className = `toast show ${type}`;
    this.toastTimer = setTimeout(() => {
      el.classList.remove('show');
    }, 2800);
  }

  async apiFetch(path, options = {}) {
    const url = path.startsWith('http') ? path : `${this.apiBase}${path.replace(/^\//, '')}`;
    const headers = options.headers || {};
    if (this.csrfToken) headers['X-CSRF-Token'] = this.csrfToken;
    if (options.json) headers['Content-Type'] = 'application/json';
    const res = await fetch(url, { credentials: 'same-origin', ...options, headers });

    // Try JSON; on failure keep a short raw preview for diagnostics
    let data = null;
    let rawPreview = '';
    try {
      data = await res.json();
    } catch (e) {
      try {
        const t = await res.text();
        rawPreview = String(t || '').slice(0, 200);
      } catch (_) {
        // ignore
      }
    }

    // Auth/session handling (common failure for planning tool if user is idle)
    if (res.status === 401) {
      const msg401 = data?.error || data?.message || 'Sessione scaduta. Effettua di nuovo il login.';
      this.handleSessionExpired(msg401);
      throw new Error(msg401);
    }

    if (!res.ok || !data || data.success === false) {
      const msg = data?.error || data?.message || `Errore (${res.status})`;
      // If server returned non-JSON (e.g. HTML), guide user
      if (!data && rawPreview) {
        console.warn('[Planning] Non-JSON response preview:', rawPreview);
      }
      const err = new Error(msg);
      err.status = res.status;
      err.data = data;
      err.rawPreview = rawPreview;
      err.url = url;
      throw err;
    }
    return data;
  }

  ensureProgressOverlay() {
    // Prefer server-rendered markup if present; otherwise create a minimal overlay.
    let overlay = document.getElementById('planningProgressOverlay');
    if (overlay) return overlay;

    overlay = document.createElement('div');
    overlay.id = 'planningProgressOverlay';
    overlay.style.cssText = [
      'position:fixed',
      'inset:0',
      'background:rgba(0,0,0,0.45)',
      'z-index:100001',
      'display:none',
      'align-items:center',
      'justify-content:center',
      'padding:16px'
    ].join(';');

    overlay.innerHTML = `
      <div style="background:white; border-radius:12px; max-width:520px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,0.25); padding:18px 18px 16px;">
        <div id="planningProgressText" style="font-weight:700; margin-bottom:10px;">Operazione in corso…</div>
        <div style="height:10px; background:#E5E7EB; border-radius:999px; overflow:hidden;">
          <div id="planningProgressBar" style="height:10px; width:0%; background:#2563EB;"></div>
        </div>
        <div style="display:flex; justify-content:space-between; margin-top:10px; color:#4B5563; font-size:12px;">
          <div id="planningProgressPct">0%</div>
          <div>Non chiudere la pagina</div>
        </div>
      </div>
    `;

    document.body.appendChild(overlay);
    return overlay;
  }

  startProgress(label = 'Operazione in corso…') {
    this.ensureProgressOverlay();
    const overlay = document.getElementById('planningProgressOverlay');
    const bar = document.getElementById('planningProgressBar');
    const pct = document.getElementById('planningProgressPct');
    const text = document.getElementById('planningProgressText');
    if (text) text.textContent = label;
    if (bar) bar.style.width = '0%';
    if (pct) pct.textContent = '0%';
    if (overlay) overlay.style.display = 'flex';

    // Fake progress up to ~90% while network is pending.
    const startTs = Date.now();
    clearInterval(this._progressTimer);
    this._progressTimer = setInterval(() => {
      const elapsed = Date.now() - startTs;
      // Approach 90% asymptotically.
      const p = Math.min(90, Math.floor(10 + (80 * (1 - Math.exp(-elapsed / 3500)))));
      if (bar) bar.style.width = `${p}%`;
      if (pct) pct.textContent = `${p}%`;
    }, 180);
  }

  finishProgress() {
    const overlay = document.getElementById('planningProgressOverlay');
    const bar = document.getElementById('planningProgressBar');
    const pct = document.getElementById('planningProgressPct');
    if (bar) bar.style.width = '100%';
    if (pct) pct.textContent = '100%';
    clearInterval(this._progressTimer);
    this._progressTimer = null;
    // Small delay so user sees completion
    setTimeout(() => {
      if (overlay) overlay.style.display = 'none';
    }, 250);
  }

  handleSessionExpired(message) {
    // Avoid spamming multiple overlays/toasts.
    this.lastAuthError = message || this.lastAuthError || 'Sessione scaduta';
    if (this.sessionExpiredShown) return;
    this.sessionExpiredShown = true;

    // Build a blocking overlay so user understands why nothing works.
    const existing = document.getElementById('planningAuthOverlay');
    if (existing) {
      existing.style.display = 'flex';
      return;
    }

    const returnTo = encodeURIComponent('planning.php');
    const loginUrl = `index.php?return_to=${returnTo}`;
    const overlay = document.createElement('div');
    overlay.id = 'planningAuthOverlay';
    overlay.style.cssText = [
      'position:fixed',
      'inset:0',
      'background:rgba(0,0,0,0.55)',
      'z-index:100000',
      'display:flex',
      'align-items:center',
      'justify-content:center',
      'padding:16px'
    ].join(';');

    const box = document.createElement('div');
    box.style.cssText = [
      'background:white',
      'border-radius:12px',
      'max-width:560px',
      'width:100%',
      'box-shadow:0 20px 60px rgba(0,0,0,0.25)',
      'padding:20px'
    ].join(';');

    box.innerHTML = `
      <div style="font-weight:700; font-size:18px; margin-bottom:6px;">Sessione scaduta</div>
      <div style="color:#4B5563; font-size:14px; line-height:1.4; margin-bottom:14px;">
        ${this.escapeHtml(this.lastAuthError || 'Per continuare devi effettuare nuovamente il login.')}
      </div>
      <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
        <button type="button" class="btn btn-secondary btn-sm" id="planningAuthOverlayReloadBtn">Ricarica</button>
        <a class="btn btn-primary btn-sm" href="${loginUrl}">Vai al login</a>
      </div>
      <div style="margin-top:10px; color:#6B7280; font-size:12px;">
        Suggerimento: se usi estensioni (autofill/password manager) e vedi errori di “content.js”, prova in incognito o disattiva l’estensione per questo sito.
      </div>
    `;
    overlay.appendChild(box);
    document.body.appendChild(overlay);

    box.querySelector('#planningAuthOverlayReloadBtn')?.addEventListener('click', () => {
      window.location.reload();
    });
  }

  async loadClients() {
    try {
      const data = await this.apiFetch('consulting_plans/clients.php', { method: 'GET' });
      this.clients = data.data?.clients || [];
      const sel = document.getElementById('planningClientFilter');
      if (sel) {
        sel.innerHTML = `<option value="">Tutte le aziende</option>` + this.clients.map(c => {
          const name = (c.denominazione || c.name || `Tenant ${c.id}`);
          return `<option value="${c.id}">${this.escapeHtml(name)} (#${c.id})</option>`;
        }).join('');
      }
    } catch (e) {
      console.error(e);
      const msg = e.message || 'Errore caricamento aziende';
      this.toast(msg, 'error');
      this.renderInitErrorIfNeeded(msg);
    }
  }

  async loadCalendarTargets() {
    try {
      const data = await this.apiFetch('consulting_plans/calendar_targets.php', { method: 'GET' });
      this.calendarTargets = data.data?.targets || [];
    } catch (e) {
      console.warn('[Planning] calendar targets unavailable:', e?.message || e);
      this.calendarTargets = [];
    }
  }

  async loadPlans() {
    try {
      const clientId = document.getElementById('planningClientFilter')?.value || '';
      const status = document.getElementById('planningStatusFilter')?.value || '';
      const search = document.getElementById('planningSearch')?.value || '';

      const params = new URLSearchParams();
      if (clientId) params.set('client_tenant_id', clientId);
      if (status) params.set('status', status);
      if (search) params.set('search', search);
      params.set('limit', '100');

      const data = await this.apiFetch(`consulting_plans/list.php?${params.toString()}`, { method: 'GET' });
      this.plans = data.data?.plans || [];
      this.renderPlans();
      this.updateOnboardingVisibility();

      // Auto-select first plan if none selected
      if (!this.activePlanId && this.plans.length) {
        this.selectPlan(this.plans[0].id);
      } else if (this.activePlanId) {
        const stillExists = this.plans.some(p => String(p.id) === String(this.activePlanId));
        if (!stillExists) {
          this.activePlanId = null;
          this.items = [];
          this.renderItems();
          document.getElementById('planningActivePlanTitle').textContent = 'Seleziona un piano';
          this.setAddItemEnabled(false);
          this.updateDocumentPanelLinks();
          this.updateOnboardingVisibility();
        }
      }
    } catch (e) {
      console.error(e);
      const msg = e.message || 'Errore caricamento piani';
      this.toast(msg, 'error');
      this.renderInitErrorIfNeeded(msg);
    }
  }

  renderInitErrorIfNeeded(message) {
    if (!message || !String(message).includes('Modulo pianificazione non inizializzato')) return;
    const wrap = document.getElementById('planningPlansList');
    if (!wrap) return;
    wrap.innerHTML = `
      <div class="planning-muted">
        <strong>Modulo non inizializzato.</strong><br>
        Serve applicare la migrazione DB: <code>database/migrations/34_consulting_plans_tenant28.sql</code><br>
        In alternativa esegui il tool: <code>tools/apply_migration_34_consulting_plans.php</code>
      </div>
    `;
  }

  renderPlans() {
    const wrap = document.getElementById('planningPlansList');
    if (!wrap) return;
    if (!this.plans.length) {
      wrap.innerHTML = `<div class="planning-muted">Nessun piano trovato.</div>`;
      return;
    }
    wrap.innerHTML = this.plans.map(p => {
      const active = String(p.id) === String(this.activePlanId) ? 'active' : '';
      const client = p.client_name || `Tenant #${p.client_tenant_id}`;
      const status = p.status || 'draft';
      const period = (p.period_start || p.period_end) ? `${p.period_start || '—'} → ${p.period_end || '—'}` : 'Periodo: —';
      return `
        <div class="planning-plan ${active}" data-plan-id="${p.id}">
          <div style="display:flex; align-items:flex-start; justify-content:space-between; gap: 10px;">
          <div class="planning-plan-title">${this.escapeHtml(p.title || 'Piano')}</div>
            <button type="button" class="btn btn-danger btn-sm" data-action="delete-plan" data-plan-id="${p.id}" title="Elimina piano">Elimina</button>
          </div>
          <div class="planning-plan-sub">
            <span class="planning-pill ${status}">${this.escapeHtml(status)}</span>
            <span class="planning-muted"> • ${this.escapeHtml(client)}</span>
            <div class="planning-muted" style="margin-top:6px;">${this.escapeHtml(period)}</div>
          </div>
        </div>
      `;
    }).join('');

    wrap.querySelectorAll('[data-plan-id]').forEach(el => {
      // Ignore clicks on buttons inside the plan card
      if (el.getAttribute('data-action') === 'delete-plan') return;
      el.addEventListener('click', (ev) => {
        const target = ev.target;
        if (target && target.closest && target.closest('[data-action="delete-plan"]')) return;
        this.selectPlan(el.getAttribute('data-plan-id'));
      });
    });

    wrap.querySelectorAll('[data-action="delete-plan"]').forEach(btn => {
      btn.addEventListener('click', async (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        const id = parseInt(btn.getAttribute('data-plan-id') || '0', 10) || 0;
        if (!id) return;
        await this.deletePlan(id);
      });
    });
  }

  async deletePlan(planId) {
    const plan = this.plans.find(p => String(p.id) === String(planId));
    const title = plan?.title || `#${planId}`;
    if (!confirm(`Eliminare il piano "${title}"?`)) return;
    try {
      await this.apiFetch('consulting_plans/delete.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, id: parseInt(planId, 10) }),
      });
      this.toast('Piano eliminato', 'success');
      if (String(this.activePlanId) === String(planId)) {
        this.activePlanId = null;
        this.planScopes = [];
        this.planScopeServiceIds = [];
        this.planScopesStorageAvailable = false;
        this.planScopesDerivedFromEstimate = false;
        this.items = [];
        this.renderItems();
        document.getElementById('planningActivePlanTitle').textContent = 'Seleziona un piano';
        this.setAddItemEnabled(false);
        try { this.initPlanningTabs?.(); } catch (_) {}
      }
      await this.loadPlans();
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore eliminazione piano', 'error');
    }
  }

  async selectPlan(planId) {
    this.activePlanId = String(planId);
    // Reset checklist add-on state (loaded lazily when tab is opened)
    this.activeChecklist = null;
    this.activeChecklistItems = [];
    this.activeChecklistTemplate = null;
    this.activeChecklistDocProfile = null;
    this.activeChecklistLoadedForPlanId = null;
    const plan = this.plans.find(p => String(p.id) === String(planId));
    document.getElementById('planningActivePlanTitle').textContent = plan ? `${plan.title} — ${plan.client_name || `Tenant #${plan.client_tenant_id}`}` : 'Piano';
    this.renderPlans();
    this.setAddItemEnabled(true);
    await this.loadActivityTypesForActivePlan();
    await this.loadPlanScopesForActivePlan();
    await this.loadConsultantsForActivePlan();
    await this.loadClientLocationsForActivePlan();
    await this.loadItems(planId);
    await this.loadScheduleDrafts();
    this.updateOnboardingVisibility();
    try { this.initPlanningTabs?.(); } catch (_) {}

    // Update document-system quick links (client tenant scoped)
    this.updateDocumentPanelLinks();

    // Progress (best-effort, does not block)
    try { await this.refreshPlanProgress({ force: false }); } catch (_) {}
  }

  // ------------------------------
  // Plan progress (best-effort)
  // ------------------------------
  parseJsonBestEffort(v) {
    if (!v) return null;
    if (typeof v === 'object') return v;
    const s = String(v || '').trim();
    if (!s) return null;
    try { return JSON.parse(s); } catch (_) { return null; }
  }

  renderProgressPill(kind, label) {
    const k = String(kind || 'na');
    const l = String(label || '');
    return `<span class="planning-progress-pill ${this.escapeAttr(k)}">${this.escapeHtml(l)}</span>`;
  }

  async refreshPlanProgress({ force }) {
    const wrap = document.getElementById('planningPlanProgress');
    const body = document.getElementById('planningPlanProgressBody');
    if (!wrap || !body) return;

    const plan = this.getActivePlan();
    const planId = plan ? (parseInt(plan.id, 10) || 0) : 0;
    const clientTenantId = plan ? (parseInt(plan.client_tenant_id, 10) || 0) : 0;
    if (!planId || !clientTenantId) {
      wrap.style.display = 'none';
      return;
    }
    wrap.style.display = 'block';

    // Basic signals from current in-memory state (no extra API calls)
    const estimateObj = this.parseJsonBestEffort(plan.estimate_json);
    const isNewFlow = !!estimateObj; // legacy plans won't have estimate_json
    const hasEstimate = isNewFlow && Array.isArray(estimateObj?.estimates) && estimateObj.estimates.length > 0;
    const hasAllocation = isNewFlow && estimateObj && typeof estimateObj === 'object'
      && estimateObj.allocation && typeof estimateObj.allocation === 'object' && Object.keys(estimateObj.allocation || {}).length > 0;

    const hasAssigneeField = (this.items || []).some(it => Object.prototype.hasOwnProperty.call(it, 'assignee_user_id'));
    const anyAssignedItems = (this.items || []).some(it => (parseInt(it.assignee_user_id || '0', 10) || 0) > 0);

    const scheduleAvailable = (this.scheduleStorageAvailable !== false);
    const drafts = Array.isArray(this.scheduleDrafts) ? this.scheduleDrafts : [];
    const hasDrafts = drafts.length > 0;
    const confirmedCount = drafts.filter(d => (String(d.status || '') === 'confirmed') || (parseInt(d.confirmed_event_id || '0', 10) || 0) > 0).length;
    const draftCount = drafts.filter(d => String(d.status || '') === 'draft').length;
    const calendarGenerated = scheduleAvailable && hasDrafts;
    const calendarConfirmed = scheduleAvailable && hasDrafts && confirmedCount > 0 && draftCount === 0;

    // Now compute IMS + deliverables using Compliance APIs (best-effort, access dependent)
    let programId = 0;
    let programsAvailable = null; // null=unknown, true/false=known
    let deliverablesTotal = null;
    let deliverablesCompiled = null;
    try {
      const pres = await this.apiFetch(`compliance/programs.php?action=list&tenant_id=${encodeURIComponent(String(clientTenantId))}`, { method: 'GET', json: false });
      const plist = Array.isArray(pres?.data?.programs) ? pres.data.programs : [];
      programsAvailable = !!plist.length;
      programId = plist.length ? (parseInt(plist[0].id, 10) || 0) : 0;
    } catch (e) {
      // Permission/storage errors are common; treat as N/A instead of failing UI.
      programsAvailable = null;
      programId = 0;
    }

    if (programId > 0) {
      try {
        const ares = await this.apiFetch(`compliance/artifacts.php?action=list&program_id=${encodeURIComponent(String(programId))}`, { method: 'GET', json: false });
        const arts = Array.isArray(ares?.data?.artifacts) ? ares.data.artifacts : [];
        deliverablesTotal = arts.length;
        deliverablesCompiled = arts.filter(a => {
          const wiz = a?.wizard || null;
          const ts = wiz ? String(wiz.inputs_updated_at || '').trim() : '';
          return ts !== '';
        }).length;
      } catch (e) {
        deliverablesTotal = null;
        deliverablesCompiled = null;
      }
    }

    const items = [];

    // Stima
    if (!isNewFlow) {
      items.push({ key: 'stima', label: 'Stima fatta', pill: this.renderProgressPill('na', 'Legacy') , sub: 'Piano creato con flusso precedente' });
    } else {
      items.push({ key: 'stima', label: 'Stima fatta', pill: this.renderProgressPill(hasEstimate ? 'done' : 'pending', hasEstimate ? 'OK' : 'No'), sub: hasEstimate ? '' : 'Apri “Crea piano (stima guidata)” e calcola la stima' });
    }

    // Allocazione
    if (!hasAssigneeField) {
      items.push({ key: 'alloc', label: 'Allocazione fatta', pill: this.renderProgressPill('na', 'N/D'), sub: 'Piano legacy (righe senza assignee_user_id)' });
    } else {
      const ok = hasAllocation || anyAssignedItems;
      items.push({ key: 'alloc', label: 'Allocazione fatta', pill: this.renderProgressPill(ok ? 'done' : 'pending', ok ? 'OK' : 'No'), sub: ok ? '' : 'Apri tab “Consulenti” e applica allocazione' });
    }

    // Calendario
    if (!scheduleAvailable) {
      items.push({ key: 'cal', label: 'Calendario confermato', pill: this.renderProgressPill('na', 'N/D'), sub: 'Modulo calendario bozza non inizializzato (migrazione 35)' });
    } else if (!calendarGenerated) {
      items.push({ key: 'cal', label: 'Calendario confermato', pill: this.renderProgressPill('pending', 'No'), sub: 'Genera “Calendario (bozza)” e poi conferma' });
    } else {
      items.push({ key: 'cal', label: 'Calendario confermato', pill: this.renderProgressPill(calendarConfirmed ? 'done' : 'pending', calendarConfirmed ? 'OK' : 'In bozza'), sub: calendarConfirmed ? '' : `Bozze: ${draftCount}, confermati: ${confirmedCount}` });
    }

    // IMS provisionato
    if (programsAvailable === null) {
      items.push({ key: 'ims', label: 'IMS provisionato', pill: this.renderProgressPill('na', 'N/D'), sub: 'Permessi o modulo Compliance non disponibile' });
    } else {
      items.push({ key: 'ims', label: 'IMS provisionato', pill: this.renderProgressPill(programsAvailable ? 'done' : 'pending', programsAvailable ? 'OK' : 'No'), sub: programsAvailable ? '' : 'Usa “Provisiona /IMS” dalla tab “Sistema documentale”' });
    }

    // Deliverable compilati
    if (deliverablesTotal === null || deliverablesCompiled === null) {
      items.push({ key: 'del', label: 'Deliverable compilati', pill: this.renderProgressPill('na', 'N/D'), sub: 'Non disponibile senza programma/artifacts accessibili' });
    } else if (deliverablesTotal === 0) {
      items.push({ key: 'del', label: 'Deliverable compilati', pill: this.renderProgressPill('pending', 'No'), sub: 'Nessun deliverable trovato nel programma' });
    } else {
      const all = deliverablesCompiled >= deliverablesTotal;
      items.push({ key: 'del', label: 'Deliverable compilati', pill: this.renderProgressPill(all ? 'done' : 'pending', `${deliverablesCompiled}/${deliverablesTotal}`), sub: all ? '' : 'Apri Compliance e usa “Compila” sui deliverable' });
    }

    body.innerHTML = `
      <div class="planning-progress-grid">
        ${items.map(it => `
          <div class="planning-progress-item">
            <div>
              <div style="font-weight:700;">${this.escapeHtml(it.label)}</div>
              ${it.sub ? `<div class="planning-muted" style="margin-top:4px; font-size:12px;">${this.escapeHtml(it.sub)}</div>` : ``}
            </div>
            <div>${it.pill}</div>
          </div>
        `).join('')}
      </div>
    `;
  }

  async loadLatestBlueprintForActivePlan() {
    const planId = parseInt(this.activePlanId || '0', 10) || 0;
    if (!planId) return;
    try {
      const res = await this.apiFetch(`consulting_plans/blueprint_latest.php?plan_id=${encodeURIComponent(String(planId))}`, { method: 'GET', json: true });
      const data = res?.data || null;
      if (data && data.compliance_blueprint) {
        this.blueprintResult = data;
        this.blueprintPlanId = planId;
        this.renderBlueprint(data);
      }
    } catch (e) {
      // Best-effort: do not block plan selection if blueprint fetch fails
      console.warn('[Blueprint] latest load failed:', e);
    }
  }

  async loadItems(planId) {
    try {
      const pid = (planId !== undefined && planId !== null && String(planId) !== '')
        ? planId
        : this.activePlanId;
      const id = parseInt(String(pid || '0'), 10) || 0;
      if (!id) throw new Error('plan_id obbligatorio');
      const data = await this.apiFetch(`consulting_plans/items.php?plan_id=${encodeURIComponent(String(id))}`, { method: 'GET' });
      this.items = data.data?.items || [];
      this.renderItems();
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore caricamento attività', 'error');
    }
  }

  async loadClientLocationsForActivePlan() {
    const plan = this.getActivePlan();
    const tid = plan ? (parseInt(plan.client_tenant_id || '0', 10) || 0) : 0;
    if (!tid) {
      this.activePlanClientLocations = [];
      this.activePlanClientLocationsLoadedForTenant = 0;
      return;
    }
    if (this.activePlanClientLocationsLoadedForTenant === tid && Array.isArray(this.activePlanClientLocations)) {
      return;
    }
    try {
      const res = await this.apiFetch(`consulting_plans/client_locations.php?client_tenant_id=${encodeURIComponent(String(tid))}`, { method: 'GET', json: true });
      const locs = Array.isArray(res?.data?.locations) ? res.data.locations : [];
      // Store only id-based locations (tenant_locations table). Legacy locations have id=null and cannot be referenced by id.
      this.activePlanClientLocations = locs.map(l => ({
        id: parseInt(l?.id || '0', 10) || 0,
        label: String(l?.label || ''),
        indirizzo_completo: String(l?.indirizzo_completo || ''),
        comune: String(l?.comune || ''),
        provincia: String(l?.provincia || ''),
        location_type: String(l?.location_type || ''),
        is_primary: !!l?.is_primary,
      })).filter(l => l.id > 0);
      this.activePlanClientLocationsLoadedForTenant = tid;
    } catch (e) {
      console.warn('[Planning] load client locations failed:', e?.message || e);
      this.activePlanClientLocations = [];
      this.activePlanClientLocationsLoadedForTenant = tid;
    }
  }

  renderPlanItemLocationOptions(selectedId) {
    const sel = parseInt(String(selectedId || '0'), 10) || 0;
    const locs = Array.isArray(this.activePlanClientLocations) ? this.activePlanClientLocations : [];
    if (!locs.length) {
      return `<option value="0" selected>— Sede non disponibile —</option>`;
    }
    return `<option value="0"${sel <= 0 ? ' selected' : ''}>— Auto (sede primaria) —</option>` + locs.map(l => {
      const label = String(l.label || '').trim() || (l.location_type === 'sede_operativa' ? 'Sede operativa' : 'Sede legale');
      const where = l.comune ? `${l.comune}${l.provincia ? ` (${l.provincia})` : ''}` : '';
      const full = (label + (where ? ` — ${where}` : '')).trim();
      const s = (sel > 0 && sel === l.id) ? ' selected' : '';
      return `<option value="${l.id}"${s}>${this.escapeHtml(full)}</option>`;
    }).join('');
  }

  // ------------------------------
  // Plan scopes (multi-service / multi-norma)
  // ------------------------------
  async loadPlanScopesForActivePlan() {
    const pid = parseInt(String(this.activePlanId || '0'), 10) || 0;
    if (!pid) {
      this.planScopes = [];
      this.planScopeServiceIds = [];
      this.planScopesStorageAvailable = false;
      this.planScopesDerivedFromEstimate = false;
      return;
    }
    try {
      const res = await this.apiFetch(`consulting_plans/scopes.php?plan_id=${encodeURIComponent(String(pid))}`, { method: 'GET', json: true });
      const data = res?.data || {};
      const scopes = Array.isArray(data?.scopes) ? data.scopes : [];
      this.planScopes = scopes;
      this.planScopesStorageAvailable = !!data?.storage_available;
      this.planScopesDerivedFromEstimate = !!data?.derived_from_estimate;
      this.planScopeServiceIds = scopes
        .map(s => parseInt(s?.activity_type_id || '0', 10) || 0)
        .filter(v => v > 0);
    } catch (e) {
      console.warn('[Planning] plan scopes unavailable:', e?.message || e);
      this.planScopes = [];
      this.planScopeServiceIds = [];
      this.planScopesStorageAvailable = false;
      this.planScopesDerivedFromEstimate = false;
    }
  }

  renderItems() {
    const tbody = document.getElementById('planningItemsTbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!this.activePlanId) {
      tbody.innerHTML = `<tr><td colspan="9" class="planning-muted">Seleziona un piano per vedere le attività.</td></tr>`;
      this.setAddItemEnabled(false);
      this.updateTotals();
      this.renderSchedule();
      return;
    }
    this.setAddItemEnabled(true);

    if (!this.items.length) {
      tbody.innerHTML = `<tr><td colspan="9" class="planning-muted">Nessuna attività. Usa “Aggiungi attività”.</td></tr>`;
      this.updateTotals();
      this.renderSchedule();
      return;
    }

    tbody.innerHTML = this.items.map((it) => this.renderItemRow(it)).join('');
    this.bindItemRowEvents();
    this.updateTotals();
    this.renderSchedule();
  }

  renderItemRow(it) {
    const id = it.id || '';
    const type = it.activity_type || 'remote';
    const date = it.activity_date || '';
    const days = this.num(it.days);
    const hours = this.num(it.hours);
    const km = this.num(it.km);
    const storedDayRate = this.num(it.day_rate);
    const dayRate = storedDayRate > 0 ? storedDayRate : this.dayRateDefault;
    const fixedAmount = this.num(it.fixed_amount);
    const fixedAmountDisplay = fixedAmount > 0 ? String(fixedAmount) : '';
    const kmRate = this.num(it.km_rate || this.kmRateDefault);
    const extras = this.num(it.extras_amount);
    const desc = it.description || '';
    const domainTypeId = parseInt(it.domain_activity_type_id || '0', 10) || 0;
    const hasAssigneeField = Object.prototype.hasOwnProperty.call(it, 'assignee_user_id');
    const assigneeId = parseInt(it.assignee_user_id || '0', 10) || 0;
    const hasLocationField = Object.prototype.hasOwnProperty.call(it, 'location_tenant_location_id');
    const locId = hasLocationField ? (parseInt(it.location_tenant_location_id || '0', 10) || 0) : 0;
    const showLocationBlock = (type === 'onsite' || type === 'travel');
    const locOptions = hasLocationField ? this.renderPlanItemLocationOptions(locId) : '';
    const total = this.calcTotal({ activityType: type, days, hours, dayRate, fixedAmount, km, kmRate, extras });

    const isCallLike = (type === 'call' || type === 'communication');
    const callMinutesRaw = Math.round(this.num(hours) * 60);
    const callMinutes = (callMinutesRaw > 0 ? callMinutesRaw : 30);

    const linked = Array.isArray(it.linked_tasks) ? it.linked_tasks : [];
    const linkedHtml = linked.length ? linked.map(t => `<div class="planning-muted">#${t.task_id}: ${this.escapeHtml(t.title || '')}</div>`).join('') : `<div class="planning-muted">Nessun task</div>`;

    const selectedConsultantIds = Array.isArray(this.selectedConsultantIds) ? this.selectedConsultantIds : [];
    const selectedConsultants = (this.consultants || [])
      .filter(u => selectedConsultantIds.includes(parseInt(u.id, 10)))
      .map(u => ({ id: parseInt(u.id, 10), name: (u.name || u.email || `#${u.id}`) }));
    const hasAssigneeInList = assigneeId > 0 && selectedConsultants.some(u => u.id === assigneeId);
    const extraAssignedOpt = (assigneeId > 0 && !hasAssigneeInList)
      ? `<option value="${assigneeId}" selected>#${this.escapeHtml(String(assigneeId))} (non selezionato)</option>`
      : '';
    const assigneeOptions = `<option value="0"${assigneeId <= 0 ? ' selected' : ''}>— Non assegnato —</option>` + extraAssignedOpt + selectedConsultants.map(u => {
      const sel = (assigneeId === u.id) ? ' selected' : '';
      return `<option value="${u.id}"${sel}>${this.escapeHtml(u.name)}</option>`;
    }).join('');
    const assigneeDisabled = !selectedConsultants.length ? ' disabled' : '';

    return `
      <tr data-item-id="${id}">
        <td>
          <select data-field="activity_type">
            <option value="onsite" ${type === 'onsite' ? 'selected' : ''}>In sito</option>
            <option value="remote" ${type === 'remote' ? 'selected' : ''}>Remoto</option>
            <option value="call" ${type === 'call' ? 'selected' : ''}>Call</option>
            <option value="communication" ${type === 'communication' ? 'selected' : ''}>Comunicazione</option>
            <option value="travel" ${type === 'travel' ? 'selected' : ''}>Trasferta</option>
          </select>
          <div style="margin-top:8px;">
            <select data-field="domain_activity_type_id">
              ${this.renderDomainActivityOptions(domainTypeId)}
            </select>
            <div class="planning-muted" style="margin-top:6px;">Servizio / Norma (parametri)</div>
          </div>
          ${hasAssigneeField ? `
            <div style="margin-top:8px;">
              <select data-field="assignee_user_id"${assigneeDisabled}>
                ${assigneeOptions}
              </select>
              <div class="planning-muted" style="margin-top:6px;">Consulente</div>
            </div>
          ` : ''}
          <div style="margin-top:8px;">
            <input type="date" data-field="activity_date" value="${this.escapeAttr(date)}" />
          </div>
          ${hasLocationField ? `
            <div data-block="location" style="margin-top:8px; ${showLocationBlock ? '' : 'display:none;'}">
              <select data-field="location_tenant_location_id">
                ${locOptions}
              </select>
              <div class="planning-muted" style="margin-top:6px;">Sede attività</div>
            </div>
          ` : ''}
        </td>
        <td>
          <div data-block="duration-days" style="${isCallLike ? 'display:none;' : ''}">
            <input type="number" step="0.5" min="0" data-field="days" value="${days}" />
            <div class="planning-muted" style="margin-top:6px;">gg (step 0.5)</div>
          </div>
          <div data-block="duration-call" style="${isCallLike ? '' : 'display:none;'}">
            <input type="number" step="15" min="30" max="60" data-field="duration_minutes" value="${this.escapeAttr(String(callMinutes))}" />
            <div class="planning-muted" style="margin-top:6px;">min (30–60)</div>
          </div>
        </td>
        <td><input type="number" step="0.01" min="0" data-field="day_rate" value="${dayRate}" /></td>
        <td><input type="number" step="0.01" min="0" data-field="fixed_amount" value="${this.escapeAttr(fixedAmountDisplay)}" /></td>
        <td>
          <input type="number" step="0.1" min="0" data-field="km" value="${km}" />
          ${(type === 'travel' && hasLocationField) ? `<div class="planning-muted" style="margin-top:6px;">KM=0 → calcolo automatico A/R (richiede Consulente + Sede)</div>` : ''}
        </td>
        <td>
          <input type="number" step="0.01" min="0" data-field="extras_amount" value="${extras}" />
          <div class="planning-muted" style="margin-top:6px;">Km rate: ${kmRate.toFixed(2)} €/km</div>
        </td>
        <td>
          <textarea data-field="description" placeholder="Note...">${this.escapeHtml(desc)}</textarea>
        </td>
        <td>
          <div><strong data-field-readonly="total">${this.money(total)}</strong></div>
          <div style="margin-top:8px;">${linkedHtml}</div>
          <div class="planning-actions" style="margin-top:8px;">
            <button type="button" class="btn btn-secondary btn-sm" data-action="createTask">Crea Task</button>
            <button type="button" class="btn btn-secondary btn-sm" data-action="createCalendarEvent">Crea evento</button>
          </div>
        </td>
        <td style="width:120px;">
          <div class="planning-actions">
            <button type="button" class="btn btn-primary btn-sm" data-action="saveRow">Salva</button>
            <button type="button" class="btn btn-danger btn-sm" data-action="deleteRow">Elimina</button>
          </div>
        </td>
      </tr>
    `;
  }

  bindItemRowEvents() {
    const tbody = document.getElementById('planningItemsTbody');
    if (!tbody) return;

    tbody.querySelectorAll('tr[data-item-id]').forEach(tr => {
      tr.querySelectorAll('input,select,textarea').forEach(el => {
        el.addEventListener('input', () => this.updateRowTotal(tr));
        el.addEventListener('change', () => this.updateRowTotal(tr));
      });

      // Improve textarea readability (auto-grow)
      tr.querySelectorAll('textarea[data-field="description"]').forEach(ta => {
        this.autoGrowTextarea(ta);
        ta.addEventListener('input', () => this.autoGrowTextarea(ta));
      });

      // Apply pricing defaults (best-effort) when selecting domain type, but don't override manual edits
      tr.querySelector('select[data-field="domain_activity_type_id"]')?.addEventListener('change', () => {
        const domainSel = tr.querySelector('select[data-field="domain_activity_type_id"]');
        const typeId = parseInt(domainSel?.value || '0', 10) || 0;
        if (!typeId) return;
        const def = this.getDomainPricingDefaults(typeId);
        const dayRateEl = tr.querySelector('input[data-field="day_rate"]');
        const fixedEl = tr.querySelector('input[data-field="fixed_amount"]');
        const curDay = parseFloat(dayRateEl?.value || '0') || 0;
        const curFix = parseFloat(fixedEl?.value || '0') || 0;
        if (dayRateEl && curDay <= 0 && def.default_day_rate > 0) dayRateEl.value = String(def.default_day_rate);
        if (fixedEl && curFix <= 0 && def.default_fixed_amount > 0) fixedEl.value = String(def.default_fixed_amount);
        this.updateRowTotal(tr);
      });

      // Toggle location selector for onsite/travel items (multi-location support)
      tr.querySelector('select[data-field="activity_type"]')?.addEventListener('change', () => {
        const t = tr.querySelector('select[data-field="activity_type"]')?.value || 'remote';

        // Toggle duration inputs (days vs call minutes)
        const isCallLike = (t === 'call' || t === 'communication');
        const daysBlock = tr.querySelector('[data-block="duration-days"]');
        const callBlock = tr.querySelector('[data-block="duration-call"]');
        if (daysBlock) daysBlock.style.display = isCallLike ? 'none' : '';
        if (callBlock) callBlock.style.display = isCallLike ? '' : 'none';
        if (isCallLike) {
          const daysEl = tr.querySelector('input[data-field="days"]');
          const minEl = tr.querySelector('input[data-field="duration_minutes"]');
          if (daysEl) daysEl.value = '0';
          if (minEl) {
            const cur = parseInt(String(minEl.value || '0'), 10) || 0;
            if (!cur) minEl.value = '30';
          }
        } else {
          const daysEl = tr.querySelector('input[data-field="days"]');
          if (daysEl) {
            const cur = parseFloat(String(daysEl.value || '0')) || 0;
            if (cur > 0 && cur < 0.5) daysEl.value = '0.5';
            if (cur === 0) {
              // Best-effort default when switching from call to day-based
              daysEl.value = '0.5';
            }
          }
        }

        const block = tr.querySelector('[data-block="location"]');
        if (block) {
          if (t === 'onsite' || t === 'travel') {
            block.style.display = '';
          } else {
            block.style.display = 'none';
            const sel = block.querySelector('select[data-field="location_tenant_location_id"]');
            if (sel) sel.value = '0';
          }
        }
        this.updateRowTotal(tr);
      });

      tr.querySelector('[data-action="saveRow"]')?.addEventListener('click', () => this.saveRow(tr));
      tr.querySelector('[data-action="deleteRow"]')?.addEventListener('click', () => this.deleteRow(tr));
      tr.querySelector('[data-action="createTask"]')?.addEventListener('click', () => this.createTaskForRow(tr));
      tr.querySelector('[data-action="createCalendarEvent"]')?.addEventListener('click', () => this.openCalendarModalForRow(tr));
    });
  }

  getDomainPricingDefaults(domainTypeId) {
    const id = parseInt(domainTypeId || '0', 10) || 0;
    if (!id) return { default_day_rate: 0, default_fixed_amount: 0 };
    const found = (this.activityTypes || []).find(t => parseInt(t?.base?.id || t?.effective?.id || '0', 10) === id) || null;
    const base = found?.base || found?.effective || {};
    const dr = this.num(base.default_day_rate);
    return {
      default_day_rate: dr > 0 ? dr : this.dayRateDefault,
      default_fixed_amount: this.num(base.default_fixed_amount),
    };
  }

  addEmptyItemRow() {
    if (!this.activePlanId) {
      this.toast('Seleziona un piano', 'error');
      return;
    }
    // Add temporary unsaved row
    const temp = {
      id: 'new_' + Math.random().toString(16).slice(2),
      activity_type: 'remote',
      activity_date: '',
      days: 1,
      hours: 0,
      day_rate: this.dayRateDefault,
      fixed_amount: 0,
      km: 0,
      km_rate: this.kmRateDefault,
      extras_amount: 0,
      description: '',
      assignee_user_id: 0,
      location_tenant_location_id: 0,
      linked_tasks: []
    };
    this.items = [...this.items, temp];
    this.renderItems();
    const tbody = document.getElementById('planningItemsTbody');
    tbody?.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  readRow(tr) {
    const get = (field) => tr.querySelector(`[data-field="${field}"]`);
    const t = get('activity_type')?.value || 'remote';
    const isCallLike = (t === 'call' || t === 'communication');
    const minutes = parseInt(get('duration_minutes')?.value || '0', 10) || 0;
    const hours = isCallLike ? (Math.max(0, minutes) / 60.0) : 0.0;
    const days = isCallLike ? 0.0 : (parseFloat(get('days')?.value || '0') || 0);
    const v = {
      id: tr.getAttribute('data-item-id') || '',
      activity_type: t,
      activity_date: get('activity_date')?.value || '',
      days,
      hours,
      day_rate: parseFloat(get('day_rate')?.value || '0') || 0,
      fixed_amount: parseFloat(get('fixed_amount')?.value || '0') || 0,
      km: parseFloat(get('km')?.value || '0') || 0,
      extras_amount: parseFloat(get('extras_amount')?.value || '0') || 0,
      description: get('description')?.value || '',
      domain_activity_type_id: parseInt(get('domain_activity_type_id')?.value || '0', 10) || 0,
      assignee_user_id: parseInt(get('assignee_user_id')?.value || '0', 10) || 0,
      location_tenant_location_id: parseInt(get('location_tenant_location_id')?.value || '0', 10) || 0,
    };
    return v;
  }

  async saveRow(tr) {
    if (!this.activePlanId) return;
    const row = this.readRow(tr);
    const isNew = String(row.id).startsWith('new_');
    const payload = {
      csrf_token: this.csrfToken,
      plan_id: parseInt(this.activePlanId, 10),
      activity_type: row.activity_type,
      domain_activity_type_id: row.domain_activity_type_id || null,
      activity_date: row.activity_date || null,
      days: row.days,
      hours: row.hours,
      km: row.km,
      day_rate: row.day_rate,
      fixed_amount: row.fixed_amount,
      extras_amount: row.extras_amount,
      description: row.description || null,
      assignee_user_id: row.assignee_user_id || 0,
      location_tenant_location_id: row.location_tenant_location_id || null,
    };
    if (!isNew) payload.id = parseInt(row.id, 10);

    try {
      const data = await this.apiFetch('consulting_plans/items_upsert.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify(payload),
      });
      const savedId = data.data?.id;
      const warns = Array.isArray(data?.data?.warnings) ? data.data.warnings : [];
      this.toast('Salvato', 'success');
      if (warns.length) {
        this.toast(warns[0], 'warning');
      }
      // Ensure task exists for this action (best-effort) BEFORE reload so UI shows it
      try {
        const sid = parseInt(savedId || '0', 10) || 0;
        if (sid > 0) {
          await this.syncPlanItemTasks({ planId: parseInt(this.activePlanId, 10), itemIds: [sid], showToast: false });
        }
      } catch (_) {}
      await this.loadItems(this.activePlanId);
      // focus saved row
      if (savedId) {
        const tbody = document.getElementById('planningItemsTbody');
        const savedTr = tbody?.querySelector(`tr[data-item-id="${savedId}"]`);
        savedTr?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore salvataggio', 'error');
    }
  }

  async deleteRow(tr) {
    const id = tr.getAttribute('data-item-id') || '';
    if (!confirm('Eliminare questa attività?')) return;
    if (String(id).startsWith('new_')) {
      this.items = this.items.filter(x => String(x.id) !== String(id));
      this.renderItems();
      return;
    }
    try {
      await this.apiFetch('consulting_plans/items_delete.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, id: parseInt(id, 10) }),
      });
      this.toast('Eliminata', 'success');
      await this.loadItems(this.activePlanId);
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore eliminazione', 'error');
    }
  }

  async saveAllItems() {
    const tbody = document.getElementById('planningItemsTbody');
    if (!tbody) return;
    const rows = [...tbody.querySelectorAll('tr[data-item-id]')];
    for (const tr of rows) {
      // eslint-disable-next-line no-await-in-loop
      await this.saveRow(tr);
    }
    // One more best-effort sync for the whole plan (covers edge cases)
    try { await this.syncPlanItemTasks({ planId: parseInt(this.activePlanId, 10), showToast: false }); } catch (_) {}
    try { await this.loadItems(this.activePlanId); } catch (_) {}
  }

  async createTaskForRow(tr) {
    const id = tr.getAttribute('data-item-id') || '';
    if (String(id).startsWith('new_')) {
      this.toast('Salva prima l’attività', 'error');
      return;
    }
    try {
      const itemId = parseInt(id, 10) || 0;
      await this.syncPlanItemTasks({ planId: parseInt(this.activePlanId, 10), itemIds: [itemId], showToast: true });
      await this.loadItems(this.activePlanId);
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore creazione task', 'error');
    }
  }

  async syncPlanItemTasks({ planId = 0, itemIds = null, showToast = true } = {}) {
    const pid = parseInt(String(planId || this.activePlanId || '0'), 10) || 0;
    if (!pid) return null;
    const body = { csrf_token: this.csrfToken, plan_id: pid };
    if (Array.isArray(itemIds) && itemIds.length) {
      body.item_ids = itemIds.map(x => parseInt(x, 10)).filter(x => Number.isFinite(x) && x > 0);
    }
    const res = await this.apiFetch('consulting_plans/tasks_sync.php', {
      method: 'POST',
      json: true,
      body: JSON.stringify(body),
    });
    const d = res?.data || {};
    const ok = d.storage_available !== false;
    const created = parseInt(d.created || '0', 10) || 0;
    const updated = parseInt(d.updated || '0', 10) || 0;
    const synced = parseInt(d.synced || '0', 10) || 0;
    const warnings = Array.isArray(d.warnings) ? d.warnings : [];
    if (showToast) {
      if (!ok) {
        this.toast('Task: storage non disponibile (verifica migrazioni)', 'warning');
      } else if (warnings.length) {
        this.toast(`Task sync: ${synced} • creati ${created} • aggiornati ${updated} (con avvisi)`, 'warning');
      } else {
        this.toast(`Task sync: ${synced} • creati ${created} • aggiornati ${updated}`, 'success');
      }
    }
    return d;
  }

  updateRowTotal(tr) {
    const row = this.readRow(tr);
    const total = this.calcTotal({
      activityType: row.activity_type,
      days: row.days,
      hours: row.hours,
      dayRate: row.day_rate,
      fixedAmount: row.fixed_amount,
      km: row.km,
      kmRate: this.kmRateDefault,
      extras: row.extras_amount,
    });
    const el = tr.querySelector('[data-field-readonly="total"]');
    if (el) el.textContent = this.money(total);
    this.updateTotals();
  }

  updateTotals() {
    const tbody = document.getElementById('planningItemsTbody');
    const totalEl = document.getElementById('planningTotalValue');
    if (!tbody || !totalEl) return;
    let sum = 0;
    tbody.querySelectorAll('tr[data-item-id]').forEach(tr => {
      const row = this.readRow(tr);
      sum += this.calcTotal({
        activityType: row.activity_type,
        days: row.days,
        hours: row.hours,
        dayRate: row.day_rate,
        fixedAmount: row.fixed_amount,
        km: row.km,
        kmRate: this.kmRateDefault,
        extras: row.extras_amount,
      });
    });
    totalEl.textContent = this.money(sum);
  }

  calcTotal({ activityType = '', days, hours, dayRate, fixedAmount, km, kmRate, extras }) {
    const d = this.num(days);
    const h = this.num(hours);
    const dr = this.num(dayRate);
    const fx = this.num(fixedAmount);
    const k = this.num(km);
    const kr = this.num(kmRate);
    const ex = this.num(extras);
    let billableDays = d;
    if (billableDays <= 0 && h > 0) {
      // Bill hours proportionally to day_rate (8h/day)
      billableDays = h / 8.0;
    }
    return billableDays * dr + fx + k * kr + ex;
  }

  openPlanModal() {
    const m = document.getElementById('planningPlanModal');
    if (!m) return;
    // populate clients in modal
    const sel = document.getElementById('planningPlanClient');
    if (sel) {
      sel.innerHTML = `<option value="">Seleziona azienda</option>` + this.clients.map(c => {
        const name = (c.denominazione || c.name || `Tenant ${c.id}`);
        return `<option value="${c.id}">${this.escapeHtml(name)} (#${c.id})</option>`;
      }).join('');
    }
    m.style.display = 'flex';
  }

  closePlanModal() {
    const m = document.getElementById('planningPlanModal');
    if (!m) return;
    m.style.display = 'none';
    document.getElementById('planningPlanForm')?.reset();
  }

  async createPlanFromModal() {
    const form = document.getElementById('planningPlanForm');
    if (form && typeof form.reportValidity === 'function') {
      if (!form.reportValidity()) {
        return;
      }
    }

    const client = parseInt(document.getElementById('planningPlanClient')?.value || '0', 10) || 0;
    const title = document.getElementById('planningPlanTitle')?.value || '';
    const status = document.getElementById('planningPlanStatus')?.value || 'draft';
    const ps = document.getElementById('planningPlanStart')?.value || '';
    const pe = document.getElementById('planningPlanEnd')?.value || '';
    const notes = document.getElementById('planningPlanNotes')?.value || '';
    const consultantsSel = document.getElementById('planningPlanConsultants');
    const consultantIds = consultantsSel
      ? [...consultantsSel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0)
      : [];

    if (!client) return this.toast('Seleziona un’azienda cliente', 'error');
    if (!title || title.trim().length < 3) return this.toast('Titolo minimo 3 caratteri', 'error');

    const submitBtn = form?.querySelector('button[type="submit"]');
    const prevLabel = submitBtn ? submitBtn.textContent : '';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Creazione...';
    }

    try {
      const data = await this.apiFetch('consulting_plans/create.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          client_tenant_id: client,
          title,
          status,
          period_start: ps || null,
          period_end: pe || null,
          notes: notes || null,
          consultant_user_ids: consultantIds,
        }),
      });
      const id = data.data?.id;
      this.toast('Piano creato', 'success');
      this.closePlanModal();
      await this.loadPlans();
      if (id) this.selectPlan(id);
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore creazione piano', 'error');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = prevLabel || 'Crea';
      }
    }
  }

  // ------------------------------
  // Catalog / domain activities
  // ------------------------------

  renderDomainActivityOptions(selectedId) {
    const opts = this.activityTypes || [];
    const selected = parseInt(String(selectedId || '0'), 10) || 0;
    const empty = `<option value="0"${selected <= 0 ? ' selected' : ''}>— Servizio / Norma —</option>`;
    if (!opts.length) return empty + `<option value="0" disabled>(Catalogo non disponibile)</option>`;

    // Restrict to plan scope services when available, but always include the currently selected id for legacy rows.
    const allowed = new Set((this.planScopeServiceIds || []).map(v => parseInt(String(v || '0'), 10) || 0).filter(v => v > 0));
    if (selected > 0) allowed.add(selected);
    let filtered = allowed.size
      ? opts.filter(t => {
        const id = parseInt(String(t?.effective?.id || t?.base?.id || '0'), 10) || 0;
        return id > 0 && allowed.has(id);
      })
      : opts;
    if (allowed.size && !filtered.length) filtered = opts;

    return empty + filtered.map(t => {
      const base = t.effective || t.base || {};
      const id = parseInt(String(base?.id || t.effective?.id || t.base?.id || '0'), 10) || 0;
      const name = base?.name || `#${id}`;
      const code = String(base?.service_code || '').trim();
      const inactive = (base?.is_active === false || String(base?.is_active || '') === '0') ? ' (non attivo)' : '';
      const label = code ? `${name} (${code})` : name;
      const sel = selected === id ? ' selected' : '';
      return `<option value="${id}"${sel}>${this.escapeHtml(label)}${inactive}</option>`;
    }).join('');
  }

  async loadActivityTypesForActivePlan() {
    const plan = this.plans.find(p => String(p.id) === String(this.activePlanId));
    const clientTenantId = plan ? parseInt(plan.client_tenant_id, 10) : 0;
    try {
      const data = await this.apiFetch(`consulting_plans/activity_types.php?client_tenant_id=${encodeURIComponent(clientTenantId || '')}`, { method: 'GET' });
      this.activityTypes = data.data?.types || [];
    } catch (e) {
      console.warn('[Planning] activity types unavailable:', e?.message || e);
      this.activityTypes = [];
    }
  }

  async seedServiceCatalog({ showToast = false } = {}) {
    const call = async (actionName) => {
      return await this.apiFetch('consulting_plans/activity_types.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, action: actionName }),
      });
    };
    try {
      const res = await call('seed_services');
      const inserted = parseInt(res?.data?.inserted || '0', 10) || 0;
      const updated = parseInt(res?.data?.updated || '0', 10) || 0;
      if (showToast) {
        const parts = [];
        if (inserted) parts.push(`+${inserted}`);
        if (updated) parts.push(`~${updated}`);
        this.toast(parts.length ? `Catalogo servizi aggiornato (${parts.join(', ')})` : 'Catalogo servizi aggiornato', inserted ? 'success' : 'info');
      }
      return { ok: true, inserted, updated };
    } catch (e) {
      // Back-compat: older action name
      try {
        const res = await call('seed_base');
        const inserted = parseInt(res?.data?.inserted || '0', 10) || 0;
        const updated = parseInt(res?.data?.updated || '0', 10) || 0;
        if (showToast) {
          const parts = [];
          if (inserted) parts.push(`+${inserted}`);
          if (updated) parts.push(`~${updated}`);
          this.toast(parts.length ? `Catalogo servizi aggiornato (${parts.join(', ')})` : 'Catalogo servizi aggiornato', inserted ? 'success' : 'info');
        }
        return { ok: true, inserted, updated };
      } catch (e2) {
        if (showToast) this.toast(e2?.message || e?.message || 'Errore seed catalogo', 'error');
        return { ok: false, inserted: 0, updated: 0 };
      }
    }
  }

  async openActivityCatalogModal() {
    await this.loadActivityCatalog();
    this.openModal('planningActivityCatalogModal');
  }

  async loadActivityCatalog() {
    const tbody = document.getElementById('planningActivityCatalogTbody');
    if (tbody) tbody.innerHTML = `<tr><td colspan="9" class="planning-muted">Caricamento...</td></tr>`;
    try {
      const data = await this.apiFetch('consulting_plans/activity_types.php', { method: 'GET' });
      let types = data.data?.types || [];
      this.activityCatalogTypes = Array.isArray(types) ? types : [];
      // Keep catalog up-to-date with base set (idempotent) - attempt once per session
      if (!this.baseActivitySeedAttempted) {
        this.baseActivitySeedAttempted = true;
        try {
          const seedRes = await this.seedServiceCatalog({ showToast: false });
          if ((seedRes.inserted || 0) > 0 || (seedRes.updated || 0) > 0) {
            const data2 = await this.apiFetch('consulting_plans/activity_types.php', { method: 'GET' });
            types = data2.data?.types || [];
            this.activityCatalogTypes = Array.isArray(types) ? types : [];
            this.renderActivityCatalog(this.activityCatalogTypes);
            await this.loadActivityTypesForActivePlan();
            this.renderItems();
            return;
          }
        } catch (seedErr) {
          console.warn('seed_services failed:', seedErr?.message || seedErr);
        }
      }
      this.renderActivityCatalog(this.activityCatalogTypes);
    } catch (e) {
      console.error(e);
      if (tbody) tbody.innerHTML = `<tr><td colspan="9" class="planning-muted">${this.escapeHtml(e.message || 'Errore caricamento catalogo')}</td></tr>`;
    }
  }

  renderActivityCatalog(types) {
    const tbody = document.getElementById('planningActivityCatalogTbody');
    if (!tbody) return;
    const list = Array.isArray(types) ? types : [];
    this.activityCatalogTypes = list;

    // Update category options (preserve selection)
    const catSel = document.getElementById('planningServiceCatalogCategory');
    const currentCat = (catSel?.value || '').trim();
    const cats = new Set();
    list.forEach(t => {
      const b = t?.base || null;
      const c = b ? String(b.category || '').trim() : '';
      if (c) cats.add(c);
    });
    if (catSel) {
      const sorted = Array.from(cats).sort((a, b) => a.localeCompare(b));
      catSel.innerHTML = `<option value="">Tutte le categorie</option>` + sorted.map(c => `<option value="${this.escapeAttr(c)}">${this.escapeHtml(c)}</option>`).join('');
      if (currentCat) catSel.value = currentCat;
    }

    const q = String(document.getElementById('planningServiceCatalogSearch')?.value || '').trim().toLowerCase();
    const cat = (catSel?.value || '').trim();
    const activeOnly = !!document.getElementById('planningServiceCatalogActiveOnly')?.checked;

    const filtered = list.filter(t => {
      const b = t?.base || null;
      if (!b) return false;
      const codeRaw = String(b.service_code || '').trim().toUpperCase();
      const isLegacy = !!b.legacy_combo || !!b.is_legacy_composite || codeRaw.includes('_') || codeRaw === 'ACCREDIA';
      if (activeOnly && (!b.is_active || isLegacy)) return false;
      if (cat && String(b.category || '').trim() !== cat) return false;
      if (!q) return true;
      const name = String(b.name || '').toLowerCase();
      const code = String(b.service_code || '').toLowerCase();
      const aliases = Array.isArray(b.aliases) ? b.aliases.map(x => String(x || '').toLowerCase()) : [];
      return name.includes(q) || code.includes(q) || aliases.some(a => a.includes(q));
    });

    if (!filtered.length) {
      tbody.innerHTML = `<tr><td colspan="9" class="planning-muted">Nessun servizio trovato.</td></tr>`;
      return;
    }

    const baseById = new Map(list.map(t => [parseInt(t?.base?.id || '0', 10) || 0, (t?.base || null)]));

    tbody.innerHTML = filtered.map(t => {
      const b = t.base || {};
      const code = String(b.service_code || '').trim() || '—';
      const category = String(b.category || '').trim() || '—';
      const min = (b.base_days_min !== null && b.base_days_min !== undefined && b.base_days_min !== '') ? parseInt(b.base_days_min, 10) : NaN;
      const max = (b.base_days_max !== null && b.base_days_max !== undefined && b.base_days_max !== '') ? parseInt(b.base_days_max, 10) : NaN;
      const range = (Number.isFinite(min) && Number.isFinite(max) && max > 0) ? `${min}-${max}` : '—';
      const aliases = Array.isArray(b.aliases) ? b.aliases.map(x => String(x || '').trim()).filter(Boolean) : [];
      const aliasText = aliases.length ? (aliases.slice(0, 3).join(', ') + (aliases.length > 3 ? '…' : '')) : '—';
      let std = Array.isArray(b.standard_codes) ? b.standard_codes.map(s => String(s || '').trim()).filter(Boolean) : [];
      const inferStdWhitelist = new Set([
        'ISO9001','ISO14001','ISO45001','ISO22000','ISO50001','ISO37001','ISOIEC17025','SA8000','PDR125',
        'UNI16636','HACCP','BRC','IFS','GDP','PRIVACY','ODV231','ACCRED','EMAS','CE','RT12','NORMA10891','NORMA13895',
        'BRCBROKERS','BIO',
      ]);
      const codeUp = String(code || '').trim().toUpperCase();
      if (!std.length && inferStdWhitelist.has(codeUp)) std = [codeUp];
      const stdHtml = std.length
        ? std.slice(0, 4).map(s => `<span class="planning-pill" style="font-size:11px; font-weight:800; padding:2px 7px;">${this.escapeHtml(s)}</span>`).join(' ')
        : '<span class="planning-muted">—</span>';

      const schemeType = String(b.scheme_type || '').trim().toUpperCase();
      const schemeHtml = schemeType
        ? `<span class="planning-pill" style="font-size:11px; font-weight:800; padding:2px 7px;">${this.escapeHtml(schemeType)}</span>`
        : '';

      const isLegacy = !!b.legacy_combo || !!b.is_legacy_composite || codeUp.includes('_') || codeUp === 'ACCREDIA';
      const activeLabel = b.is_active ? 'Attivo' : 'Inattivo';
      const activeClass = b.is_active ? 'planning-pill done' : 'planning-pill draft';
      const stateHtml = `
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
          <span class="${activeClass}" style="font-size:11px; padding:2px 7px;">${this.escapeHtml(activeLabel)}</span>
          ${isLegacy ? `<span class="planning-pill cancelled" style="font-size:11px; padding:2px 7px;">LEGACY</span>` : ''}
        </div>
      `;

      const callEvery = parseInt(b.call_every_days_default || 0, 10) || 0;
      const callDur = parseInt(b.call_duration_minutes_default || 0, 10) || 0;
      const callTxt = (callEvery > 0 || callDur > 0)
        ? `${callEvery > 0 ? (callEvery + 'g') : '—'} • ${callDur > 0 ? (callDur + 'm') : '—'}`
        : '—';
      const dr = this.num(b.default_day_rate);
      const dayRateTxt = (dr > 0 ? dr : this.dayRateDefault).toFixed(2);

      return `
        <tr data-atype-id="${b.id}">
          <td class="planning-muted">${this.escapeHtml(code)}</td>
          <td>
            <div>${this.escapeHtml(b.name || '')}</div>
            <div class="planning-muted" style="margin-top:4px;" title="${this.escapeHtml(aliases.join(', '))}">Alias: ${this.escapeHtml(aliasText)}</div>
          </td>
          <td>
            <div class="planning-muted">${this.escapeHtml(category)}</div>
            ${schemeHtml ? `<div style="margin-top:4px;">${schemeHtml}</div>` : ''}
          </td>
          <td>${stdHtml}</td>
          <td class="planning-muted">${this.escapeHtml(range)}</td>
          <td class="planning-muted">${this.escapeHtml(dayRateTxt)}</td>
          <td class="planning-muted">${this.escapeHtml(callTxt)}</td>
          <td>${stateHtml}</td>
          <td>
            <div class="planning-actions">
              <button type="button" class="btn btn-secondary btn-sm" data-action="edit">Modifica</button>
              <button type="button" class="btn btn-danger btn-sm" data-action="delete">Elimina</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    tbody.querySelectorAll('tr[data-atype-id]').forEach(tr => {
      const id = parseInt(tr.getAttribute('data-atype-id'), 10) || 0;
      tr.querySelector('[data-action="edit"]')?.addEventListener('click', () => {
        const b = baseById.get(id) || null;
        this.openActivityTypeModal(b);
      });
      tr.querySelector('[data-action="delete"]')?.addEventListener('click', async () => {
        if (!confirm('Eliminare questo servizio dal catalogo?')) return;
        try {
          await this.apiFetch('consulting_plans/activity_types.php', {
            method: 'POST',
            json: true,
            body: JSON.stringify({ csrf_token: this.csrfToken, action: 'delete', id }),
          });
          this.toast('Servizio eliminato', 'success');
          await this.loadActivityCatalog();
          await this.loadActivityTypesForActivePlan();
          this.renderItems();
        } catch (e) {
          console.error(e);
          this.toast(e.message || 'Errore eliminazione', 'error');
        }
      });
    });
  }

  openActivityTypeModal(existing = null) {
    document.getElementById('planningActivityTypeId').value = existing?.id ? String(existing.id) : '';
    document.getElementById('planningActivityTypeName').value = existing?.name || '';
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v === null || v === undefined) ? '' : String(v); };
    setVal('planningActivityTypeServiceCode', (existing && Object.prototype.hasOwnProperty.call(existing, 'service_code')) ? (existing.service_code || '') : '');
    setVal('planningActivityTypeCategory', (existing && Object.prototype.hasOwnProperty.call(existing, 'category')) ? (existing.category || '') : '');
    setVal('planningActivityTypeSchemeType', (existing && Object.prototype.hasOwnProperty.call(existing, 'scheme_type')) ? (existing.scheme_type || '') : '');
    const aliasArr = Array.isArray(existing?.aliases) ? existing.aliases.map(x => String(x || '').trim()).filter(Boolean) : [];
    setVal('planningActivityTypeAliases', aliasArr.join('\n'));
    const stdArr = Array.isArray(existing?.standard_codes) ? existing.standard_codes.map(x => String(x || '').trim()).filter(Boolean) : [];
    setVal('planningActivityTypeStandardCodes', stdArr.join('\n'));
    setVal('planningActivityTypeBaseDaysMin', (existing && Object.prototype.hasOwnProperty.call(existing, 'base_days_min')) ? (existing.base_days_min ?? '') : '');
    setVal('planningActivityTypeBaseDaysMax', (existing && Object.prototype.hasOwnProperty.call(existing, 'base_days_max')) ? (existing.base_days_max ?? '') : '');
    setVal('planningActivityTypeComplexityScore', (existing && Object.prototype.hasOwnProperty.call(existing, 'complexity_score')) ? (existing.complexity_score ?? '') : '');

    // Advanced JSON (store as pretty JSON for editing)
    const phases = Array.isArray(existing?.default_phases) ? existing.default_phases : [];
    const dels = Array.isArray(existing?.default_deliverables) ? existing.default_deliverables : [];
    setVal('planningActivityTypeDefaultPhasesJson', phases.length ? JSON.stringify(phases, null, 2) : '');
    setVal('planningActivityTypeDefaultDeliverablesJson', dels.length ? JSON.stringify(dels, null, 2) : '');

    document.getElementById('planningActivityTypeWeight').value = (existing && existing.weight_factor !== undefined && existing.weight_factor !== null) ? String(existing.weight_factor) : '';
    try {
      const dr = (existing && existing.default_day_rate !== undefined && existing.default_day_rate !== null) ? parseFloat(String(existing.default_day_rate)) : 0;
      document.getElementById('planningActivityTypeDefaultDayRate').value = (Number.isFinite(dr) && dr > 0) ? String(dr) : String(this.dayRateDefault);
    } catch (_) {
      document.getElementById('planningActivityTypeDefaultDayRate').value = String(this.dayRateDefault);
    }
    try {
      const fx = (existing && existing.default_fixed_amount !== undefined && existing.default_fixed_amount !== null) ? parseFloat(String(existing.default_fixed_amount)) : 0;
      document.getElementById('planningActivityTypeDefaultFixedAmount').value = (Number.isFinite(fx) && fx > 0) ? String(fx) : '';
    } catch (_) {
      document.getElementById('planningActivityTypeDefaultFixedAmount').value = '';
    }
    document.getElementById('planningActivityTypeCallEvery').value = (existing && existing.call_every_days_default !== undefined && existing.call_every_days_default !== null) ? String(existing.call_every_days_default) : '';
    document.getElementById('planningActivityTypeCallDur').value = (existing && existing.call_duration_minutes_default !== undefined && existing.call_duration_minutes_default !== null) ? String(existing.call_duration_minutes_default) : '';
    document.getElementById('planningActivityTypeActive').value = (existing && existing.is_active === false) ? '0' : '1';
    document.getElementById('planningActivityTypeModalTitle').textContent = existing?.id ? 'Modifica servizio' : 'Nuovo servizio';
    // Auto-derive weight + call cadence from complexity (requested mapping)
    try {
      const cx = parseInt(String(document.getElementById('planningActivityTypeComplexityScore')?.value || '0'), 10) || 0;
      if (cx >= 1 && cx <= 5) {
        const weight = 1 + (cx - 1) * (0.5 / 4);
        const callEvery = 90 - (cx - 1) * 15;
        const wEl = document.getElementById('planningActivityTypeWeight');
        const cEl = document.getElementById('planningActivityTypeCallEvery');
        if (wEl) wEl.value = Number.isFinite(weight) ? weight.toFixed(2) : '';
        if (cEl) cEl.value = String(callEvery);
      }
    } catch (_) {}
    this.openModal('planningActivityTypeModal');
  }

  async saveActivityTypeFromModal() {
    const idRaw = document.getElementById('planningActivityTypeId')?.value || '';
    const id = parseInt(idRaw, 10) || 0;
    const name = document.getElementById('planningActivityTypeName')?.value || '';
    const serviceCode = (document.getElementById('planningActivityTypeServiceCode')?.value || '').trim();
    const category = (document.getElementById('planningActivityTypeCategory')?.value || '').trim();
    const schemeType = (document.getElementById('planningActivityTypeSchemeType')?.value || '').trim();
    const aliasesRaw = String(document.getElementById('planningActivityTypeAliases')?.value || '');
    const aliases = aliasesRaw
      .split(/\r?\n|,/g)
      .map(s => s.trim())
      .filter(Boolean);
    const stdRaw = String(document.getElementById('planningActivityTypeStandardCodes')?.value || '');
    const standardCodes = Array.from(new Set(
      stdRaw
        .split(/\r?\n|,/g)
        .map(s => String(s || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, ''))
        .filter(Boolean)
    ));
    const baseMinRaw = (document.getElementById('planningActivityTypeBaseDaysMin')?.value || '').trim();
    const baseMaxRaw = (document.getElementById('planningActivityTypeBaseDaysMax')?.value || '').trim();
    const baseMin = baseMinRaw === '' ? null : (parseInt(baseMinRaw, 10) || 0);
    const baseMax = baseMaxRaw === '' ? null : (parseInt(baseMaxRaw, 10) || 0);
    if (baseMin !== null && baseMin < 0) return this.toast('Giornate base min non valide', 'error');
    if (baseMax !== null && baseMax < 0) return this.toast('Giornate base max non valide', 'error');
    if (baseMin !== null && baseMax !== null && baseMax < baseMin) return this.toast('Giornate base max deve essere >= min', 'error');
    const cxRaw = (document.getElementById('planningActivityTypeComplexityScore')?.value || '').trim();
    const complexityScore = cxRaw === '' ? null : (parseInt(cxRaw, 10) || 0);
    if (complexityScore !== null && (complexityScore < 1 || complexityScore > 5)) return this.toast('Complessità non valida (1-5)', 'error');

    const phasesRaw = (document.getElementById('planningActivityTypeDefaultPhasesJson')?.value || '').trim();
    const deliverablesRaw = (document.getElementById('planningActivityTypeDefaultDeliverablesJson')?.value || '').trim();
    let defaultPhases = null;
    let defaultDeliverables = null;
    if (phasesRaw !== '') {
      try { defaultPhases = JSON.parse(phasesRaw); } catch (_) { return this.toast('Fasi JSON non valido', 'error'); }
    }
    if (deliverablesRaw !== '') {
      try { defaultDeliverables = JSON.parse(deliverablesRaw); } catch (_) { return this.toast('Deliverable JSON non valido', 'error'); }
    }

    const weightRaw = (document.getElementById('planningActivityTypeWeight')?.value || '').trim();
    const defDayRateRaw = (document.getElementById('planningActivityTypeDefaultDayRate')?.value || '').trim();
    const defFixedRaw = (document.getElementById('planningActivityTypeDefaultFixedAmount')?.value || '').trim();
    const callEveryRaw = (document.getElementById('planningActivityTypeCallEvery')?.value || '').trim();
    const callDurRaw = (document.getElementById('planningActivityTypeCallDur')?.value || '').trim();
    const active = (document.getElementById('planningActivityTypeActive')?.value || '1') === '1';
    if (!name || name.trim().length < 1) {
      this.toast('Nome servizio obbligatorio', 'error');
      return;
    }
    try {
      const action = id > 0 ? 'update' : 'create';
      await this.apiFetch('consulting_plans/activity_types.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          action,
          id: id || undefined,
          name: name.trim(),
          service_code: serviceCode || null,
          category: category || null,
          scheme_type: schemeType || null,
          aliases: aliases,
          standard_codes: standardCodes,
          base_days_min: baseMin,
          base_days_max: baseMax,
          complexity_score: complexityScore,
          default_phases: defaultPhases,
          default_deliverables: defaultDeliverables,
          weight_factor: weightRaw === '' ? undefined : parseFloat(weightRaw),
          default_day_rate: defDayRateRaw === '' ? undefined : parseFloat(defDayRateRaw),
          default_fixed_amount: defFixedRaw === '' ? undefined : parseFloat(defFixedRaw),
          call_every_days_default: callEveryRaw === '' ? undefined : parseInt(callEveryRaw, 10),
          call_duration_minutes_default: callDurRaw === '' ? undefined : parseInt(callDurRaw, 10),
          is_active: active,
        }),
      });
      this.toast('Servizio salvato', 'success');
      this.closeModal('planningActivityTypeModal');
      await this.loadActivityCatalog();
      await this.loadActivityTypesForActivePlan();
      this.renderItems();
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore salvataggio', 'error');
    }
  }

  // ------------------------------
  // Consultants (per plan)
  // ------------------------------

  async loadConsultantsListForCreate() {
    try {
      const data = await this.apiFetch('consulting_plans/consultants.php', { method: 'GET' });
      this.consultants = data.data?.consultants || [];
      const sel = document.getElementById('planningPlanConsultants');
      if (sel) {
        sel.innerHTML = this.consultants.map(u => `<option value="${u.id}">${this.escapeHtml(u.name || u.email || `#${u.id}`)}</option>`).join('');
      }
    } catch (e) {
      console.warn('[Planning] consultants list unavailable:', e?.message || e);
      this.consultants = [];
    }
  }

  async loadConsultantsForActivePlan() {
    const bar = document.getElementById('planningConsultantsBar');
    if (bar) bar.style.display = this.activePlanId ? 'block' : 'none';
    if (!this.activePlanId) return;
    try {
      const data = await this.apiFetch(`consulting_plans/consultants.php?plan_id=${encodeURIComponent(this.activePlanId)}`, { method: 'GET' });
      this.homeCityStorageAvailable = !!data.data?.home_city_storage_available;
      this.consultants = data.data?.consultants || this.consultants;
      const selectedDetails = Array.isArray(data.data?.selected_consultants) ? data.data.selected_consultants : [];
      if (!this.consultantHomeCityById) this.consultantHomeCityById = new Map();
      if (selectedDetails.length) {
        this.selectedConsultantIds = selectedDetails
          .map(x => parseInt(x?.user_id || x?.id || '0', 10) || 0)
          .filter(v => v > 0);
        this.consultantHomeCityById = new Map(selectedDetails.map(x => {
          const uid = parseInt(x?.user_id || x?.id || '0', 10) || 0;
          const city = (x && typeof x === 'object' && x.home_city) ? String(x.home_city) : '';
          return [uid, city];
        }).filter(([uid]) => uid > 0));
      } else {
        this.selectedConsultantIds = data.data?.selected_user_ids || [];
      }
      this.renderSelectedConsultantsText();
      this.updateOnboardingVisibility();
      // Populate modal select list (lazy)
      const sel = document.getElementById('planningConsultantsSelect');
      if (sel) {
        sel.innerHTML = (this.consultants || []).map(u => {
          const selected = this.selectedConsultantIds.includes(parseInt(u.id, 10)) ? ' selected' : '';
          return `<option value="${u.id}"${selected}>${this.escapeHtml(u.name || u.email || `#${u.id}`)}</option>`;
        }).join('');
      }
      this.renderConsultantsHomeCityForm();
    } catch (e) {
      console.warn('[Planning] load consultants for plan failed:', e?.message || e);
      this.selectedConsultantIds = [];
      this.homeCityStorageAvailable = false;
      this.consultantHomeCityById = new Map();
      this.renderSelectedConsultantsText();
      this.updateOnboardingVisibility();
    }
  }

  renderSelectedConsultantsText() {
    const el = document.getElementById('planningSelectedConsultantsText');
    if (!el) return;
    if (!this.selectedConsultantIds.length) {
      el.textContent = '— (non selezionati)';
      return;
    }
    const names = (this.consultants || [])
      .filter(u => this.selectedConsultantIds.includes(parseInt(u.id, 10)))
      .map(u => u.name || u.email || `#${u.id}`);
    el.textContent = names.join(', ');
  }

  openConsultantsModal() {
    if (!this.activePlanId) {
      this.toast('Seleziona un piano', 'error');
      return;
    }
    this.renderConsultantsHomeCityForm();
    this.openModal('planningConsultantsModal');
  }

  planHasOnsiteItems() {
    return (this.items || []).some(it => String(it?.activity_type || '').toLowerCase() === 'onsite');
  }

  renderConsultantsHomeCityForm() {
    const wrap = document.getElementById('planningConsultantsHomeCityWrap');
    if (!wrap) return;
    const sel = document.getElementById('planningConsultantsSelect');
    if (!sel) { wrap.innerHTML = ''; return; }
    const ids = [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0);
    if (!ids.length) {
      wrap.innerHTML = `<div class="planning-muted">Nessun consulente selezionato.</div>`;
      return;
    }

    const requireCity = this.planHasOnsiteItems();
    const byId = new Map((this.consultants || []).map(u => [parseInt(u.id, 10) || 0, u]));
    const reqMark = requireCity ? '<span class="planning-muted"> *</span>' : '';

    const rows = ids.map(uid => {
      const u = byId.get(uid) || {};
      const name = this.escapeHtml(u.name || u.email || `#${uid}`);
      // Value precedence:
      // 1) per-plan override (consulting_plan_consultants.home_city)
      // 2) user default (users.home_city from utenti.php) when available
      let cityVal = '';
      try {
        if (this.consultantHomeCityById && this.consultantHomeCityById.has(uid)) {
          cityVal = String(this.consultantHomeCityById.get(uid) || '').trim();
        }
      } catch (_) {}
      if (!cityVal) {
        const def = (u && typeof u === 'object') ? (u.home_city || u.default_home_city || '') : '';
        cityVal = String(def || '').trim();
      }
      const city = this.escapeAttr(cityVal);
      const placeholder = requireCity ? 'Es. Milano (obbligatorio)' : 'Es. Milano';
      return `
        <tr>
          <td>${name}</td>
          <td>
            <input class="form-control" data-home-city-user-id="${uid}" value="${city}" placeholder="${this.escapeAttr(placeholder)}" />
            <div class="form-text" data-city-validation-for="${uid}"></div>
            ${requireCity ? '<div class="form-text">Obbligatoria se il piano ha attività on-site.</div>' : ''}
          </td>
        </tr>
      `;
    }).join('');

    wrap.innerHTML = `
      <div style="margin-bottom:8px; font-weight:700;">Città di partenza (per ottimizzare trasferte)${requireCity ? reqMark : ''}</div>
      <div class="planning-muted" style="margin-bottom:10px;">Inserisci la città di partenza per ogni consulente selezionato. Nessun testo ISO/UNI nei campi.</div>
      <table class="planning-table" style="margin:0;">
        <thead><tr><th>Consulente</th><th>Città di partenza</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    `;

    // Best-effort: validate cities against Italian municipalities (to enable travel optimization).
    try {
      this.bindConsultantsHomeCityValidation(wrap);
    } catch (_) {}
  }

  normalizeCityInput(city) {
    const raw = String(city || '').trim();
    if (!raw) return '';
    // Strip trailing province in parentheses: "Milano (MI)" -> "Milano"
    const m = raw.match(/^(.*)\s*\([A-Z]{2}\)\s*$/);
    const base = (m && m[1]) ? String(m[1]).trim() : raw;
    return base.replace(/\s+/g, ' ').trim();
  }

  setCityInputVisualState(inputEl, state) {
    try {
      if (!inputEl) return;
      if (state === 'valid') {
        inputEl.style.borderColor = 'var(--color-success-500, #22c55e)';
        inputEl.style.boxShadow = '0 0 0 3px rgba(34,197,94,.12)';
      } else if (state === 'invalid') {
        inputEl.style.borderColor = 'var(--color-danger-500, #ef4444)';
        inputEl.style.boxShadow = '0 0 0 3px rgba(239,68,68,.10)';
      } else {
        inputEl.style.borderColor = '';
        inputEl.style.boxShadow = '';
      }
    } catch (_) {}
  }

  async validateItalianMunicipality(city) {
    const q = this.normalizeCityInput(city);
    if (!q || q.length < 2) return { ok: true, query: q, skipped: true };

    if (!this._municipalityCache) this._municipalityCache = new Map();
    const cacheKey = q.toLowerCase();
    if (this._municipalityCache.has(cacheKey)) return this._municipalityCache.get(cacheKey);

    try {
      const data = await this.apiFetch(`locations/search_municipalities.php?q=${encodeURIComponent(q)}&limit=8`, { method: 'GET' });
      const results = Array.isArray(data?.data?.results) ? data.data.results : [];
      const exact = results.find(r => String(r?.name || '').toLowerCase() === q.toLowerCase()) || null;
      const out = exact ? {
        ok: true,
        query: q,
        municipality: {
          name: String(exact.name || ''),
          province_code: String(exact.province_code || ''),
          province_name: String(exact.province_name || ''),
          region: String(exact.region || ''),
        },
      } : {
        ok: false,
        query: q,
        suggestions: results.slice(0, 5).map(r => ({
          name: String(r?.name || ''),
          province_code: String(r?.province_code || ''),
          province_name: String(r?.province_name || ''),
          region: String(r?.region || ''),
        })).filter(x => x.name && x.province_code),
      };
      this._municipalityCache.set(cacheKey, out);
      return out;
    } catch (e) {
      // If locations system is unavailable, don't block UX (server will still enforce when available).
      const out = { ok: true, query: q, skipped: true, warning: 'validation_unavailable' };
      this._municipalityCache.set(cacheKey, out);
      return out;
    }
  }

  bindConsultantsHomeCityValidation(wrapEl) {
    if (!wrapEl) return;
    const inputs = [...wrapEl.querySelectorAll('input[data-home-city-user-id]')];
    inputs.forEach((input) => {
      const uid = parseInt(String(input.getAttribute('data-home-city-user-id') || '0'), 10) || 0;
      if (!uid) return;

      const validateAndRender = async () => {
        const box = wrapEl.querySelector(`[data-city-validation-for="${uid}"]`);
        const val = String(input.value || '').trim();
        const norm = this.normalizeCityInput(val);

        if (!box) return;
        if (!norm || norm.length < 2) {
          box.innerHTML = '';
          this.setCityInputVisualState(input, 'none');
          return;
        }

        const res = await this.validateItalianMunicipality(norm);
        if (res && res.ok && !res.skipped) {
          this.setCityInputVisualState(input, 'valid');
          const m = res.municipality || {};
          const label = `${this.escapeHtml(m.name || norm)}${m.province_code ? ' (' + this.escapeHtml(m.province_code) + ')' : ''}`;
          box.innerHTML = `<span style="color: var(--color-success-700, #15803d); font-weight:600;">Comune valido: ${label}</span>`;
          return;
        }

        if (res && res.ok && res.skipped) {
          // Validation infra unavailable or user typed too short: keep neutral.
          box.innerHTML = '';
          this.setCityInputVisualState(input, 'none');
          return;
        }

        // Invalid: show suggestions (clickable)
        this.setCityInputVisualState(input, 'invalid');
        const sugg = Array.isArray(res?.suggestions) ? res.suggestions : [];
        if (!sugg.length) {
          box.innerHTML = `<span style="color: var(--color-danger-700, #b91c1c); font-weight:600;">Comune non trovato. Inserisci un comune italiano valido.</span>`;
          return;
        }

        const btns = sugg.map((s, idx) => {
          const label = `${this.escapeHtml(s.name)} (${this.escapeHtml(s.province_code)})`;
          return `<button type="button" class="btn btn-secondary btn-sm" style="padding:4px 8px; font-size:12px; margin-right:6px; margin-top:6px;" data-city-suggest-uid="${uid}" data-city-suggest-name="${this.escapeAttr(s.name)}">${label}</button>`;
        }).join('');

        box.innerHTML = `
          <div style="color: var(--color-danger-700, #b91c1c); font-weight:600;">Comune non trovato.</div>
          <div class="planning-muted" style="margin-top:4px;">Suggerimenti:</div>
          <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:4px;">${btns}</div>
        `;

        box.querySelectorAll(`[data-city-suggest-uid="${uid}"][data-city-suggest-name]`).forEach(btn => {
          btn.addEventListener('click', () => {
            const name = String(btn.getAttribute('data-city-suggest-name') || '').trim();
            if (!name) return;
            input.value = name;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
          });
        });
      };

      const debounced = this.debounce(() => { validateAndRender(); }, 250);
      input.addEventListener('input', debounced);
      input.addEventListener('blur', () => { validateAndRender(); });

      // Initial validate (best-effort) when prefilled.
      if (String(input.value || '').trim().length >= 2) {
        validateAndRender();
      }
    });
  }

  async saveConsultantsForActivePlan() {
    if (!this.activePlanId) return;
    const sel = document.getElementById('planningConsultantsSelect');
    const ids = sel ? [...sel.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0) : [];
    const requireCity = this.planHasOnsiteItems();
    const byId = new Map((this.consultants || []).map(u => [parseInt(u.id, 10) || 0, u]));
    const wrap = document.getElementById('planningConsultantsHomeCityWrap');
    const consultants = ids.map(uid => {
      const input = wrap ? wrap.querySelector(`[data-home-city-user-id="${uid}"]`) : null;
      const city = input ? String(input.value || '').trim() : '';
      return { user_id: uid, home_city: city || null };
    });
    if (requireCity) {
      const missing = consultants.filter(c => !c.home_city).map(c => {
        const u = byId.get(c.user_id) || {};
        return (u.name || u.email || `#${c.user_id}`);
      });
      if (missing.length) {
        this.toast('Inserisci la città di partenza per: ' + missing.join(', '), 'error');
        return;
      }
    }

    // Validate city existence (best-effort). Backend also enforces when Italian locations tables exist.
    const invalid = [];
    for (const c of consultants) {
      if (!c.home_city) continue;
      const res = await this.validateItalianMunicipality(c.home_city);
      if (res && res.ok) continue;
      const u = byId.get(c.user_id) || {};
      const label = (u.name || u.email || `#${c.user_id}`);
      invalid.push({ label, home_city: c.home_city, suggestions: res?.suggestions || [] });
    }
    if (invalid.length) {
      const first = invalid[0];
      const sug = (first.suggestions || []).slice(0, 3).map(s => `${s.name} (${s.province_code})`).join(', ');
      this.toast(`Comune non valido per ${first.label}: "${first.home_city}". ${sug ? 'Suggerimenti: ' + sug : ''}`, 'error');
      return;
    }
    try {
      await this.apiFetch('consulting_plans/consultants.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, plan_id: parseInt(this.activePlanId, 10), consultants }),
      });
      this.selectedConsultantIds = ids;
      this.consultantHomeCityById = new Map(consultants.map(c => [c.user_id, c.home_city || '']));
      this.renderSelectedConsultantsText();
      try { this.renderAllocationPanel(); } catch (_) {}
      this.toast('Consulenti salvati', 'success');
      this.closeModal('planningConsultantsModal');
    } catch (e) {
      console.error(e);
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e.message || 'Errore salvataggio consulenti';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    }
  }

  // ------------------------------
  // Allocation UI (Services x Consultants -> apply to items.assignee_user_id)
  // ------------------------------
  getPlanEstimateObject(plan) {
    if (!plan) return null;
    try {
      const raw = plan.estimate_json;
      if (!raw) return null;
      const obj = (typeof raw === 'string') ? JSON.parse(raw) : raw;
      return (obj && typeof obj === 'object') ? obj : null;
    } catch (_) {
      return null;
    }
  }

  getServiceIdsForAllocation(plan, items) {
    const ids = new Set();
    // Prefer items domain_activity_type_id (real plan rows)
    (items || []).forEach(it => {
      const sid = parseInt(it?.domain_activity_type_id || '0', 10) || 0;
      if (sid > 0) ids.add(sid);
    });
    if (ids.size) return Array.from(ids);

    // Fallback to estimate_json (if items not yet generated)
    const est = this.getPlanEstimateObject(plan);
    const estimates = Array.isArray(est?.estimates) ? est.estimates : [];
    estimates.forEach(e => {
      const sid = parseInt(e?.service_type_id || '0', 10) || 0;
      if (sid > 0) ids.add(sid);
    });
    return Array.from(ids);
  }

  getServiceTotals(plan, items) {
    const totals = new Map(); // serviceId -> days
    const byItems = new Map();
    (items || []).forEach(it => {
      const sid = parseInt(it?.domain_activity_type_id || '0', 10) || 0;
      if (sid <= 0) return;
      const d = parseFloat(String(it?.days || '0')) || 0;
      byItems.set(sid, (byItems.get(sid) || 0) + d);
    });
    if (byItems.size) return byItems;

    const est = this.getPlanEstimateObject(plan);
    const estimates = Array.isArray(est?.estimates) ? est.estimates : [];
    estimates.forEach(e => {
      const sid = parseInt(e?.service_type_id || '0', 10) || 0;
      if (sid <= 0) return;
      const d = parseFloat(String(e?.suggested_days || e?.days || '0')) || 0;
      totals.set(sid, d);
    });
    return totals;
  }

  getServiceLabelById(serviceId) {
    const id = parseInt(serviceId, 10) || 0;
    const t = (this.activityTypes || []).find(x => String(x?.base?.id || '') === String(id)) || null;
    const base = t?.base || t?.effective || null;
    const name = base?.name || `Servizio ${id}`;
    const code = (base?.service_code || '').trim();
    return code ? `${name} (${code})` : name;
  }

  renderAllocationPanel() {
    const wrap = document.getElementById('planningAllocationMatrixWrap');
    const msg = document.getElementById('planningAllocationMsg');
    if (!wrap) return;

    // Best-effort: prefetch consultant capacities so we can auto-suggest allocation
    // (non-blocking; will rerender once loaded)
    if (!this.consultantCapacityPrefetching && (!this.consultantCapacityCache || (Date.now() - (this.consultantCapacityLoadedAt || 0)) > 60_000)) {
      this.consultantCapacityPrefetching = true;
      this.loadConsultantCapacityList({ force: false })
        .then(() => { try { this.renderAllocationPanel(); } catch (_) {} })
        .catch(() => {})
        .finally(() => { this.consultantCapacityPrefetching = false; });
    }

    if (!this.activePlanId) {
      wrap.innerHTML = '';
      if (msg) { msg.style.display = 'block'; msg.innerHTML = '<div class="planning-muted">Seleziona un piano.</div>'; }
      return;
    }
    if (!this.selectedConsultantIds || !this.selectedConsultantIds.length) {
      wrap.innerHTML = '';
      if (msg) { msg.style.display = 'block'; msg.innerHTML = '<div class="planning-muted">Seleziona prima i consulenti del piano.</div>'; }
      return;
    }

    const plan = this.getActivePlan();
    const est = this.getPlanEstimateObject(plan);
    const items = Array.isArray(this.items) ? this.items : [];
    // Keep a snapshot for other allocation actions (AI suggest, apply, etc.)
    this._allocationPanelCtx = { plan, est, items };

    const serviceIds = this.getServiceIdsForAllocation(plan, items);
    if (!serviceIds.length) {
      // Even without service IDs, still show the plan activities (so user can assign consultants per-row)
      wrap.innerHTML = `<div id="planningAllocationItemsWrap"></div>`;
      if (msg) {
        msg.style.display = 'block';
        msg.innerHTML = '<div class="planning-muted">Nessun servizio trovato: assegna un servizio alle righe (tab Attività) o rigenera le attività dalla stima. Puoi comunque assegnare i consulenti alle attività qui sotto.</div>';
      }
      try { this.renderAllocationItemsList({ container: wrap.querySelector('#planningAllocationItemsWrap'), plan, items, est }); } catch (_) {}
      return;
    }

    const totals = this.getServiceTotals(plan, items); // Map

    // Existing allocation (if persisted)
    const existingAlloc = (est && typeof est === 'object' && est.allocation && typeof est.allocation === 'object') ? est.allocation : null;

    const capacityById = new Map();
    try {
      const capList = Array.isArray(this.consultantCapacityCache?.consultants) ? this.consultantCapacityCache.consultants : [];
      capList.forEach(c => {
        const uid = parseInt(c?.id || '0', 10) || 0;
        if (!uid) return;
        const d = (c?.available_days === null || c?.available_days === undefined) ? NaN : parseFloat(String(c.available_days));
        capacityById.set(uid, Number.isFinite(d) ? Math.max(0, d) : 0);
      });
    } catch (_) {}

    const allocateHalfUnits = (totalUnits, weights) => {
      const tu = Math.max(0, parseInt(totalUnits || 0, 10) || 0);
      if (!weights || !weights.length) return [];
      let ws = weights.map(w => Math.max(0, parseFloat(String(w || 0)) || 0));
      let sum = ws.reduce((a, b) => a + b, 0);
      if (sum <= 0) {
        ws = ws.map(() => 1);
        sum = ws.length;
      }
      const floors = [];
      const rem = [];
      let used = 0;
      for (const w of ws) {
        const raw = sum > 0 ? (tu * w / sum) : 0;
        const f = Math.floor(raw);
        floors.push(f);
        rem.push(raw - f);
        used += f;
      }
      let left = tu - used;
      if (left > 0) {
        const idxs = floors.map((_, i) => i).sort((a, b) => rem[b] - rem[a]);
        let k = 0;
        while (left > 0 && idxs.length) {
          const i = idxs[k % idxs.length];
          floors[i] += 1;
          left -= 1;
          k += 1;
        }
      }
      return floors;
    };

    // Prefill draft
    const draft = {};
    for (const sid of serviceIds) {
      draft[sid] = {};
      for (const uid of this.selectedConsultantIds) {
        draft[sid][uid] = 0;
      }
      if (existingAlloc && existingAlloc[sid]) {
        for (const [uidStr, days] of Object.entries(existingAlloc[sid] || {})) {
          const uid = parseInt(uidStr, 10) || 0;
          if (uid > 0 && draft[sid] && Object.prototype.hasOwnProperty.call(draft[sid], uid)) {
            draft[sid][uid] = this.num(days);
          }
        }
      } else {
        // Default: auto-suggest split based on consultant availability (best-effort), editable by user.
        const tot = this.num(totals.get(parseInt(sid, 10) || 0));
        const uids = (this.selectedConsultantIds || []).slice();
        const weights = uids.map(uid => capacityById.has(uid) ? capacityById.get(uid) : 0);
        const totalU = Math.max(0, Math.round(tot * 2));
        const us = allocateHalfUnits(totalU, weights);
        uids.forEach((uid, i) => {
          draft[sid][uid] = ((us[i] || 0) / 2.0);
        });
      }
    }

    this.allocationDraftPlanId = this.activePlanId;
    this.allocationDraft = draft;

    // Build table
    const consultantNameById = new Map((this.consultants || []).map(c => [parseInt(c.id, 10) || 0, (c.name || c.email || `#${c.id}`)]));
    const cols = this.selectedConsultantIds.map(uid => ({
      id: uid,
      label: consultantNameById.get(uid) || `#${uid}`,
    }));

    // Summary totals (from actual plan items when available)
    const dayTotals = { onsite: 0, remote: 0, travel: 0, other: 0 };
    let callHours = 0;
    let communicationHours = 0;
    (items || []).forEach(it => {
      const t = String(it?.activity_type || 'remote').toLowerCase();
      const d = this.num(it?.days || 0);
      const h = this.num(it?.hours || 0);
      if (t === 'call') callHours += h;
      else if (t === 'communication') communicationHours += h;
      else if (dayTotals[t] === undefined) dayTotals.other += d;
      else dayTotals[t] += d;
    });
    const totalDays = Object.values(dayTotals).reduce((a, b) => a + this.num(b), 0);
    const totalCallHours = callHours + communicationHours;

    wrap.innerHTML = `
      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">
        <span class="planning-pill">Totale piano: ${this.escapeHtml(totalDays.toFixed(2))}g${totalCallHours > 0 ? ` + ${this.escapeHtml(totalCallHours.toFixed(1))}h call` : ''}</span>
        <span class="planning-pill">On-site: ${this.escapeHtml(this.num(dayTotals.onsite).toFixed(2))}g</span>
        <span class="planning-pill">Remoto: ${this.escapeHtml(this.num(dayTotals.remote).toFixed(2))}g</span>
        <span class="planning-pill">Call: ${this.escapeHtml(totalCallHours.toFixed(1))}h</span>
      </div>
      <div style="overflow:auto;">
        <table class="planning-table planning-allocation-table">
          <thead>
            <tr>
              <th>Servizio</th>
              <th style="width:120px;">Totale (gg)</th>
              ${cols.map(c => `<th style="min-width:140px;">${this.escapeHtml(c.label)}</th>`).join('')}
              <th style="width:140px;">Totale assegnato</th>
            </tr>
          </thead>
          <tbody>
            ${serviceIds.map(sid => {
              const label = this.getServiceLabelById(sid);
              const tot = this.num(totals.get(parseInt(sid, 10) || 0));
              return `
                <tr data-service-id="${this.escapeAttr(String(sid))}">
                  <td>${this.escapeHtml(label)}</td>
                  <td><strong>${this.escapeHtml(String(tot.toFixed(2)))}</strong></td>
                  ${cols.map(c => {
                    const v = this.num(draft[sid]?.[c.id] ?? 0);
                    return `
                      <td>
                        <input
                          type="number"
                          step="0.5"
                          min="0"
                          class="planning-alloc-input"
                          data-service-id="${this.escapeAttr(String(sid))}"
                          data-user-id="${this.escapeAttr(String(c.id))}"
                          value="${this.escapeAttr(String(v))}"
                        />
                      </td>
                    `;
                  }).join('')}
                  <td class="planning-muted" data-row-total="1">—</td>
                </tr>
              `;
            }).join('')}
          </tbody>
          <tfoot>
            <tr>
              <th>Totale</th>
              <th class="planning-muted" data-total-services="1">—</th>
              ${cols.map(c => `<th class="planning-muted" data-col-total="${this.escapeAttr(String(c.id))}">—</th>`).join('')}
              <th class="planning-muted" data-grand-total="1">—</th>
            </tr>
          </tfoot>
        </table>
      </div>
      ${!plan?.estimate_json ? `<div class="planning-muted" style="margin-top:8px;">Nota: la stima non è salvata sul piano (migrazione 50 non applicata). L’allocazione verrà comunque applicata alle righe se disponibile.</div>` : ''}
      <div style="height: 12px;"></div>
      <div id="planningAllocationItemsWrap"></div>
    `;

    const recompute = () => {
      const rowTotals = new Map(); // sid -> sum
      const colTotals = new Map(); // uid -> sum
      let grand = 0;
      const inputs = wrap.querySelectorAll('input.planning-alloc-input');
      inputs.forEach(inp => {
        const sid = parseInt(inp.getAttribute('data-service-id') || '0', 10) || 0;
        const uid = parseInt(inp.getAttribute('data-user-id') || '0', 10) || 0;
        const v = this.num(inp.value);
        rowTotals.set(sid, (rowTotals.get(sid) || 0) + v);
        colTotals.set(uid, (colTotals.get(uid) || 0) + v);
        grand += v;
      });

      wrap.querySelectorAll('tr[data-service-id]').forEach(tr => {
        const sid = parseInt(tr.getAttribute('data-service-id') || '0', 10) || 0;
        const v = this.num(rowTotals.get(sid) || 0);
        const td = tr.querySelector('[data-row-total="1"]');
        if (td) td.textContent = v.toFixed(2);
      });
      const totalServices = Array.from(totals.values()).reduce((a, b) => a + this.num(b), 0);
      const tdServices = wrap.querySelector('[data-total-services="1"]');
      if (tdServices) tdServices.textContent = totalServices.toFixed(2);

      cols.forEach(c => {
        const td = wrap.querySelector(`[data-col-total="${String(c.id)}"]`);
        if (td) td.textContent = this.num(colTotals.get(c.id) || 0).toFixed(2);
      });
      const tdGrand = wrap.querySelector('[data-grand-total="1"]');
      if (tdGrand) tdGrand.textContent = grand.toFixed(2);
    };

    // Bind input events
    wrap.querySelectorAll('input.planning-alloc-input').forEach(inp => {
      inp.addEventListener('input', recompute);
    });
    recompute();

    // Also show the real plan activities under the matrix (so users can verify what they're allocating).
    try {
      this.renderAllocationItemsList({ container: wrap.querySelector('#planningAllocationItemsWrap'), plan, items, est });
    } catch (e) {
      console.warn('[Allocation] renderAllocationItemsList failed:', e);
    }

    // Best-effort: if no allocation is persisted yet, auto-propose once per plan (AI + fallback).
    // This makes the default suggestion distance-aware without forcing the user to click the button.
    try {
      const hasPersisted = !!(existingAlloc && typeof existingAlloc === 'object' && Object.keys(existingAlloc || {}).length);
      const pid = parseInt(this.activePlanId || '0', 10) || 0;
      if (!hasPersisted && pid > 0) {
        if (!this.allocationAutoSuggestedPlanIds) this.allocationAutoSuggestedPlanIds = new Set();
        if (!this.allocationAutoSuggestedPlanIds.has(pid)) {
          this.allocationAutoSuggestedPlanIds.add(pid);
          setTimeout(() => { try { this.suggestAllocationForActivePlan(); } catch (_) {} }, 80);
        }
      }
    } catch (_) {}

    if (msg) {
      msg.style.display = 'none';
      msg.innerHTML = '';
    }
  }

  async suggestAllocationForActivePlan() {
    if (!this.activePlanId) return this.toast('Seleziona un piano', 'error');
    if (!this.selectedConsultantIds || !this.selectedConsultantIds.length) return this.toast('Seleziona prima i consulenti', 'error');

    try {
      this.startProgress('Proposta allocazione (AI)…');
      const res = await this.apiFetch('consulting_plans/allocation_suggest.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          plan_id: parseInt(this.activePlanId, 10),
          use_ai: true,
        }),
      });
      const d = res?.data || {};
      const matrix = (d && typeof d.matrix === 'object' && d.matrix) ? d.matrix : null;
      const perItem = (d && typeof d.per_item_assignments === 'object' && d.per_item_assignments) ? d.per_item_assignments : null;
      const reasons = (d && typeof d.reasons === 'object' && d.reasons) ? d.reasons : null;
      const warnings = Array.isArray(d.warnings) ? d.warnings : [];
      const aiUsed = !!(d?.ai?.used);

      // Store for apply step (best-effort)
      this.allocationSuggestedPerItem = perItem || {};
      this.allocationSuggestedReasons = reasons || {};

      // Apply suggested matrix to inputs (if matrix available and table rendered)
      try {
        const wrap = document.getElementById('planningAllocationMatrixWrap');
        if (wrap && matrix) {
          this.allocationMatrixUpdating = true;
          try {
            // Reset all to 0
            wrap.querySelectorAll('input.planning-alloc-input').forEach(inp => { inp.value = '0'; });
            for (const [sidStr, byUser] of Object.entries(matrix || {})) {
              const sid = parseInt(sidStr, 10) || 0;
              if (!sid || !byUser || typeof byUser !== 'object') continue;
              for (const [uidStr, days] of Object.entries(byUser || {})) {
                const uid = parseInt(uidStr, 10) || 0;
                if (!uid) continue;
                const v = this.num(days);
                // sid/uid are numeric; safe to use directly in attribute selectors
                const inp = wrap.querySelector(`input.planning-alloc-input[data-service-id="${String(sid)}"][data-user-id="${String(uid)}"]`);
                if (inp) inp.value = String(v);
              }
            }
            // Trigger totals update by re-dispatching input event
            wrap.querySelectorAll('input.planning-alloc-input').forEach(inp => {
              try { inp.dispatchEvent(new Event('input', { bubbles: true })); } catch (_) {}
            });
          } finally {
            this.allocationMatrixUpdating = false;
          }
        }
      } catch (_) {}

      // Refresh the per-item list to show suggested assignees + reasons (best-effort)
      try {
        const wrap = document.getElementById('planningAllocationMatrixWrap');
        const ctx = this._allocationPanelCtx || { plan: this.getActivePlan(), est: this.getPlanEstimateObject(this.getActivePlan()), items: Array.isArray(this.items) ? this.items : [] };
        if (wrap) {
          this.renderAllocationItemsList({
            container: wrap.querySelector('#planningAllocationItemsWrap'),
            plan: ctx.plan,
            items: Array.isArray(this.items) ? this.items : ctx.items,
            est: ctx.est,
          });
          try { this.recomputeAllocationMatrixFromItemAssignments(); } catch (_) {}
        }
      } catch (_) {}

      // Update message box with warnings (if any)
      try {
        const msg = document.getElementById('planningAllocationMsg');
        if (msg) {
          if (warnings.length) {
            msg.style.display = 'block';
            msg.innerHTML = `<div style="font-weight:700; margin-bottom:6px;">Proposta completata con avvisi</div>` +
              `<div class="planning-muted">${this.escapeHtml(warnings.slice(0, 12).join('\n'))}</div>`;
          } else {
            msg.style.display = 'none';
            msg.innerHTML = '';
          }
        }
      } catch (_) {}

      this.toast(
        warnings.length
          ? `Proposta allocazione ${aiUsed ? 'AI' : ''} (con avvisi)`
          : `Proposta allocazione ${aiUsed ? 'AI' : ''} pronta`,
        warnings.length ? 'warning' : 'success'
      );
    } catch (e) {
      console.error('[Allocation] suggest error:', e);
      this.toast(e?.message || 'Errore proposta allocazione', 'error');
    } finally {
      this.finishProgress();
    }
  }

  renderAllocationItemsList({ container, plan, items, est }) {
    if (!container) return;
    const pid = parseInt(this.activePlanId || '0', 10) || 0;
    const safeItems = Array.isArray(items) ? items : [];
    const rows = safeItems.filter(it => it && !String(it.id || '').startsWith('new_'));

    const nameById = new Map((this.consultants || []).map(u => [parseInt(u.id, 10) || 0, (u.name || u.email || `#${u.id}`)]));
    const selectedConsultants = (this.selectedConsultantIds || []).map(uid => {
      const id = parseInt(uid, 10) || 0;
      return { id, name: nameById.get(id) || `#${id}` };
    }).filter(x => x.id > 0);
    const hasEstimate = !!(est && typeof est === 'object');
    const canGenerateFromEstimate = hasEstimate && Array.isArray(est?.estimates) && est.estimates.length;

    if (!pid) {
      container.innerHTML = `<div class="planning-muted">Seleziona un piano per vedere le attività.</div>`;
      return;
    }

    if (!rows.length) {
      const btn = canGenerateFromEstimate
        ? `<button type="button" class="btn btn-primary btn-sm" id="planningAllocationGenerateItemsBtn">Genera attività da stima</button>`
        : '';
      const hint = canGenerateFromEstimate
        ? 'Il piano non ha ancora attività. Puoi generarle dalla stima del wizard.'
        : 'Il piano non ha attività e non risulta una stima salvata: crea il piano via wizard oppure aggiungi attività manualmente.';
      container.innerHTML = `
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
          <div style="font-weight:700;">Attività del piano</div>
          <div class="planning-actions">${btn}</div>
        </div>
        <div class="planning-muted" style="margin-top:8px;">${this.escapeHtml(hint)}</div>
      `;
      const genBtn = container.querySelector('#planningAllocationGenerateItemsBtn');
      if (genBtn && genBtn.dataset?.cnxBound !== '1') {
        genBtn.addEventListener('click', async () => {
          try {
            await this.allocationGenerateItemsFromEstimate({ replaceExisting: false });
          } catch (e) {
            console.error(e);
            this.toast(e?.message || 'Errore generazione attività', 'error');
          }
        });
        genBtn.dataset.cnxBound = '1';
      }
      return;
    }

    const serviceMissing = rows.filter(it => !parseInt(it?.domain_activity_type_id || '0', 10)).length;
    const warn = serviceMissing
      ? `<div style="margin-top:8px; color:#9a3412; font-weight:600;">Attenzione: ${serviceMissing} attività senza “Servizio/Norma”. Assegna il servizio nelle righe (tab Attività) oppure rigenera da stima.</div>`
      : '';

    container.innerHTML = `
      <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div style="font-weight:700;">Attività del piano (${rows.length})</div>
        <div class="planning-actions">
          ${canGenerateFromEstimate ? `<button type="button" class="btn btn-secondary btn-sm" id="planningAllocationRegenerateItemsBtn">Rigenera da stima</button>` : ''}
        </div>
      </div>
      <div class="planning-muted" style="margin-top:8px;">Qui vedi le attività reali del piano (fasi). L’allocazione verrà applicata a queste righe prima di generare il calendario.</div>
      ${warn}
      <div style="overflow:auto; margin-top:10px;">
        <table class="planning-table">
          <thead>
            <tr>
              <th>Tipo</th>
              <th>Fase / Note</th>
              <th style="width:120px;">Durata</th>
              <th style="width:130px;">Data</th>
              <th>Servizio</th>
              <th>Consulente</th>
              <th>Motivazione</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(it => {
              const itemId = parseInt(it.id || '0', 10) || 0;
              const type = String(it.activity_type || '');
              const dVal = this.num(it.days);
              const hVal = this.num(it.hours);
              const durText = (type === 'call' || type === 'communication')
                ? (hVal > 0 ? `${Math.round(hVal * 60)}m` : '—')
                : (dVal > 0 ? `${this.formatHalfDayDays(dVal)}g` : '—');
              const date = String(it.activity_date || '');
              const svcId = parseInt(it.domain_activity_type_id || '0', 10) || 0;
              const svcLabel = svcId > 0 ? this.getServiceLabelById(svcId) : '—';
              const desc = String(it.description || '').trim();
              const assignee = parseInt(it.assignee_user_id || '0', 10) || 0;
              const suggestedUid = parseInt((this.allocationSuggestedPerItem && this.allocationSuggestedPerItem[itemId]) || '0', 10) || 0;
              const chosenUid = suggestedUid > 0 ? suggestedUid : assignee;
              const optionsHtml = [`<option value="0"${chosenUid <= 0 ? ' selected' : ''}>— Non assegnato —</option>`]
                .concat(selectedConsultants.map(c => {
                  const sel = (chosenUid === c.id) ? ' selected' : '';
                  return `<option value="${this.escapeAttr(String(c.id))}"${sel}>${this.escapeHtml(c.name)}</option>`;
                }))
                .join('');
              const selHtml = `<select class="form-control" data-alloc-item-id="${this.escapeAttr(String(itemId))}">${optionsHtml}</select>`;

              const rr = (this.allocationSuggestedReasons && this.allocationSuggestedReasons[itemId]) ? this.allocationSuggestedReasons[itemId] : null;
              const reasonArr = Array.isArray(rr) ? rr.map(x => String(x || '').trim()).filter(Boolean) : [];
              const reasonText = reasonArr.length ? reasonArr.join(' • ') : '—';
              const typeLabel = (type === 'onsite') ? 'In sito' : (type === 'remote') ? 'Remoto' : (type === 'call') ? 'Call' : (type === 'travel') ? 'Trasferta' : (type === 'communication') ? 'Comunicazione' : type;
              return `
                <tr>
                  <td>${this.escapeHtml(typeLabel)}</td>
                  <td>${this.escapeHtml(desc || '—')}</td>
                  <td>${this.escapeHtml(durText)}</td>
                  <td>${this.escapeHtml(date || '—')}</td>
                  <td>${this.escapeHtml(svcLabel)}</td>
                  <td>${selHtml}</td>
                  <td class="planning-muted">${this.escapeHtml(reasonText)}</td>
                </tr>
              `;
            }).join('')}
          </tbody>
        </table>
      </div>
    `;

    const regenBtn = container.querySelector('#planningAllocationRegenerateItemsBtn');
    if (regenBtn && regenBtn.dataset?.cnxBound !== '1') {
      regenBtn.addEventListener('click', async () => {
        if (!confirm('Rigenerare le attività dalla stima? Le attività esistenti verranno sostituite.')) return;
        try {
          await this.allocationGenerateItemsFromEstimate({ replaceExisting: true });
        } catch (e) {
          console.error(e);
          this.toast(e?.message || 'Errore rigenerazione attività', 'error');
        }
      });
      regenBtn.dataset.cnxBound = '1';
    }

    // Bind per-row assignment changes (keeps the matrix in sync, best-effort)
    container.querySelectorAll('select[data-alloc-item-id]').forEach(sel => {
      if (sel.dataset?.cnxBound === '1') return;
      sel.addEventListener('change', () => {
        try { this.recomputeAllocationMatrixFromItemAssignments(); } catch (_) {}
      });
      sel.dataset.cnxBound = '1';
    });
  }

  recomputeAllocationMatrixFromItemAssignments() {
    const wrap = document.getElementById('planningAllocationMatrixWrap');
    if (!wrap) return;
    const hasMatrix = !!wrap.querySelector('input.planning-alloc-input');
    if (!hasMatrix) return; // matrix not rendered (no service IDs)

    const assignments = this.collectAllocationItemAssignmentsFromUI();
    if (!assignments || !Object.keys(assignments).length) return;

    const itemsById = new Map((Array.isArray(this.items) ? this.items : []).map(it => [parseInt(it?.id || '0', 10) || 0, it]));
    const matrix = {}; // sid -> uid -> days
    for (const [iidStr, uidRaw] of Object.entries(assignments)) {
      const iid = parseInt(iidStr, 10) || 0;
      const uid = parseInt(String(uidRaw || '0'), 10) || 0;
      if (!iid || !uid) continue;
      const it = itemsById.get(iid) || null;
      if (!it) continue;
      const sid = parseInt(it?.domain_activity_type_id || '0', 10) || 0;
      if (!sid) continue;
      const d = this.num(it?.days || 0);
      if (d <= 0) continue;
      if (!matrix[sid]) matrix[sid] = {};
      matrix[sid][uid] = this.num((matrix[sid][uid] || 0) + d);
    }

    this.allocationMatrixUpdating = true;
    try {
      // Reset all inputs to 0 and fill computed values
      wrap.querySelectorAll('input.planning-alloc-input').forEach(inp => { inp.value = '0'; });
      for (const [sidStr, byUser] of Object.entries(matrix)) {
        const sid = parseInt(sidStr, 10) || 0;
        if (!sid || !byUser || typeof byUser !== 'object') continue;
        for (const [uidStr, days] of Object.entries(byUser || {})) {
          const uid = parseInt(uidStr, 10) || 0;
          if (!uid) continue;
          const v = this.num(days);
          const inp = wrap.querySelector(`input.planning-alloc-input[data-service-id="${String(sid)}"][data-user-id="${String(uid)}"]`);
          if (inp) inp.value = String(v);
        }
      }
      // Recompute totals
      wrap.querySelectorAll('input.planning-alloc-input').forEach(inp => {
        try { inp.dispatchEvent(new Event('input', { bubbles: true })); } catch (_) {}
      });
    } finally {
      this.allocationMatrixUpdating = false;
    }
  }

  async allocationGenerateItemsFromEstimate({ replaceExisting = false } = {}) {
    if (!this.activePlanId) throw new Error('Seleziona un piano');
    const plan = this.getActivePlan();
    const est = this.getPlanEstimateObject(plan);
    if (!est) throw new Error('Stima non disponibile sul piano (crea il piano via wizard o verifica le migrazioni)');

    await this.apiFetch('consulting_plans/items_generate_from_estimate.php', {
      method: 'POST',
      json: true,
      body: JSON.stringify({
        csrf_token: this.csrfToken,
        plan_id: parseInt(this.activePlanId, 10),
        estimate_json: est,
        replace_existing: !!replaceExisting,
      }),
    });
    this.toast(replaceExisting ? 'Attività rigenerate' : 'Attività generate', 'success');
    await this.loadItems(this.activePlanId);
    try { this.renderAllocationPanel(); } catch (_) {}
  }

  collectAllocationFromUI() {
    const wrap = document.getElementById('planningAllocationMatrixWrap');
    if (!wrap) return {};
    const allocation = {};
    wrap.querySelectorAll('input.planning-alloc-input').forEach(inp => {
      const sid = parseInt(inp.getAttribute('data-service-id') || '0', 10) || 0;
      const uid = parseInt(inp.getAttribute('data-user-id') || '0', 10) || 0;
      if (sid <= 0 || uid <= 0) return;
      const v = this.num(inp.value);
      if (!allocation[sid]) allocation[sid] = {};
      allocation[sid][uid] = v;
    });
    return allocation;
  }

  collectAllocationItemAssignmentsFromUI() {
    const wrap = document.getElementById('planningAllocationMatrixWrap');
    if (!wrap) return {};
    const out = {};
    // Best-effort: if the allocation items table has per-row selects, collect them
    wrap.querySelectorAll('select[data-alloc-item-id]').forEach(sel => {
      const iid = parseInt(sel.getAttribute('data-alloc-item-id') || '0', 10) || 0;
      const uid = parseInt(sel.value || '0', 10) || 0;
      if (iid > 0 && uid > 0) out[iid] = uid;
    });
    return out;
  }

  async applyAllocationFromUI() {
    if (!this.activePlanId) return this.toast('Seleziona un piano', 'error');
    if (!this.selectedConsultantIds || !this.selectedConsultantIds.length) return this.toast('Seleziona prima i consulenti', 'error');

    const msg = document.getElementById('planningAllocationMsg');
    const allocation = this.collectAllocationFromUI();
    const itemAssignmentsFromUi = this.collectAllocationItemAssignmentsFromUI();
    const itemAssignmentsSuggested = (this.allocationSuggestedPerItem && typeof this.allocationSuggestedPerItem === 'object') ? this.allocationSuggestedPerItem : {};
    const itemAssignments = Object.keys(itemAssignmentsFromUi || {}).length
      ? itemAssignmentsFromUi
      : (Object.keys(itemAssignmentsSuggested || {}).length ? itemAssignmentsSuggested : null);
    try {
      this.startProgress('Applicazione allocazione…');
      const res = await this.apiFetch('consulting_plans/apply_allocation.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          plan_id: parseInt(this.activePlanId, 10),
          allocation,
          item_assignments: itemAssignments,
        }),
      });

      const warnings = Array.isArray(res?.data?.warnings) ? res.data.warnings : [];
      if (msg) {
        if (warnings.length) {
          msg.style.display = 'block';
          msg.innerHTML = `<div style="font-weight:700; margin-bottom:6px;">Completato con avvisi</div>` +
            `<div class="planning-muted">${this.escapeHtml(warnings.slice(0, 12).join('\n'))}</div>`;
        } else {
          msg.style.display = 'none';
          msg.innerHTML = '';
        }
      }

      this.toast(warnings.length ? 'Allocazione applicata (con avvisi)' : 'Allocazione applicata', warnings.length ? 'warning' : 'success');

      // Ensure every action has a task assigned to the same consultant (best-effort)
      try {
        await this.syncPlanItemTasks({ planId: parseInt(this.activePlanId, 10), showToast: false });
      } catch (_) {}

      await this.loadItems();
      // Refresh plans to update estimate_json (allocation persisted)
      try { await this.loadPlans(); } catch (_) {}
      try { await this.loadConsultantsForActivePlan(); } catch (_) {}
      try { this.renderAllocationPanel(); } catch (_) {}
    } catch (e) {
      console.error('[Allocation] apply error:', e);
      if (msg) {
        msg.style.display = 'block';
        msg.innerHTML = `<div style="font-weight:700; margin-bottom:6px;">Errore</div><div class="planning-muted">${this.escapeHtml(String(e?.message || e))}</div>`;
      }
      this.toast(e.message || 'Errore allocazione', 'error');
    } finally {
      this.finishProgress();
    }
  }

  // ------------------------------
  // Modal helpers
  // ------------------------------
  openModal(id) {
    const m = document.getElementById(id);
    if (m) m.style.display = 'flex';
    try { document.body.style.overflow = 'hidden'; } catch (_) {}
  }

  closeModal(id) {
    const m = document.getElementById(id);
    if (m) m.style.display = 'none';
    try {
      const anyOpen = Array.from(document.querySelectorAll('.modal'))
        .some(x => (x && x.style && x.style.display === 'flex'));
      if (!anyOpen) document.body.style.overflow = '';
    } catch (_) {}
  }

  num(v) {
    // Accept both dot and comma decimals (Italian locale)
    const s = String(v ?? '0').trim().replace(',', '.');
    const n = parseFloat(s);
    return Number.isFinite(n) ? n : 0;
  }

  // Planning 2026: day-based durations must be multiples of 0.5 (no 0.25/0.3 artifacts)
  formatHalfDayDays(days) {
    const d = this.num(days);
    if (!Number.isFinite(d) || d <= 0) return '';
    const snapped = Math.round(d * 2) / 2;
    const isInt = Math.abs(snapped - Math.round(snapped)) < 1e-9;
    return isInt ? String(Math.round(snapped)) : snapped.toFixed(1);
  }

  money(v) {
    const n = this.num(v);
    return n.toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
  }

  escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[c]));
  }

  escapeAttr(str) {
    return this.escapeHtml(str).replace(/"/g, '&quot;');
  }

  openCalendarModalForRow(tr) {
    const id = tr.getAttribute('data-item-id') || '';
    if (String(id).startsWith('new_')) {
      this.toast('Salva prima l’attività', 'error');
      return;
    }

    const row = this.readRow(tr);
    const m = document.getElementById('planningCalendarModal');
    if (!m) {
      this.toast('Modal calendario non disponibile', 'error');
      return;
    }

    // Populate tenant targets
    const sel = document.getElementById('planningCalendarTenant');
    if (sel) {
      const options = (this.calendarTargets || []).map(t => {
        const name = (t.denominazione || t.name || `Tenant ${t.id}`);
        return `<option value="${t.id}">${this.escapeHtml(name)} (#${t.id})</option>`;
      }).join('');
      sel.innerHTML = options || `<option value="28">S.CO Srls (#28)</option>`;
      // Default to tenant 28 if present
      const has28 = (this.calendarTargets || []).some(t => String(t.id) === '28');
      sel.value = has28 ? '28' : (sel.options[0]?.value || '28');
    }

    document.getElementById('planningCalendarItemId').value = String(id);
    document.getElementById('planningCalendarTitle').value =
      document.getElementById('planningCalendarTitle').value || '';

    // Default title and time
    const titleEl = document.getElementById('planningCalendarTitle');
    if (titleEl && !titleEl.value) {
      titleEl.value = `Consulenza: ${row.activity_type}`;
    }

    const startEl = document.getElementById('planningCalendarStart');
    const endEl = document.getElementById('planningCalendarEnd');
    const baseDate = row.activity_date || new Date().toISOString().slice(0, 10);
    if (startEl && !startEl.value) startEl.value = `${baseDate}T09:00`;
    if (endEl && !endEl.value) endEl.value = `${baseDate}T10:00`;

    const descEl = document.getElementById('planningCalendarDesc');
    if (descEl) {
      const lines = [];
      lines.push(`Attività: ${row.activity_type}`);
      if (row.activity_date) lines.push(`Data: ${row.activity_date}`);
      if (row.days) lines.push(`Giornate: ${row.days}`);
      if (row.km) lines.push(`Km: ${row.km}`);
      if (row.description) lines.push(`Note: ${row.description}`);
      descEl.value = lines.join('\n');
    }

    m.style.display = 'flex';
  }

  closeCalendarModal() {
    const m = document.getElementById('planningCalendarModal');
    if (!m) return;
    m.style.display = 'none';
  }

  async submitCalendarEvent() {
    const itemId = parseInt(document.getElementById('planningCalendarItemId')?.value || '0', 10) || 0;
    const tenantId = parseInt(document.getElementById('planningCalendarTenant')?.value || '0', 10) || 0;
    const title = document.getElementById('planningCalendarTitle')?.value || '';
    const start = document.getElementById('planningCalendarStart')?.value || '';
    const end = document.getElementById('planningCalendarEnd')?.value || '';
    const description = document.getElementById('planningCalendarDesc')?.value || '';

    if (!itemId || !tenantId) return this.toast('Seleziona azienda calendario', 'error');
    if (!title || title.trim().length < 2) return this.toast('Titolo evento troppo corto', 'error');
    if (!start || !end) return this.toast('Imposta inizio/fine', 'error');

    try {
      // events.php expects title,start,end; it normalizes start_date/end_date too
      await this.apiFetch(`events.php?tenant_id=${encodeURIComponent(tenantId)}`, {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          title: title.trim(),
          start: start,
          end: end,
          description: description || null,
          category: 'meeting',
          visibility: 'private',
        }),
      });
      this.toast('Evento creato', 'success');
      this.closeCalendarModal();
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore creazione evento', 'error');
    }
  }

  // ------------------------------
  // Schedule proposal UI
  // ------------------------------

  async loadScheduleDrafts() {
    if (!this.activePlanId) {
      this.scheduleStorageAvailable = true;
      this.scheduleDrafts = [];
      this.renderSchedule();
      return;
    }
    try {
      this.scheduleStorageAvailable = true;
      const data = await this.apiFetch(`consulting_plans/schedule_list.php?plan_id=${encodeURIComponent(this.activePlanId)}`, { method: 'GET' });
      this.scheduleDrafts = data.data?.drafts || [];
      this.renderSchedule();
    } catch (e) {
      const msg = String(e?.message || 'Errore calendario proposto');
      if (msg.includes('Modulo calendario proposto non inizializzato') || msg.includes('migrazione database 35')) {
        this.scheduleStorageAvailable = false;
      }
      this.scheduleDrafts = [];
      this.renderSchedule(msg);
    }
  }

  renderSchedule(errorMsg = '') {
    const tbody = document.getElementById('planningScheduleTbody');
    if (!tbody) return;
    if (!this.activePlanId) {
      tbody.innerHTML = `<tr><td colspan="8" class="planning-muted">Seleziona un piano…</td></tr>`;
      return;
    }
    if (errorMsg) {
      tbody.innerHTML = `<tr><td colspan="8" class="planning-muted">${this.escapeHtml(errorMsg)}</td></tr>`;
      return;
    }
    if (!this.scheduleDrafts.length) {
      tbody.innerHTML = `<tr><td colspan="8" class="planning-muted">Nessuna bozza. Usa “Genera proposta”.</td></tr>`;
      return;
    }

    const consultantOptions = (this.consultants || []).map(u => `<option value="${u.id}">${this.escapeHtml(u.name || u.email || `#${u.id}`)}</option>`).join('');

    const niceTitle = (raw) => {
      const t = String(raw || '').trim();
      if (!t) return { main: '—', meta: '' };
      const mPhase = t.match(/Fase:\s*(.+)$/i);
      if (mPhase && mPhase[1]) {
        const main = String(mPhase[1]).trim();
        const meta = t.replace(mPhase[0], '').replace(/\s+•\s+$/g, '').trim();
        return { main, meta };
      }
      const main = t.length > 72 ? (t.slice(0, 72) + '…') : t;
      return { main, meta: '' };
    };

    tbody.innerHTML = this.scheduleDrafts.map(d => {
      const startLocal = this.toDatetimeLocal(d.start_datetime);
      const endLocal = this.toDatetimeLocal(d.end_datetime);
      const disabled = d.status !== 'draft' ? 'disabled' : '';
      const titleRaw = String(d.title || '').trim();
      const t = niceTitle(titleRaw);
      let forcedTag = '';
      let phaseText = '';
      let reasonText = '';
      try {
        const exp = this.parseJsonBestEffort(d.explain_json);
        if (exp && typeof exp === 'object') {
          const forced = !!(exp.slot && typeof exp.slot === 'object' && exp.slot.forced);
          if (forced) forcedTag = ' • FORZATO';

          const poRaw = exp.phase && typeof exp.phase === 'object' ? exp.phase.order : null;
          const plRaw = exp.phase && typeof exp.phase === 'object' ? exp.phase.label : null;
          const po = poRaw !== null && poRaw !== undefined ? parseInt(String(poRaw), 10) : NaN;
          const pl = plRaw !== null && plRaw !== undefined ? String(plRaw || '').trim() : '';
          if (pl) phaseText = `${pl}${!Number.isNaN(po) ? ` (#${po})` : ''}`;
          else if (!Number.isNaN(po)) phaseText = `fase ${po}`;

          const tags = exp.reason && typeof exp.reason === 'object' && Array.isArray(exp.reason.tags) ? exp.reason.tags : [];
          const clean = (tags || []).map(x => String(x || '').trim()).filter(Boolean).filter(x => !x.toLowerCase().startsWith('fase '));
          if (clean.length) {
            reasonText = clean.slice(0, 5).join(' • ');
          } else {
            // Backward/partial schema: show something even when tags are missing
            const slot = (exp.slot && typeof exp.slot === 'object') ? exp.slot : null;
            const strat = slot && slot.strategy ? String(slot.strategy || '').trim() : '';
            const atts = slot && Array.isArray(slot.attempts) ? slot.attempts : [];
            const last = atts.length ? atts[atts.length - 1] : null;
            const lastReason = (last && typeof last === 'object' && last.reason) ? String(last.reason || '').trim() : '';
            const parts = [];
            if (strat) parts.push(strat);
            if (lastReason && lastReason !== strat) parts.push(lastReason);
            if (parts.length) reasonText = parts.join(' • ');
          }
        }
      } catch (_) {}
      // Fallback (schema drift): if explain_json is missing, derive phase from title "Fase: ..."
      if (!phaseText && t && t.main && String(t.main).trim() && String(t.main).trim() !== '—') {
        phaseText = String(t.main).trim();
      }
      // Guarantee a non-empty reason (users reported many "—" rows)
      if (!reasonText) {
        const st = String(startLocal || '');
        const en = String(endLocal || '');
        const stT = st.includes('T') ? st.split('T')[1].slice(0, 5) : '';
        const enT = en.includes('T') ? en.split('T')[1].slice(0, 5) : '';
        if (stT && enT) reasonText = `slot ${stT}-${enT}`;
      }
      return `
        <tr data-draft-id="${d.id}">
          <td>${this.escapeHtml(d.kind || '')}</td>
          <td class="planning-muted">${this.escapeHtml(phaseText || '—')}</td>
          <td title="${this.escapeAttr(titleRaw)}">
            <div style="font-weight:700;">${this.escapeHtml(t.main)}</div>
            <div class="planning-muted" style="margin-top:6px;">
              ${this.escapeHtml(d.status || '')}${forcedTag}${d.confirmed_event_id ? ` • event #${d.confirmed_event_id}` : ''}${t.meta ? ` • ${this.escapeHtml(t.meta)}` : ''}
            </div>
          </td>
          <td><input type="datetime-local" data-field="start_datetime" value="${this.escapeAttr(startLocal)}" ${disabled}></td>
          <td><input type="datetime-local" data-field="end_datetime" value="${this.escapeAttr(endLocal)}" ${disabled}></td>
          <td>
            <select data-field="assigned_user_id" ${disabled}>
              <option value="0">—</option>
              ${consultantOptions}
            </select>
          </td>
          <td class="planning-muted">${this.escapeHtml(reasonText || '—')}</td>
          <td style="width:200px;">
            <div class="planning-actions">
              <button type="button" class="btn btn-primary btn-sm" data-action="save" ${disabled}>Salva</button>
              <button type="button" class="btn btn-secondary btn-sm" data-action="suggest" ${disabled}>Trova slot</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    tbody.querySelectorAll('tr[data-draft-id]').forEach(tr => {
      const id = parseInt(tr.getAttribute('data-draft-id') || '0', 10);
      const d = this.scheduleDrafts.find(x => parseInt(x.id, 10) === id);
      const sel = tr.querySelector('[data-field="assigned_user_id"]');
      if (sel && d && d.assigned_user_id) sel.value = String(d.assigned_user_id);

      tr.querySelector('[data-action="save"]')?.addEventListener('click', () => this.saveScheduleRow(tr));
      tr.querySelector('[data-action="suggest"]')?.addEventListener('click', () => this.suggestScheduleRow(tr));
    });
  }

  async saveScheduleRow(tr) {
    const id = parseInt(tr.getAttribute('data-draft-id') || '0', 10) || 0;
    const start = tr.querySelector('[data-field="start_datetime"]')?.value || '';
    const end = tr.querySelector('[data-field="end_datetime"]')?.value || '';
    const assigned = parseInt(tr.querySelector('[data-field="assigned_user_id"]')?.value || '0', 10) || 0;
    if (!id) return;
    // Client-side overlap guard (plan/client rule):
    // overlap is forbidden if either slot is client_blocking=true (best-effort via explain_json).
    try {
      const sMs = new Date(String(start || '')).getTime();
      const eMs = new Date(String(end || '')).getTime();
      if (!Number.isFinite(sMs) || !Number.isFinite(eMs) || eMs <= sMs) {
        this.toast('Intervallo non valido', 'error');
        return;
      }
      const cur = (this.scheduleDrafts || []).find(x => (parseInt(x?.id, 10) || 0) === id) || null;
      const curExp = this.parseJsonBestEffort(cur?.explain_json);
      const curBlocking = (curExp && typeof curExp === 'object' && Object.prototype.hasOwnProperty.call(curExp, 'client_blocking'))
        ? !!curExp.client_blocking
        : true;
      for (const d of (this.scheduleDrafts || [])) {
        const oid = parseInt(d?.id, 10) || 0;
        if (!oid || oid === id) continue;
        const osMs = new Date(String(d?.start_datetime || '').replace(' ', 'T')).getTime();
        const oeMs = new Date(String(d?.end_datetime || '').replace(' ', 'T')).getTime();
        if (!Number.isFinite(osMs) || !Number.isFinite(oeMs) || oeMs <= osMs) continue;
        const overlap = (sMs < oeMs && eMs > osMs);
        if (!overlap) continue;
        const exp = this.parseJsonBestEffort(d?.explain_json);
        const otherBlocking = (exp && typeof exp === 'object' && Object.prototype.hasOwnProperty.call(exp, 'client_blocking'))
          ? !!exp.client_blocking
          : true;
        if (curBlocking || otherBlocking) {
          this.toast('Sovrapposizione non permessa (fase client-blocking). Sposta uno slot o usa “Trova slot”.', 'error');
          return;
        }
      }
    } catch (_) {}
    try {
      await this.apiFetch('consulting_plans/schedule_update.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({
          csrf_token: this.csrfToken,
          id,
          start_datetime: start,
          end_datetime: end,
          assigned_user_id: assigned || null,
        }),
      });
      this.toast('Slot aggiornato', 'success');
      await this.loadScheduleDrafts();
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore aggiornamento slot', 'error');
    }
  }

  async suggestScheduleRow(tr) {
    const id = parseInt(tr.getAttribute('data-draft-id') || '0', 10) || 0;
    if (!id) return;
    try {
      await this.apiFetch('consulting_plans/schedule_suggest.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, draft_id: id }),
      });
      this.toast('Slot aggiornato con suggerimento', 'success');
      await this.loadScheduleDrafts();
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Nessuno slot disponibile', 'error');
    }
  }

  async generateScheduleDrafts() {
    if (!this.activePlanId) {
      this.toast('Seleziona un piano', 'error');
      return;
    }
    // Refresh consultants selection state (avoid stale UI vs DB mismatch)
    try { await this.loadConsultantsForActivePlan(); } catch (_) {}
    if (!this.selectedConsultantIds || !this.selectedConsultantIds.length) {
      this.toast('Seleziona e salva prima i consulenti S.CO (tab Consulenti → “Seleziona consulenti” → Salva)', 'error');
      try { this.openConsultantsModal(); } catch (_) {}
      return;
    }
    if (this.planHasOnsiteItems()) {
      // Travel optimization is best-effort: don't block schedule generation if home_city storage is unavailable/missing.
      if (!this.homeCityStorageAvailable) {
        this.toast('Nota: migrazione DB 52 non applicata (home_city). La proposta verrà generata senza ottimizzazione trasferte.', 'warning');
      } else {
        const missing = (this.selectedConsultantIds || []).filter(uid => {
          const v = this.consultantHomeCityById ? String(this.consultantHomeCityById.get(uid) || '').trim() : '';
          return v === '';
        });
        if (missing.length) {
          const byId = new Map((this.consultants || []).map(u => [parseInt(u.id, 10) || 0, u]));
          const names = missing.map(uid => {
            const u = byId.get(uid) || {};
            return (u.name || u.email || `#${uid}`);
          });
          this.toast('Nota: città di partenza mancante per: ' + names.join(', ') + ' (ottimizzazione trasferte parziale)', 'warning');
          this.openConsultantsModal();
        }
      }
    }

    // New flow gating (non-breaking): enforce assignee only when the plan has estimate_json persisted.
    // Legacy plans (no estimate_json) keep working with round-robin.
    const plan = this.getActivePlan?.() || (this.plans || []).find(p => String(p.id) === String(this.activePlanId)) || null;
    const hasEstimateJson = !!(plan && plan.estimate_json && String(plan.estimate_json).trim());
    if (hasEstimateJson) {
      const hasAssigneeField = (this.items || []).some(it => it && Object.prototype.hasOwnProperty.call(it, 'assignee_user_id'));
      if (hasAssigneeField) {
        const anyAssigned = (this.items || []).some(it => (parseInt(it?.assignee_user_id || '0', 10) || 0) > 0);
        if (!anyAssigned) {
          this.toast('Assegna prima i consulenti alle attività (allocazione) prima di generare la bozza calendario', 'error');
          return;
        }
      }
    }

    if (!confirm('Generare una nuova proposta? Le bozze precedenti verranno sostituite.')) return;
    try {
      this.startProgress('Generazione bozza calendario…');
      const res = await this.apiFetch('consulting_plans/schedule_generate.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, plan_id: parseInt(this.activePlanId, 10) }),
      });
      const createdCount = parseInt(res?.data?.created_count || '0', 10) || 0;
      const errs = Array.isArray(res?.data?.errors) ? res.data.errors : [];
      const metaWarnings = Array.isArray(res?.data?.meta_warnings) ? res.data.meta_warnings : [];
      const warnings = Array.isArray(res?.data?.warnings) ? res.data.warnings : [];
      if (createdCount <= 0) {
        this.toast(`Nessuno slot creato (${errs.length} tentativi falliti). Verifica conflitti, finestra temporale e assegnazioni.`, 'error');
        await this.loadScheduleDrafts();
        return;
      }
      if (metaWarnings.length) {
        this.toast(String(metaWarnings[0] || 'Nota: proposta generata con avvisi'), 'warning');
      }
      if (errs.length) {
        this.toast(`Proposta generata con errori (${errs.length} slot non inseriti)`, 'warning');
      } else if (warnings.length) {
        this.toast(`Proposta generata (${createdCount}). Nota: ${warnings.length} slot forzati (potenziali conflitti)`, 'warning');
      } else {
        this.toast('Proposta generata', 'success');
      }
      await this.loadScheduleDrafts();
    } catch (e) {
      console.error(e);
      const errId = e?.data?.data?.error_id || e?.data?.error_id || null;
      let msg = e?.message || 'Errore generazione proposta';
      if (errId) msg += ` (ref: ${errId})`;
      this.toast(msg, 'error');
    } finally {
      this.finishProgress();
    }
  }

  async confirmScheduleDrafts() {
    if (!this.activePlanId) {
      this.toast('Seleziona un piano', 'error');
      return;
    }
    const drafts = (this.scheduleDrafts || []).filter(d => String(d?.status || '') === 'draft');
    if (!drafts.length) {
      this.toast('Nessuna bozza da confermare. Usa “Genera proposta”.', 'error');
      return;
    }
    const missing = drafts.filter(d => (parseInt(d?.assigned_user_id || '0', 10) || 0) <= 0);
    if (missing.length) {
      this.toast('Assegna un consulente a tutti gli slot prima di confermare (colonna “Consulente”).', 'error');
      return;
    }
    if (!confirm('Confermare la proposta? Verranno creati eventi reali nel calendario S.CO (tenant 28) e task assegnati ai consulenti.')) return;
    try {
      this.startProgress('Creazione eventi calendario…');
      const res = await this.apiFetch('consulting_plans/schedule_confirm.php', {
        method: 'POST',
        json: true,
        body: JSON.stringify({ csrf_token: this.csrfToken, plan_id: parseInt(this.activePlanId, 10) }),
      });
      const evc = parseInt(res?.data?.created_events_count || '0', 10) || 0;
      const tsc = parseInt(res?.data?.created_tasks_count || '0', 10) || 0;
      this.toast(`Confermato: eventi creati (${evc}) • task creati (${tsc})`, 'success');
      await this.loadScheduleDrafts();
    } catch (e) {
      console.error(e);
      this.toast(e.message || 'Errore conferma', 'error');
    } finally {
      this.finishProgress();
    }
  }

  toDatetimeLocal(sqlDateTime) {
    if (!sqlDateTime) return '';
    const s = String(sqlDateTime).replace(' ', 'T');
    return s.length >= 16 ? s.slice(0, 16) : s;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  window.planningApp = new PlanningApp();
  // Modal bindings
  document.getElementById('planningCalendarModalClose')?.addEventListener('click', () => window.planningApp.closeCalendarModal());
  document.getElementById('planningCalendarModalCancel')?.addEventListener('click', () => window.planningApp.closeCalendarModal());
  document.getElementById('planningCalendarForm')?.addEventListener('submit', (e) => {
    e.preventDefault();
    window.planningApp.submitCalendarEvent();
  });
});


