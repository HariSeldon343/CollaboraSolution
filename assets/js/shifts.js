/**
 * CollaboraNexio - Work Shifts Management JavaScript
 *
 * Handles the UI interactions for the shifts management page:
 * - Calendar grid rendering (week/month views)
 * - Shift CRUD operations
 * - Shift types management
 * - Change request workflow
 *
 * @version 1.0.0
 * @since 2025-12-19
 * @author CollaboraNexio Team (ui-craftsman)
 */

'use strict';

/**
 * Main Shifts Application Class
 */
class ShiftsApp {
    constructor(containerId) {
        this.container = document.getElementById(containerId);
        if (!this.container) {
            console.error('[ShiftsApp] Container not found:', containerId);
            return;
        }

        // Configuration
        this.csrfToken = document.getElementById('csrfToken')?.value || '';
        this.currentUserId = parseInt(document.getElementById('currentUserId')?.value || '0');
        this.currentUserRole = document.getElementById('currentUserRole')?.value || 'user';
        this.currentTenantId = parseInt(document.getElementById('currentTenantId')?.value || '0');
        this.canManageShifts = document.getElementById('canManageShifts')?.value === '1';
        this.canApproveRequests = document.getElementById('canApproveRequests')?.value === '1';

        // State
        // Default to month view for planning; persist user preference
        const savedView = (window.localStorage ? localStorage.getItem('cnx_shifts_view') : null);
        this.currentView = (savedView === 'week' || savedView === 'month') ? savedView : 'month'; // 'week' or 'month'
        this.currentDate = new Date();
        this.shifts = [];
        this.shiftTypes = [];
        this.employees = [];
        this.pendingRequests = [];

        // Shift wizard state
        this.shiftWizard = {
            step: 1,
            shift_type_id: 0,
            user_ids: [],
            start_date: '',
            end_date: '',
            days_of_week: [1, 2, 3, 4, 5],
            status: 'scheduled',
            notes: ''
        };

        // Bulk selection for fast delete (manager/admin)
        this.bulkSelectionMode = false;
        this.bulkSelectedShiftIds = new Set();

        // Italian locale
        this.dayNames = ['Domenica', 'Lunedi', 'Martedi', 'Mercoledi', 'Giovedi', 'Venerdi', 'Sabato'];
        this.dayNamesShort = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
        this.monthNames = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno',
                          'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

        // Status translations
        this.statusLabels = {
            'scheduled': 'Programmato',
            'confirmed': 'Confermato',
            'in_progress': 'In Corso',
            'completed': 'Completato',
            'cancelled': 'Annullato',
            'no_show': 'Assente'
        };

        this.requestTypeLabels = {
            'change': 'Cambio turno',
            'swap': 'Scambio con collega',
            'cancel': 'Annullamento'
        };

        this.requestStatusLabels = {
            'pending': 'In attesa',
            'approved': 'Approvata',
            'rejected': 'Rifiutata',
            'cancelled': 'Annullata',
            'expired': 'Scaduta'
        };

        // Onboarding / tour (like planning.php)
        this.tour = { active: false, stepIdx: 0, steps: [] };

        // Initialize
        this.init();
    }

    /**
     * Selected tenant IDs (from server-rendered hidden input).
     * - `#currentTenantIds` is preferred (JSON array)
     * - fallback to `#currentTenantId`
     */
    getSelectedTenantIds() {
        const raw = document.getElementById('currentTenantIds')?.value || '';
        try {
            const parsed = JSON.parse(raw || '[]');
            if (Array.isArray(parsed)) {
                const ids = parsed
                    .map(x => parseInt(String(x), 10))
                    .filter(n => Number.isFinite(n) && n > 0);
                if (ids.length > 0) return ids;
            }
        } catch (_) {
            // ignore
        }

        const fallback = parseInt(document.getElementById('currentTenantId')?.value || '0', 10);
        return Number.isFinite(fallback) && fallback > 0 ? [fallback] : [];
    }

    /**
     * Single-tenant selection only.
     * Returns 0 when multi-tenant (or none) is selected.
     */
    getSelectedTenantId() {
        const ids = this.getSelectedTenantIds();
        return ids.length === 1 ? ids[0] : 0;
    }

    /**
     * True when the current selection is exactly one tenant.
     */
    hasSingleTenantSelection() {
        return this.getSelectedTenantIds().length === 1;
    }

    /**
     * Update UI to reflect whether management actions are available.
     * - Multi/all companies: view-only (merged), disable management actions.
     */
    updateManagementAvailability() {
        const single = this.hasSingleTenantSelection();
        const msg = "Seleziona un’azienda per gestire Tipi Turno/creare turni";

        // IMPORTANT UX:
        // - Do NOT disable buttons entirely, otherwise clicks become "no-op" (user perceives the UI as broken).
        // - Keep buttons clickable and enforce single-tenant rule inside handlers via ensureSingleTenantForManage().
        const setBtn = (id, enabled) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.disabled = false;
            if (!enabled) {
                el.title = msg;
                el.setAttribute('data-cnx-single-tenant-required', '1');
            } else {
                el.removeAttribute('data-cnx-single-tenant-required');
            }
        };

        if (this.canManageShifts) {
            setBtn('newShiftBtn', single);
            setBtn('newShiftWizardBtn', single);
            setBtn('shiftTypesBtn', single);
            setBtn('balanceShiftsBtn', single);
        }
    }

    /**
     * Guard for any management operation that requires exactly one tenant.
     */
    ensureSingleTenantForManage() {
        if (this.hasSingleTenantSelection()) return true;
        this.showToast("Seleziona un’azienda per gestire Tipi Turno/creare turni", 'warning');
        return false;
    }

    /**
     * Render a placeholder state that asks the user to select a company.
     */
    renderSelectCompanyState() {
        // Keep header/filters visible; only replace the calendar area content
        this.container.innerHTML = `
            <div class="shifts-empty-state">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <p><strong>Seleziona almeno un’azienda</strong> per visualizzare i turni.</p>
            </div>
        `;
    }

    /**
     * Initialize the application
     */
    async init() {
        console.log('[ShiftsApp] Initializing...');

        // Bind event handlers
        this.bindEvents();
        this.updateViewButtonStates();
        this.updateManagementAvailability();

        // If no tenant is available, do not call tenant-scoped APIs.
        if (this.getSelectedTenantIds().length === 0) {
            this.renderSelectCompanyState();
            console.log('[ShiftsApp] No tenant selected - waiting for selection');
            return;
        }

        // Load initial data
        await this.loadInitialData();

        // Render calendar
        this.renderCalendar();

        // Load shifts for current period
        await this.loadShifts();

        // Load pending requests if manager
        if (this.canApproveRequests) {
            await this.loadPendingRequests();
        }

        console.log('[ShiftsApp] Initialization complete');

        // Start tour once on first access (best-effort; can be re-opened via header button)
        this.startTurniTour({ force: false });
    }

    getTurniTourStorageKey() {
        const uid = String(this.currentUserId || 0);
        const nonce = (document.getElementById('turniLoginNonce')?.value || '').trim();
        const base = `cnx_turni_tour_seen_v1_${uid}`;
        return nonce ? `${base}_${nonce}` : base;
    }

    startTurniTour({ force = false } = {}) {
        try {
            const key = this.getTurniTourStorageKey();
            const seen = (window.localStorage ? localStorage.getItem(key) === '1' : false);
            if (!force && seen) return;

            this.tour.steps = [
                {
                    title: '1) Tipi Turno',
                    body: 'Qui crei/modifichi i tipi (orari, colore, icona). Il wizard usa i tipi turno come punto di partenza.',
                    selector: '#shiftTypesBtn',
                    action: 'openShiftTypes',
                },
                {
                    title: '2) Crea tipo turno',
                    body: 'Compila i campi e salva. Suggerimento: usa un codice breve e un colore riconoscibile in calendario.',
                    selector: '#saveShiftTypeBtn',
                },
                {
                    title: '3) Wizard Turni (bulk)',
                    body: 'Creazione guidata: scegli tipo → persone → periodo → anteprima → crea in blocco.',
                    selector: '#newShiftWizardBtn',
                    action: 'openShiftWizard',
                },
                {
                    title: '4) Nuovo Turno (singolo)',
                    body: 'Per creare un turno singolo velocemente (utente + tipo + data).',
                    selector: '#newShiftBtn',
                },
                {
                    title: '5) Seleziona + elimina',
                    body: 'Attiva la modalità selezione per eliminare più turni rapidamente (soft-delete).',
                    selector: '#toggleSelectShiftsBtn',
                },
            ];

            this.tour.active = true;
            this.tour.stepIdx = 0;
            this.renderTurniTourOverlay();

            // Disable after first time (requirement): mark as seen when auto-starting
            if (!force && window.localStorage) localStorage.setItem(key, '1');
        } catch (_) {
            // If localStorage is blocked, skip silently
        }
    }

    endTurniTour({ disable = false } = {}) {
        try {
            if (disable && window.localStorage) {
                localStorage.setItem(this.getTurniTourStorageKey(), '1');
            }
        } catch (_) {}
        this.tour.active = false;
        const el = document.getElementById('turniTourOverlay');
        if (el) el.remove();
    }

    renderTurniTourOverlay() {
        if (!this.tour.active) return;
        const existing = document.getElementById('turniTourOverlay');
        if (existing) existing.remove();

        const step = this.tour.steps[this.tour.stepIdx] || null;
        if (!step) return this.endTurniTour({ disable: true });

        // Optional step actions to make the UI visible
        try {
            if (step.action === 'openShiftTypes') {
                // best-effort: keep the modal open so the user understands the form
                this.openShiftTypesModal?.();
            } else if (step.action === 'openShiftWizard') {
                this.openShiftWizardModal?.();
            }
        } catch (_) {}

        const target = document.querySelector(step.selector);
        const rect = target ? target.getBoundingClientRect() : null;

        const overlay = document.createElement('div');
        overlay.id = 'turniTourOverlay';
        overlay.className = 'turni-tour-overlay';

        const highlight = document.createElement('div');
        highlight.className = 'turni-tour-highlight';
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
        box.className = 'turni-tour-box';
        const total = this.tour.steps.length;
        box.innerHTML = `
            <div class="turni-tour-top">
                <div class="turni-tour-title">${this.escapeHtml(step.title || '')}</div>
                <button type="button" class="turni-tour-close" aria-label="Chiudi">×</button>
            </div>
            <div class="turni-tour-body">${this.escapeHtml(step.body || '')}</div>
            <div class="turni-tour-footer">
                <div class="planning-muted">Step ${this.tour.stepIdx + 1}/${total}</div>
                <div class="turni-tour-actions">
                    <button type="button" class="btn btn-secondary btn-sm" data-action="back" ${this.tour.stepIdx === 0 ? 'disabled' : ''}>Indietro</button>
                    <button type="button" class="btn btn-primary btn-sm" data-action="next">${this.tour.stepIdx === total - 1 ? 'Fine' : 'Avanti'}</button>
                    <button type="button" class="btn btn-outline btn-sm" data-action="disable">Non mostrare più</button>
                </div>
            </div>
        `;

        // Position box near highlight (fallback to center)
        if (rect) {
            const margin = 12;
            const preferredTop = rect.bottom + margin;
            const maxTop = window.innerHeight - 240;
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
            if (e.target === overlay) this.endTurniTour({ disable: true });
        });
        box.querySelector('.turni-tour-close')?.addEventListener('click', () => this.endTurniTour({ disable: true }));
        box.querySelector('[data-action="back"]')?.addEventListener('click', () => {
            this.tour.stepIdx = Math.max(0, this.tour.stepIdx - 1);
            this.renderTurniTourOverlay();
        });
        box.querySelector('[data-action="next"]')?.addEventListener('click', () => {
            if (this.tour.stepIdx >= this.tour.steps.length - 1) return this.endTurniTour({ disable: true });
            this.tour.stepIdx += 1;
            this.renderTurniTourOverlay();
        });
        box.querySelector('[data-action="disable"]')?.addEventListener('click', () => this.endTurniTour({ disable: true }));

        // Keep overlay aligned on resize/scroll (once per render)
        const onMove = () => {
            if (!this.tour.active) return;
            this.renderTurniTourOverlay();
        };
        window.addEventListener('resize', onMove, { once: true });
        window.addEventListener('scroll', onMove, { once: true, passive: true });
    }

    /**
     * Bind event handlers
     */
    bindEvents() {
        // Tour button
        document.getElementById('turniShowTourBtn')?.addEventListener('click', () => this.startTurniTour({ force: true }));

        // View selectors
        document.getElementById('viewWeekBtn')?.addEventListener('click', () => this.setView('week'));
        document.getElementById('viewMonthBtn')?.addEventListener('click', () => this.setView('month'));

        // Navigation
        document.getElementById('prevPeriodBtn')?.addEventListener('click', () => this.navigate(-1));
        document.getElementById('nextPeriodBtn')?.addEventListener('click', () => this.navigate(1));
        document.getElementById('todayBtn')?.addEventListener('click', () => this.goToToday());

        // Modal triggers
        document.getElementById('newShiftBtn')?.addEventListener('click', () => this.openShiftModal());
        document.getElementById('newShiftWizardBtn')?.addEventListener('click', () => this.openShiftWizardModal());
        document.getElementById('shiftTypesBtn')?.addEventListener('click', () => this.openShiftTypesModal());
        document.getElementById('shiftSummaryBtn')?.addEventListener('click', () => this.openShiftSummaryModal());
        document.getElementById('shiftTypesUsedBtn')?.addEventListener('click', () => this.openShiftTypesUsedModal());
        document.getElementById('balanceShiftsBtn')?.addEventListener('click', () => this.openBalanceModal());

        // Bulk select/delete
        document.getElementById('toggleSelectShiftsBtn')?.addEventListener('click', () => this.toggleBulkSelectionMode());
        document.getElementById('bulkDeleteShiftsBtn')?.addEventListener('click', () => this.bulkDeleteSelectedShifts());

        // Shift modal actions
        document.getElementById('saveShiftBtn')?.addEventListener('click', () => this.saveShift());
        document.getElementById('deleteShiftBtn')?.addEventListener('click', () => this.deleteShift());

        // Shift types modal actions
        document.getElementById('saveShiftTypeBtn')?.addEventListener('click', () => this.saveShiftType());
        document.getElementById('cancelShiftTypeBtn')?.addEventListener('click', () => this.resetShiftTypeForm());

        // Shift Wizard actions
        document.getElementById('shiftWizardBackBtn')?.addEventListener('click', () => this.shiftWizardPrev());
        document.getElementById('shiftWizardNextBtn')?.addEventListener('click', () => this.shiftWizardNext());
        document.getElementById('shiftWizardEmployeeSearch')?.addEventListener('input', () => this.shiftWizardRenderEmployees());
        document.getElementById('shiftWizardType')?.addEventListener('change', () => this.shiftWizardRender());
        document.getElementById('shiftWizardSelectAllBtn')?.addEventListener('click', () => this.shiftWizardSelectAllVisibleEmployees());
        document.getElementById('shiftWizardSelectOnlyVisibleBtn')?.addEventListener('click', () => this.shiftWizardSelectOnlyVisibleEmployees());
        document.getElementById('shiftWizardClearAllBtn')?.addEventListener('click', () => this.shiftWizardClearAllEmployees());
        document.getElementById('shiftWizardExportCsvBtn')?.addEventListener('click', () => this.shiftWizardExportPreviewCsv());

        // Shift detail modal actions
        document.getElementById('requestChangeBtn')?.addEventListener('click', () => this.showRequestChangeForm());
        document.getElementById('submitRequestBtn')?.addEventListener('click', () => this.submitChangeRequest());
        document.getElementById('editShiftFromDetailBtn')?.addEventListener('click', () => this.editShiftFromDetail());

        // Request detail modal actions
        document.getElementById('approveRequestBtn')?.addEventListener('click', () => this.approveRequest());
        document.getElementById('rejectRequestBtn')?.addEventListener('click', () => this.handleRejectRequest());

        // Request type change
        document.getElementById('requestType')?.addEventListener('change', (e) => this.handleRequestTypeChange(e));

        // Bulk date change detection
        document.getElementById('shiftDateEnd')?.addEventListener('change', (e) => this.handleDateEndChange(e));

        // Turno libero (entrata posticipata / uscita anticipata)
        document.getElementById('shiftOverrideTimesEnabled')?.addEventListener('change', () => this.updateShiftOverrideTimesUi());
        document.getElementById('shiftStartOverrideEnabled')?.addEventListener('change', () => this.updateShiftOverrideTimesUi());
        document.getElementById('shiftEndOverrideEnabled')?.addEventListener('change', () => this.updateShiftOverrideTimesUi());
        document.getElementById('shiftType')?.addEventListener('change', () => {
            this.updateShiftOverrideTimesHints();
            this.updateShiftOverrideTimesUi();
        });

        // Color picker preview
        document.getElementById('shiftTypeColor')?.addEventListener('input', (e) => {
            document.getElementById('colorPreview').style.backgroundColor = e.target.value;
        });

        // Modal close handlers
        document.querySelectorAll('.modal-close, [data-dismiss="modal"]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modal = e.target.closest('.modal');
                if (modal) this.closeModal(modal.id);
            });
        });

        // Modal backdrop click to close
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', (e) => {
                const modal = e.target.closest('.modal');
                if (modal) this.closeModal(modal.id);
            });
        });

        // NOTE: Company filter is apply-only (form submit); selection changes reload the page.
    }

    /**
     * Update view button active states.
     */
    updateViewButtonStates() {
        document.querySelectorAll('.shifts-view-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === this.currentView);
        });
    }

    /**
     * Load initial data (shift types, employees)
     */
    async loadInitialData() {
        try {
            // Load shift types
            await this.loadShiftTypes();

            // Load employees if manager
            if (this.canManageShifts) {
                await this.loadEmployees();
            }
        } catch (error) {
            console.error('[ShiftsApp] Error loading initial data:', error);
            this.showToast('Errore nel caricamento dati iniziali', 'error');
        }
    }

    /**
     * Build candidate URLs (router-first) for a shifts route.
     * This avoids browser-only 404s caused by edge/proxy rules on /api/shifts/*.
     */
    buildApiUrls(route, query = {}) {
        const ts = Date.now();
        const params = new URLSearchParams({ ...query, _ts: String(ts) });
        const directUrl = `/CollaboraNexio/api/shifts/${encodeURIComponent(route)}.php?${params.toString()}`;
        const routerUrl = `/CollaboraNexio/api/router.php?route=shifts/${encodeURIComponent(route)}&${params.toString()}`;
        // Prefer direct endpoint; use router only as fallback (some environments block one or the other).
        return [directUrl, routerUrl];
    }

    /**
     * Attempt fetch with fallback URLs (retry only on 404).
     */
    async apiFetchWithFallback(route, query = {}, options = {}) {
        const urls = this.buildApiUrls(route, query);
        let lastErr = null;

        for (const url of urls) {
            try {
                return await this.apiFetch(url, options);
            } catch (e) {
                // Retry only on infra-style failures where another endpoint may work.
                // - 404: path blocked/rewrite issues (common behind proxies)
                // - 401/403: router may enforce different auth than direct endpoint
                if (e && (e.__httpStatus === 404 || e.__httpStatus === 401 || e.__httpStatus === 403)) {
                    console.warn(`[ShiftsApp] ${e.__httpStatus} on ${url}, trying fallback...`);
                    lastErr = e;
                    continue;
                }
                throw e;
            }
        }

        throw lastErr || new Error('Endpoint non disponibile');
    }

    /**
     * API fetch wrapper with CSRF and credentials
     */
    async apiFetch(url, options = {}) {
        const defaultOptions = {
            // Be explicit to ensure cookies are always sent (some environments treat router calls strangely)
            credentials: 'include',
            headers: {
                'X-CSRF-Token': this.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json'
            }
        };

        const mergedOptions = {
            ...defaultOptions,
            ...options,
            headers: {
                ...defaultOptions.headers,
                ...options.headers
            }
        };

        const response = await fetch(url, mergedOptions);

        const contentType = (response.headers.get('content-type') || '').toLowerCase();
        const isJson = contentType.includes('application/json');

        // Read body safely (can be HTML on 404 pages)
        const rawText = await response.text();
        const tryJson = () => {
            if (!rawText) return null;
            try { return JSON.parse(rawText); } catch { return null; }
        };
        const data = isJson ? (tryJson() || {}) : (tryJson() || null);

        if (!response.ok) {
            const err = new Error(
                (data && (data.message || data.error)) ? (data.message || data.error) : `HTTP ${response.status}`
            );
            err.__httpStatus = response.status;
            err.__bodySnippet = rawText ? rawText.substring(0, 200) : '';

            // UX: make auth/session issues obvious (never "dead clicks")
            if (response.status === 401) {
                try {
                    this.showToast('Sessione scaduta o non valida. Ricarica la pagina o fai login di nuovo.', 'warning');
                } catch (_) {}
            }

            throw err;
        }

        if (data === null) {
            return { success: false, message: 'Risposta non JSON dal server' };
        }

        return data;
    }

    /**
     * Load shift types from API
     */
    async loadShiftTypes() {
        try {
            const tenantId = this.getSelectedTenantId();
            if (tenantId <= 0) {
                // Multi-tenant (or none): shift types are single-tenant only
                this.shiftTypes = [];
                this.populateShiftTypeSelects();
                this.renderShiftTypesLegend();

                const list = document.getElementById('shiftTypesList');
                if (list) {
                    list.innerHTML = `
                        <div class="shift-types-empty">
                            <p><strong>Seleziona un’azienda</strong> per gestire Tipi Turno/creare turni.</p>
                        </div>
                    `;
                }
                return;
            }
            const result = await this.apiFetchWithFallback('types', { tenant_id: tenantId });

            if (result.success && result.data?.shift_types) {
                this.shiftTypes = result.data.shift_types;
                this.populateShiftTypeSelects();
                this.renderShiftTypesLegend();
            }
        } catch (error) {
            console.error('[ShiftsApp] Error loading shift types:', error);
        }
    }

    /**
     * Load employees from API
     */
    async loadEmployees() {
        try {
            const tenantId = this.getSelectedTenantId();
            if (tenantId <= 0) {
                this.employees = [];
                this.populateEmployeeSelect();
                return;
            }
            // Use scope=tenant so super_admin/admin see users relevant to selected tenant
            // IMPORTANT: super_admin users must not be assignable in shifts UI
            const result = await this.apiFetch(`/CollaboraNexio/api/users/list.php?scope=tenant&limit=200&role=user,manager,admin`);

            if (result.success && result.data?.users) {
                this.employees = result.data.users;
                this.populateEmployeeSelect();
            }
        } catch (error) {
            console.error('[ShiftsApp] Error loading employees:', error);
        }
    }

    /**
     * Load shifts for current period
     */
    async loadShifts() {
        try {
            this.showLoading();

            const tenantIds = this.getSelectedTenantIds();
            if (tenantIds.length === 0) {
                this.renderSelectCompanyState();
                return;
            }
            const dateRange = this.getCurrentDateRange();

            let merged = [];
            if (tenantIds.length === 1) {
                const result = await this.apiFetchWithFallback('list', {
                    tenant_id: tenantIds[0],
                    start_date: dateRange.start,
                    end_date: dateRange.end
                });
                if (result.success && result.data?.shifts) {
                    merged = result.data.shifts;
                }
            } else {
                // Multi-tenant view-only: fetch per tenant and merge (concurrency-limited)
                const limit = 5;
                const results = [];

                for (let i = 0; i < tenantIds.length; i += limit) {
                    const chunk = tenantIds.slice(i, i + limit);
                    // eslint-disable-next-line no-await-in-loop
                    const chunkRes = await Promise.all(chunk.map(async (tid) => {
                        try {
                            const r = await this.apiFetchWithFallback('list', {
                                tenant_id: tid,
                                start_date: dateRange.start,
                                end_date: dateRange.end
                            });
                            return (r && r.success && r.data?.shifts) ? (r.data.shifts || []) : [];
                        } catch (_) {
                            return [];
                        }
                    }));

                    results.push(...chunkRes);
                }

                merged = results.flat();
            }

            if (Array.isArray(merged)) {
                this.shifts = merged;
                this.renderCalendar();
                try { this.renderShiftTypesLegend(); } catch (_) {}
                try { this.updateWeeklyHoursAlerts(); } catch (_) {}
            }
        } catch (error) {
            console.error('[ShiftsApp] Error loading shifts:', error);
            this.showToast('Errore nel caricamento turni', 'error');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * Load pending requests (for managers)
     */
    async loadPendingRequests() {
        if (!this.canApproveRequests) return;

        try {
            const tenantId = this.getSelectedTenantId();
            if (tenantId <= 0) {
                this.pendingRequests = [];
                this.renderRequestsSidebar();
                return;
            }
            const result = await this.apiFetchWithFallback('requests', { tenant_id: tenantId, status: 'pending' });

            if (result.success && result.data?.requests) {
                this.pendingRequests = result.data.requests;
                this.renderRequestsSidebar();
            }
        } catch (error) {
            console.error('[ShiftsApp] Error loading requests:', error);
        }
    }

    /**
     * Get date range for current view
     */
    getCurrentDateRange() {
        const startDate = new Date(this.currentDate);
        const endDate = new Date(this.currentDate);

        if (this.currentView === 'week') {
            // Start from Monday
            const dayOfWeek = startDate.getDay();
            const diff = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
            startDate.setDate(startDate.getDate() + diff);
            endDate.setDate(startDate.getDate() + 6);
        } else {
            // Month view: include full visible grid (pad to Monday..Sunday)
            startDate.setDate(1);
            endDate.setMonth(endDate.getMonth() + 1);
            endDate.setDate(0);

            // Pad start to Monday
            const startDow = startDate.getDay();
            const startDiff = startDow === 0 ? -6 : 1 - startDow;
            startDate.setDate(startDate.getDate() + startDiff);

            // Pad end to Sunday
            const endDow = endDate.getDay(); // 0=Sun
            const endDiff = endDow === 0 ? 0 : 7 - endDow;
            endDate.setDate(endDate.getDate() + endDiff);
        }

        return {
            start: this.formatDateISO(startDate),
            end: this.formatDateISO(endDate)
        };
    }

    /**
     * Format date as ISO string (YYYY-MM-DD)
     */
    formatDateISO(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    /**
     * Format date for display
     */
    formatDateDisplay(dateStr) {
        const date = new Date(dateStr);
        const day = date.getDate();
        const month = this.monthNames[date.getMonth()];
        return `${day} ${month}`;
    }

    /**
     * Format time (HH:MM)
     */
    formatTime(timeStr) {
        if (!timeStr) return '';
        return timeStr.substring(0, 5);
    }

    /**
     * Convert HEX (#RGB or #RRGGBB) to rgba(r,g,b,a).
     * Falls back to a neutral gray when input is invalid.
     */
    hexToRgba(hex, alpha = 0.15) {
        const fallback = { r: 107, g: 114, b: 128 }; // #6B7280
        const clampA = (n) => {
            const x = Number(n);
            if (!Number.isFinite(x)) return 0.15;
            return Math.max(0, Math.min(1, x));
        };

        if (typeof hex !== 'string') {
            const a = clampA(alpha);
            return `rgba(${fallback.r}, ${fallback.g}, ${fallback.b}, ${a})`;
        }

        let h = hex.trim();
        if (!h.startsWith('#')) {
            const a = clampA(alpha);
            return `rgba(${fallback.r}, ${fallback.g}, ${fallback.b}, ${a})`;
        }

        h = h.slice(1);
        if (h.length === 3) {
            h = h.split('').map(ch => ch + ch).join('');
        }
        if (!/^[0-9a-fA-F]{6}$/.test(h)) {
            const a = clampA(alpha);
            return `rgba(${fallback.r}, ${fallback.g}, ${fallback.b}, ${a})`;
        }

        const r = parseInt(h.slice(0, 2), 16);
        const g = parseInt(h.slice(2, 4), 16);
        const b = parseInt(h.slice(4, 6), 16);
        const a = clampA(alpha);
        return `rgba(${r}, ${g}, ${b}, ${a})`;
    }

    /**
     * Normalize shift icon.
     * Accepts emoji, or keyword like "sun" and maps to an emoji (same mapping as calendar.js).
     */
    resolveShiftIcon(rawIcon) {
        const v = (rawIcon ?? '').toString().trim();
        if (!v) return '🕑';

        // If it already contains an emoji-like codepoint, keep it
        try {
            if (/[\u{1F300}-\u{1FAFF}\u{2600}-\u{26FF}]/u.test(v)) {
                return v;
            }
        } catch (_) {
            // Older JS engines: ignore and fall back to mapping
        }

        const key = v.toLowerCase();
        const map = {
            sun: '☀️',
            sunny: '☀️',
            sunrise: '🌅',
            sunset: '🌇',
            day: '☀️',
            morning: '☀️',
            afternoon: '🌤️',
            evening: '🌆',
            night: '🌙',
            moon: '🌙',
            cloudy: '☁️',
            cloud: '☁️',
            rain: '🌧️',
            rainy: '🌧️',
            storm: '⛈️',
            snow: '❄️',
            holiday: '🎉',
            off: '🏖️',
            vacation: '🏖️',
            sick: '🤒',
            training: '📚',
            meeting: '📌',
            work: '🧰'
        };

        // Support common prefixes (fa-sun, icon-sun, bi-sun, etc.)
        const normalizedKey = key.replace(/^(fa-|fas-|far-|fab-|bi-|icon-|mdi-)/, '');
        return map[normalizedKey] || '🕑';
    }

    /**
     * Set current view (week/month)
     */
    setView(view) {
        this.currentView = view;
        if (window.localStorage) {
            localStorage.setItem('cnx_shifts_view', view);
        }

        // Update button states
        this.updateViewButtonStates();

        this.renderCalendar();
        this.loadShifts();
    }

    /**
     * Navigate periods
     */
    navigate(direction) {
        if (this.currentView === 'week') {
            this.currentDate.setDate(this.currentDate.getDate() + (direction * 7));
        } else {
            this.currentDate.setMonth(this.currentDate.getMonth() + direction);
        }

        this.updatePeriodLabel();
        this.loadShifts();
    }

    /**
     * Go to today
     */
    goToToday() {
        this.currentDate = new Date();
        this.updatePeriodLabel();
        this.loadShifts();
    }

    /**
     * Update period label
     */
    updatePeriodLabel() {
        const label = document.getElementById('currentPeriodLabel');
        if (!label) return;

        if (this.currentView === 'week') {
            const range = this.getCurrentDateRange();
            const startDate = new Date(range.start);
            const endDate = new Date(range.end);

            if (startDate.getMonth() === endDate.getMonth()) {
                label.textContent = `${startDate.getDate()} - ${endDate.getDate()} ${this.monthNames[startDate.getMonth()]} ${startDate.getFullYear()}`;
            } else {
                label.textContent = `${startDate.getDate()} ${this.monthNames[startDate.getMonth()].substring(0, 3)} - ${endDate.getDate()} ${this.monthNames[endDate.getMonth()].substring(0, 3)} ${endDate.getFullYear()}`;
            }
        } else {
            label.textContent = `${this.monthNames[this.currentDate.getMonth()]} ${this.currentDate.getFullYear()}`;
        }
    }

    /**
     * Render the calendar grid
     */
    renderCalendar() {
        this.updatePeriodLabel();

        if (this.currentView === 'week') {
            this.renderWeekView();
        } else {
            this.renderMonthView();
        }
    }

    /**
     * Render week view
     */
    renderWeekView() {
        const range = this.getCurrentDateRange();
        const startDate = new Date(range.start);
        const today = this.formatDateISO(new Date());
        const weekKey = range.start;
        let weekMinutesByUser = {};
        try {
            const stats = (this.weeklyHoursStats && this.weeklyHoursStats.weeklyMinutes)
                ? this.weeklyHoursStats
                : this.computeWeeklyHoursStats(this.shifts || []);
            weekMinutesByUser = stats && stats.weeklyMinutes ? stats.weeklyMinutes : {};
        } catch (_) {
            weekMinutesByUser = {};
        }

        // Get unique employees from shifts or use loaded employees
        const canUseLoadedEmployees = this.canManageShifts && this.hasSingleTenantSelection();
        const employeesInShifts = canUseLoadedEmployees ? this.employees :
            [...new Map(this.shifts.map(s => [s.user_id, { id: s.user_id, name: s.user_name }])).values()];

        // Build header with days
        let headerHTML = '<div class="shifts-grid-header">';
        headerHTML += '<div class="shifts-employee-header">Dipendente</div>';

        for (let i = 0; i < 7; i++) {
            const currentDay = new Date(startDate);
            currentDay.setDate(startDate.getDate() + i);
            const dateStr = this.formatDateISO(currentDay);
            const isToday = dateStr === today;

            headerHTML += `
                <div class="shifts-day-header ${isToday ? 'today' : ''}">
                    <span class="day-name">${this.dayNamesShort[currentDay.getDay()]}</span>
                    <span class="day-number">${currentDay.getDate()}</span>
                </div>
            `;
        }
        headerHTML += '</div>';

        // Build grid rows for each employee
        let gridHTML = '<div class="shifts-grid-body">';

        if (employeesInShifts.length === 0) {
            gridHTML += `
                <div class="shifts-empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <p>Nessun turno programmato per questo periodo</p>
                    ${(this.canManageShifts && this.hasSingleTenantSelection()) ? '<button class="btn btn-primary btn-sm" onclick="shiftsApp.openShiftModal()">Aggiungi Turno</button>' : ''}
                </div>
            `;
        } else {
            employeesInShifts.forEach(employee => {
                gridHTML += `<div class="shifts-row" data-employee-id="${employee.id}">`;
                const mins = (weekMinutesByUser && weekMinutesByUser[employee.id] && weekMinutesByUser[employee.id][weekKey]) ? (weekMinutesByUser[employee.id][weekKey] || 0) : 0;
                const hours = Math.round((mins / 60) * 10) / 10;
                const badgeClass = hours > 48 ? 'shift-summary-pill danger' : 'shift-summary-pill';
                const badge = `<span class="${badgeClass}" style="margin-left:8px;" title="Ore assegnate nella settimana (lun→dom)">${this.escapeHtml(String(hours))}h</span>`;
                gridHTML += `<div class="shifts-employee-cell">${this.escapeHtml(employee.name)} ${badge}</div>`;

                for (let i = 0; i < 7; i++) {
                    const currentDay = new Date(startDate);
                    currentDay.setDate(startDate.getDate() + i);
                    const dateStr = this.formatDateISO(currentDay);
                    const isToday = dateStr === today;

                    // Find shifts for this employee on this day
                    const dayShifts = this.shifts.filter(s =>
                        s.user_id === employee.id && s.shift_date === dateStr
                    );

                    gridHTML += `<div class="shifts-cell ${isToday ? 'today' : ''}" data-date="${dateStr}" data-employee-id="${employee.id}">`;

                    if (dayShifts.length > 0) {
                        dayShifts.forEach(shift => {
                            const statusClass = shift.status !== 'scheduled' ? `status-${shift.status}` : '';
                            const selectedClass = this.bulkSelectedShiftIds.has(shift.id) ? 'shift-selected' : '';
                            const shiftColor = (shift && shift.color) ? String(shift.color) : '#6B7280';
                            const shiftIcon = this.resolveShiftIcon((shift && shift.icon) ? String(shift.icon) : '');
                            const timeStart = this.formatTime(shift.start_time);
                            const timeEnd = this.formatTime(shift.end_time);
                            const timeRange = (timeStart && timeEnd) ? `${timeStart}-${timeEnd}` : (timeStart || '');
                            const shiftLabel = (shift.shift_name || shift.shift_code || '').toString();
                            gridHTML += `
                                <div class="turni-shift turni-shift-week ${statusClass} ${selectedClass}"
                                     style="--shift-color: ${this.escapeHtml(shiftColor)}; background-color: ${this.hexToRgba(shiftColor, 0.15)}; border-color: ${this.escapeHtml(shiftColor)};"
                                     data-shift-id="${shift.id}"
                                     title="${this.escapeHtml(shiftLabel)} (${this.escapeHtml(timeStart)} - ${this.escapeHtml(timeEnd)})"
                                     onclick="shiftsApp.openShiftDetail(${shift.id})">
                                    <span class="shift-icon" title="Tipo turno">${this.escapeHtml(shiftIcon)}</span>
                                    <span class="shift-name">${this.escapeHtml(shiftLabel)}</span>
                                    <span class="shift-time">${this.escapeHtml(timeRange)}</span>
                                </div>
                            `;
                        });
                    } else if (this.canManageShifts && this.hasSingleTenantSelection()) {
                        gridHTML += `
                            <button class="add-shift-cell" onclick="shiftsApp.openShiftModal(${employee.id}, '${dateStr}')" title="Aggiungi turno">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="12" y1="5" x2="12" y2="19"></line>
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                </svg>
                            </button>
                        `;
                    }

                    gridHTML += '</div>';
                }

                gridHTML += '</div>';
            });
        }

        gridHTML += '</div>';

        this.container.innerHTML = headerHTML + gridHTML;
    }

    /**
     * Render month view
     */
    renderMonthView() {
        const today = this.formatDateISO(new Date());
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();

        // First day of month
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);

        // Start from Monday of the week containing the first day
        const startDate = new Date(firstDay);
        const dayOfWeek = startDate.getDay();
        const diff = dayOfWeek === 0 ? -6 : 1 - dayOfWeek;
        startDate.setDate(startDate.getDate() + diff);

        // Build header
        let html = '<div class="shifts-month-header">';
        this.dayNamesShort.slice(1).concat(this.dayNamesShort[0]).forEach(day => {
            html += `<div class="shifts-month-day-header">${day}</div>`;
        });
        html += '</div>';

        // Build grid
        html += '<div class="shifts-month-grid">';

        const currentDate = new Date(startDate);
        for (let week = 0; week < 6; week++) {
            for (let day = 0; day < 7; day++) {
                const dateStr = this.formatDateISO(currentDate);
                const isToday = dateStr === today;
                const isCurrentMonth = currentDate.getMonth() === month;

                // Get shifts for this day
                const dayShifts = this.shifts.filter(s => s.shift_date === dateStr);

                html += `
                    <div class="shifts-month-cell ${isToday ? 'today' : ''} ${!isCurrentMonth ? 'other-month' : ''}"
                         data-date="${dateStr}">
                        <div class="month-cell-header">
                            <span class="month-cell-date">${currentDate.getDate()}</span>
                            ${dayShifts.length > 0 ? `<span class="month-cell-count">${dayShifts.length}</span>` : ''}
                        </div>
                        <div class="month-cell-shifts">
                `;

                // Show max 3 shifts, then "+N more"
                const displayShifts = dayShifts.slice(0, 3);
                displayShifts.forEach(shift => {
                    const shiftColor = (shift && shift.color) ? String(shift.color) : '#6B7280';
                    const shiftIcon = this.resolveShiftIcon((shift && shift.icon) ? String(shift.icon) : '');
                    const selectedClass = this.bulkSelectedShiftIds.has(shift.id) ? 'shift-selected' : '';
                    const firstName = (shift.user_name || '').split(' ')[0] || '';
                    const shiftName = (shift.shift_name || shift.shift_code || 'Turno');
                    const label = firstName ? `${firstName} · ${shiftName}` : shiftName;
                    html += `
                        <div class="turni-shift turni-shift-month ${selectedClass}"
                             style="--shift-color: ${this.escapeHtml(shiftColor)}; background-color: ${this.hexToRgba(shiftColor, 0.15)}; border-color: ${this.escapeHtml(shiftColor)};"
                             data-shift-id="${shift.id}"
                             onclick="shiftsApp.openShiftDetail(${shift.id})"
                             title="${this.escapeHtml(shift.user_name)} - ${this.escapeHtml(shift.shift_name)}">
                            <span class="shift-icon" title="Tipo turno">${this.escapeHtml(shiftIcon)}</span>
                            <span class="shift-name">${this.escapeHtml(label)}</span>
                        </div>
                    `;
                });

                if (dayShifts.length > 3) {
                    html += `<div class="month-shift-more">+${dayShifts.length - 3}</div>`;
                }

                html += `
                        </div>
                        ${(this.canManageShifts && this.hasSingleTenantSelection() && isCurrentMonth) ? `
                            <button class="month-cell-add" onclick="shiftsApp.openShiftModal(null, '${dateStr}')" title="Aggiungi turno">+</button>
                        ` : ''}
                    </div>
                `;

                currentDate.setDate(currentDate.getDate() + 1);
            }
        }

        html += '</div>';

        this.container.innerHTML = html;
    }

    /**
     * Render shift types legend
     */
    renderShiftTypesLegend() {
        // Backward compatibility: if the old inline legend container exists, render it.
        const legacyLegend = document.getElementById('shiftsLegend');
        if (legacyLegend) {
            if (!Array.isArray(this.shiftTypes) || this.shiftTypes.length === 0) {
                legacyLegend.innerHTML = '';
                return;
            }
            let html = '';
            this.shiftTypes.forEach(type => {
                html += `
                    <div class="legend-item">
                        <span class="legend-dot" style="background-color: ${type.color};"></span>
                        <span class="legend-label">${this.escapeHtml(type.code)}</span>
                    </div>
                `;
            });
            legacyLegend.innerHTML = html;
            return;
        }

        // New UX: header button opens a modal with the used shift types.
        const btn = document.getElementById('shiftTypesUsedBtn');
        if (!btn) return;

        const used = this.getUsedShiftTypes();
        const n = used.length;
        btn.textContent = n > 0 ? `Tipi turno (${n})` : 'Tipi turno';
        btn.disabled = n === 0;
    }

    /**
     * Compute used shift types for the current loaded period (based on this.shifts).
     * Returns [{ shift_type_id, code, name, color, icon, count }]
     */
    getUsedShiftTypes() {
        const map = new Map();
        (Array.isArray(this.shifts) ? this.shifts : []).forEach((shift) => {
            if (!shift) return;
            const typeId = parseInt(String(shift.shift_type_id || '0'), 10) || 0;
            const tenantId = parseInt(String(shift.tenant_id || '0'), 10) || 0;
            const baseKey = typeId > 0 ? `id:${typeId}` : `code:${String(shift.shift_code || shift.shift_name || 'unknown')}`;
            const key = tenantId > 0 ? `t:${tenantId}|${baseKey}` : baseKey;

            const code = String(shift.shift_code || '').trim();
            const name = String(shift.shift_name || '').trim();
            const color = String(shift.color || shift.shift_color || '').trim() || '#6B7280';
            const icon = String(shift.icon || shift.shift_icon || '').trim();

            const existing = map.get(key);
            if (existing) {
                existing.count += 1;
            } else {
                map.set(key, {
                    shift_type_id: typeId,
                    code: code || (name ? name.substring(0, 12) : '—'),
                    name: name || code || '—',
                    color,
                    icon,
                    count: 1
                });
            }
        });

        const list = Array.from(map.values());
        list.sort((a, b) => {
            const dc = (b.count || 0) - (a.count || 0);
            if (dc !== 0) return dc;
            return String(a.code || '').localeCompare(String(b.code || ''));
        });
        return list;
    }

    /**
     * Open modal with a summary of shift types used in the current period.
     */
    openShiftTypesUsedModal() {
        const rangeEl = document.getElementById('shiftTypesUsedRange');
        const bodyEl = document.getElementById('shiftTypesUsedBody');
        const titleEl = document.getElementById('shiftTypesUsedModalTitle');

        const range = this.getCurrentDateRange();
        if (rangeEl) {
            rangeEl.textContent = `${this.formatDateDisplay(range.start)} → ${this.formatDateDisplay(range.end)}`;
        }
        if (titleEl) {
            titleEl.textContent = 'Tipi turno utilizzati';
        }

        const used = this.getUsedShiftTypes();
        if (bodyEl) {
            if (!used.length) {
                bodyEl.innerHTML = `
                    <div class="shift-types-empty">
                        <p>Nessun turno nel periodo selezionato.</p>
                    </div>
                `;
            } else {
                let rows = '';
                used.forEach((t) => {
                    const dot = `<span class="legend-dot" style="background-color: ${this.escapeHtml(String(t.color || '#6B7280'))};"></span>`;
                    rows += `
                        <tr>
                            <td style="white-space:nowrap;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    ${dot}
                                    <span style="font-weight:600;">${this.escapeHtml(String(t.code || '—'))}</span>
                                </div>
                            </td>
                            <td>${this.escapeHtml(String(t.name || '—'))}</td>
                            <td style="text-align:right; white-space:nowrap;">${this.escapeHtml(String(t.count || 0))}</td>
                        </tr>
                    `;
                });

                bodyEl.innerHTML = `
                    <div style="overflow:auto;">
                        <table class="shift-summary-table">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Nome</th>
                                    <th style="text-align:right;">Totale</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                `;
            }
        }

        this.openModal('shiftTypesUsedModal');
    }

    /**
     * Render requests sidebar
     */
    renderRequestsSidebar() {
        const countBadge = document.getElementById('pendingRequestsCount');
        const listContainer = document.getElementById('requestsList');

        if (countBadge) {
            countBadge.textContent = this.pendingRequests.length;
            countBadge.style.display = this.pendingRequests.length > 0 ? 'flex' : 'none';
        }

        if (!listContainer) return;

        if (this.pendingRequests.length === 0) {
            listContainer.innerHTML = `
                <div class="requests-empty">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M8 14s1.5 2 4 2 4-2 4-2"></path>
                        <line x1="9" y1="9" x2="9.01" y2="9"></line>
                        <line x1="15" y1="9" x2="15.01" y2="9"></line>
                    </svg>
                    <p>Nessuna richiesta in sospeso</p>
                </div>
            `;
            return;
        }

        let html = '';
        this.pendingRequests.forEach(request => {
            html += `
                <div class="request-item" onclick="shiftsApp.openRequestDetail(${request.id})">
                    <div class="request-item-header">
                        <span class="request-user">${this.escapeHtml(request.user_name)}</span>
                        <span class="request-type-badge request-type-${request.request_type}">
                            ${this.requestTypeLabels[request.request_type] || request.request_type}
                        </span>
                    </div>
                    <div class="request-item-date">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        ${this.formatDateDisplay(request.shift_date)}
                    </div>
                    <div class="request-item-actions">
                        <button class="btn btn-sm btn-success" onclick="event.stopPropagation(); shiftsApp.quickApprove(${request.id})">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="event.stopPropagation(); shiftsApp.openRequestDetail(${request.id}, true)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
        });

        listContainer.innerHTML = html;
    }

    /**
     * Populate shift type select elements
     */
    populateShiftTypeSelects() {
        const selects = ['shiftType', 'requestedShiftType'];

        selects.forEach(selectId => {
            const select = document.getElementById(selectId);
            if (!select) return;

            // Keep first option
            const firstOption = select.options[0];
            select.innerHTML = '';
            select.appendChild(firstOption);

            this.shiftTypes.forEach(type => {
                const option = document.createElement('option');
                option.value = type.id;
                option.textContent = `${type.name} (${type.code}) - ${this.formatTime(type.start_time)}-${this.formatTime(type.end_time)}`;
                option.dataset.color = type.color;
                select.appendChild(option);
            });
        });
    }

    /**
     * Populate employee select
     */
    populateEmployeeSelect() {
        const select = document.getElementById('shiftEmployee');
        if (!select) return;

        // Keep first option
        const firstOption = select.options[0];
        select.innerHTML = '';
        select.appendChild(firstOption);

        this.employees.forEach(emp => {
            const option = document.createElement('option');
            option.value = emp.id;
            option.textContent = emp.name;
            select.appendChild(option);
        });

        // Also populate swap target
        const swapSelect = document.getElementById('swapTargetUser');
        if (swapSelect) {
            const swapFirst = swapSelect.options[0];
            swapSelect.innerHTML = '';
            swapSelect.appendChild(swapFirst);

            this.employees.filter(emp => emp.id !== this.currentUserId).forEach(emp => {
                const option = document.createElement('option');
                option.value = emp.id;
                option.textContent = emp.name;
                swapSelect.appendChild(option);
            });
        }
    }

    /**
     * Open shift types modal
     */
    async openShiftTypesModal() {
        if (!this.ensureSingleTenantForManage()) return;
        // Open immediately so the click always gives feedback
        this.openModal('shiftTypesModal');
        const container = document.getElementById('shiftTypesList');
        if (container) {
            container.innerHTML = `
                <div class="shifts-loading">
                    <div class="shifts-spinner"></div>
                    <span>Caricamento tipi turno...</span>
                </div>
            `;
        }
        await this.loadShiftTypes();
        this.renderShiftTypesList();
        this.resetShiftTypeForm();
    }

    // ============================================
    // SUMMARY (counts by type) + WEEKLY HOURS (48h alert)
    // ============================================

    parseDateTimeLocal(dtStr) {
        const s = String(dtStr || '').trim();
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (!m) return null;
        return new Date(
            Number(m[1]),
            Number(m[2]) - 1,
            Number(m[3]),
            Number(m[4]),
            Number(m[5]),
            Number(m[6] || 0)
        );
    }

    getWeekStartLocal(date) {
        const d = new Date(date.getTime());
        d.setHours(0, 0, 0, 0);
        const dow = d.getDay(); // 0=Sun
        const diff = (dow === 0) ? -6 : (1 - dow);
        d.setDate(d.getDate() + diff);
        return d;
    }

    addIntervalToWeeklyBuckets(buckets, userId, startDt, endDt) {
        let s = new Date(startDt.getTime());
        const e = new Date(endDt.getTime());
        // Safety: ignore invalid/negative intervals
        if (!(s instanceof Date) || !(e instanceof Date) || isNaN(s.getTime()) || isNaN(e.getTime()) || e <= s) return;
        while (s < e) {
            const ws = this.getWeekStartLocal(s);
            const we = new Date(ws.getTime() + 7 * 24 * 60 * 60 * 1000);
            const segEnd = (e < we) ? e : we;
            const mins = Math.floor((segEnd.getTime() - s.getTime()) / 60000);
            const wk = this.formatDateISO(ws);
            if (!buckets[userId]) buckets[userId] = {};
            buckets[userId][wk] = (buckets[userId][wk] || 0) + Math.max(0, mins);
            s = segEnd;
        }
    }

    computeWeeklyHoursStats(shifts) {
        const weeklyMinutes = {}; // user_id => { weekStart: minutes }
        const intervalsByUser = {}; // user_id => [{start,end,shift_id}]

        (Array.isArray(shifts) ? shifts : []).forEach(shift => {
            if (!shift) return;
            if (String(shift.status || '') === 'cancelled') return;
            const uid = parseInt(String(shift.user_id || '0'), 10) || 0;
            if (!uid) return;

            const start = this.parseDateTimeLocal(shift.start_datetime || shift.start || '');
            const end = this.parseDateTimeLocal(shift.end_datetime || shift.end || '');
            if (!start || !end) return;

            this.addIntervalToWeeklyBuckets(weeklyMinutes, uid, start, end);
            (intervalsByUser[uid] = intervalsByUser[uid] || []).push({
                start,
                end,
                shift_id: parseInt(String(shift.id || '0'), 10) || 0,
            });
        });

        // Overlaps detection (best-effort): sort by start and check any overlap
        const overlapsByUser = {};
        Object.keys(intervalsByUser).forEach(uid => {
            const list = intervalsByUser[uid] || [];
            list.sort((a, b) => a.start.getTime() - b.start.getTime());
            let prevEnd = null;
            for (const it of list) {
                if (prevEnd && it.start < prevEnd) {
                    overlapsByUser[uid] = true;
                    break;
                }
                if (!prevEnd || it.end > prevEnd) prevEnd = it.end;
            }
        });

        // Per-user max + 48h threshold list
        const maxByUser = {}; // user_id => {max_minutes,max_week_start,max_hours}
        const over48 = [];    // [{user_id,week_start,minutes,hours}]
        Object.keys(weeklyMinutes).forEach(uidStr => {
            const uid = parseInt(uidStr, 10) || 0;
            const weeks = weeklyMinutes[uidStr] || {};
            let maxM = 0;
            let maxW = '';
            Object.keys(weeks).forEach(wk => {
                const m = parseInt(String(weeks[wk] || '0'), 10) || 0;
                if (m > maxM) { maxM = m; maxW = wk; }
                if (m > (48 * 60)) {
                    over48.push({ user_id: uid, week_start: wk, minutes: m, hours: Math.round((m / 60) * 100) / 100 });
                }
            });
            maxByUser[uid] = { max_minutes: maxM, max_week_start: maxW, max_hours: Math.round((maxM / 60) * 100) / 100 };
        });

        return { weeklyMinutes, maxByUser, over48, overlapsByUser };
    }

    updateWeeklyHoursAlerts() {
        // Only when a single tenant is selected (requirement: per-tenant)
        if (!this.hasSingleTenantSelection()) return;
        const range = this.getCurrentDateRange();
        const stats = this.computeWeeklyHoursStats(this.shifts || []);
        this.weeklyHoursStats = stats;

        const scopeOver = (stats.over48 || []).filter(x => {
            if (this.canManageShifts) return true;
            return (parseInt(String(x.user_id || '0'), 10) || 0) === (this.currentUserId || 0);
        });
        const scopeOverlap = Object.keys(stats.overlapsByUser || {}).filter(uidStr => {
            const uid = parseInt(uidStr, 10) || 0;
            if (!uid) return false;
            if (this.canManageShifts) return true;
            return uid === (this.currentUserId || 0);
        });

        const sig = JSON.stringify({
            tenant: this.getSelectedTenantId(),
            range,
            over: scopeOver.map(o => ({ u: o.user_id, w: o.week_start, h: o.hours })).sort((a, b) => (a.u - b.u) || a.w.localeCompare(b.w)),
            ov: scopeOverlap.sort(),
        });
        if (sig === this._lastWeeklyHoursAlertSig) return;
        this._lastWeeklyHoursAlertSig = sig;

        if (scopeOverlap.length) {
            const msg = this.canManageShifts
                ? `Conflitto: rilevate sovrapposizioni turni (${scopeOverlap.length} utente/i) — verifica nel riepilogo.`
                : 'Conflitto: rilevate sovrapposizioni turni — contatta un responsabile.';
            this.showToast(msg, 'error');
        }
        if (scopeOver.length) {
            if (this.canManageShifts) {
                const uniqUsers = [...new Set(scopeOver.map(x => x.user_id))];
                this.showToast(`Attenzione: ${uniqUsers.length} utente/i superano 48 ore settimanali nel periodo.`, 'warning');
            } else {
                const top = scopeOver.sort((a, b) => (b.hours || 0) - (a.hours || 0))[0];
                this.showToast(`Attenzione: superate 48 ore settimanali (${top.hours}h) nella settimana ${top.week_start}.`, 'warning');
            }
        }
    }

    async openShiftSummaryModal() {
        if (!this.hasSingleTenantSelection()) {
            this.showToast("Seleziona un’azienda per vedere il riepilogo", 'warning');
            return;
        }
        // Ensure shift types/users are loaded
        if (this.canManageShifts && (!Array.isArray(this.employees) || this.employees.length === 0)) {
            try { await this.loadEmployees(); } catch (_) {}
        }
        if (!Array.isArray(this.shiftTypes) || this.shiftTypes.length === 0) {
            try { await this.loadShiftTypes(); } catch (_) {}
        }

        this.renderShiftSummary();
        this.openModal('shiftSummaryModal');
    }

    renderShiftSummary() {
        const loading = document.getElementById('shiftSummaryLoading');
        const table = document.getElementById('shiftSummaryTable');
        const thead = document.getElementById('shiftSummaryThead');
        const tbody = document.getElementById('shiftSummaryTbody');
        const alerts = document.getElementById('shiftSummaryAlerts');
        const rangeEl = document.getElementById('shiftSummaryRange');
        const titleEl = document.getElementById('shiftSummaryModalTitle');

        if (loading) loading.style.display = 'block';
        if (table) table.style.display = 'none';
        if (alerts) alerts.innerHTML = '';

        const range = this.getCurrentDateRange();
        if (rangeEl) rangeEl.textContent = `Periodo: ${range.start} → ${range.end}`;
        if (titleEl) titleEl.textContent = this.canManageShifts ? 'Riepilogo turni (tenant selezionato)' : 'Il mio riepilogo turni';

        const shifts = Array.isArray(this.shifts) ? this.shifts : [];
        const shiftTypes = Array.isArray(this.shiftTypes) ? this.shiftTypes : [];

        // Scope users
        let users = [];
        if (this.canManageShifts) {
            users = Array.isArray(this.employees) ? this.employees.map(u => ({ id: u.id, name: u.name })) : [];
        } else {
            users = [{ id: this.currentUserId, name: 'Tu' }];
        }
        users = users.filter(u => u && u.id);

        // Counts per user/type in current range
        const counts = {}; // uid => { typeId: n, total: n }
        shifts.forEach(s => {
            if (!s || String(s.status || '') === 'cancelled') return;
            const uid = parseInt(String(s.user_id || '0'), 10) || 0;
            const tid = parseInt(String(s.shift_type_id || '0'), 10) || 0;
            if (!uid || !tid) return;
            if (!counts[uid]) counts[uid] = { total: 0 };
            counts[uid][tid] = (counts[uid][tid] || 0) + 1;
            counts[uid].total += 1;
        });

        // Weekly hours stats (re-use computed if available)
        const stats = this.weeklyHoursStats && this.weeklyHoursStats.weeklyMinutes ? this.weeklyHoursStats : this.computeWeeklyHoursStats(shifts);

        // Build header
        if (thead) {
            thead.innerHTML = `
                <tr>
                    <th>Persona</th>
                    <th>Ore settimanali (max)</th>
                    <th>Tot turni</th>
                    ${shiftTypes.map(t => {
                        const code = String(t.code || t.name || '').toUpperCase();
                        const label = code ? code : `#${t.id}`;
                        return `<th title="${this.escapeHtml(String(t.name || ''))}">${this.escapeHtml(label)}</th>`;
                    }).join('')}
                </tr>
            `;
        }

        // Build rows
        const rowsHtml = users.map(u => {
            const uid = parseInt(String(u.id || '0'), 10) || 0;
            const c = counts[uid] || { total: 0 };
            const max = (stats.maxByUser && stats.maxByUser[uid]) ? stats.maxByUser[uid] : { max_hours: 0, max_week_start: '' };
            const maxHours = Number(max.max_hours || 0);
            const maxWeek = String(max.max_week_start || '');
            const pillClass = maxHours > 48 ? 'shift-summary-pill danger' : 'shift-summary-pill';
            const hoursLabel = maxHours ? `${this.escapeHtml(String(maxHours))}h` : '—';
            const hoursCell = maxWeek
                ? `<span class="${pillClass}" title="Settimana (lun): ${this.escapeHtml(maxWeek)}">${hoursLabel}</span>`
                : `<span class="${pillClass}">${hoursLabel}</span>`;

            return `
                <tr>
                    <td>${this.escapeHtml(String(u.name || `#${uid}`))}</td>
                    <td>${hoursCell}</td>
                    <td>${this.escapeHtml(String(c.total || 0))}</td>
                    ${shiftTypes.map(t => `<td>${this.escapeHtml(String((c[t.id] || 0)))}</td>`).join('')}
                </tr>
            `;
        }).join('');

        if (tbody) tbody.innerHTML = rowsHtml || `<tr><td colspan="${3 + shiftTypes.length}" class="text-muted">—</td></tr>`;

        // Alerts box: >48h and overlaps (scoped)
        const nameById = {};
        users.forEach(u => { if (u && u.id) nameById[u.id] = String(u.name || `#${u.id}`); });
        shifts.forEach(s => {
            const uid = parseInt(String(s?.user_id || '0'), 10) || 0;
            if (!uid) return;
            if (!nameById[uid] && s.user_name) nameById[uid] = String(s.user_name);
        });

        const over = (stats.over48 || []).filter(x => {
            if (this.canManageShifts) return true;
            return (parseInt(String(x.user_id || '0'), 10) || 0) === (this.currentUserId || 0);
        });
        const overlaps = Object.keys(stats.overlapsByUser || {}).filter(uidStr => {
            const uid = parseInt(uidStr, 10) || 0;
            if (!uid) return false;
            if (this.canManageShifts) return true;
            return uid === (this.currentUserId || 0);
        });
        if (alerts) {
            const lines = [];
            if (overlaps.length) {
                lines.push(`<div><strong>Conflitti</strong>: rilevate sovrapposizioni turni per ${this.escapeHtml(String(overlaps.length))} utente/i.</div>`);
            }
            if (over.length) {
                const items = over.slice(0, 12).map(o => {
                    const nm = nameById[o.user_id] ? `${nameById[o.user_id]} (user_id=${o.user_id})` : `user_id=${o.user_id}`;
                    return `${this.escapeHtml(nm)} • ${this.escapeHtml(String(o.hours))}h • settimana ${this.escapeHtml(String(o.week_start))}`;
                }).join('<br/>');
                lines.push(`<div style="margin-top:6px;"><strong>48h</strong>: superamento ore settimanali</div><div style="margin-top:6px;">${items}</div>`);
            }
            alerts.innerHTML = lines.length ? `<div class="shift-summary-alertbox">${lines.join('')}</div>` : '';
        }

        if (loading) loading.style.display = 'none';
        if (table) table.style.display = 'table';
    }

    /**
     * Render shift types list in modal
     */
    renderShiftTypesList() {
        const container = document.getElementById('shiftTypesList');
        if (!container) return;

        if (!this.hasSingleTenantSelection()) {
            container.innerHTML = `
                <div class="shift-types-empty">
                    <p><strong>Seleziona un’azienda</strong> per gestire Tipi Turno/creare turni.</p>
                </div>
            `;
            return;
        }

        if (this.shiftTypes.length === 0) {
            container.innerHTML = `
                <div class="shift-types-empty">
                    <p>Nessun tipo turno definito. Crea il primo tipo turno qui sotto.</p>
                </div>
            `;
            return;
        }

        let html = '<div class="shift-types-grid">';
        this.shiftTypes.forEach(type => {
            const inactiveBadge = !type.is_active ? `<span class="badge badge-warning" style="margin-left:8px;">Disattivo</span>` : '';
            const desc = (type.description || '').trim();
            const descHtml = desc ? `<div class="shift-type-desc">${this.escapeHtml(desc)}</div>` : '';
            const durHtml = (type.duration_minutes !== null && type.duration_minutes !== undefined)
                ? `<div class="shift-type-meta">Durata: ${this.escapeHtml(String(type.duration_minutes))} min</div>`
                : '';
            html += `
                <div class="shift-type-card" data-id="${type.id}">
                    <div class="shift-type-color" style="background-color: ${type.color};"></div>
                    <div class="shift-type-info">
                        <div class="shift-type-name">${this.escapeHtml(type.name)}${inactiveBadge}</div>
                        <div class="shift-type-code">${this.escapeHtml(type.code)}</div>
                        <div class="shift-type-time">${this.formatTime(type.start_time)} - ${this.formatTime(type.end_time)}</div>
                        ${durHtml}
                        ${descHtml}
                    </div>
                    <div class="shift-type-actions">
                        <button class="btn btn-sm btn-outline" onclick="shiftsApp.editShiftType(${type.id})" title="Modifica">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="shiftsApp.deleteShiftType(${type.id})" title="Elimina">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
        });
        html += '</div>';

        container.innerHTML = html;
    }

    /**
     * Reset shift type form
     */
    resetShiftTypeForm() {
        document.getElementById('editingShiftTypeId').value = '';
        document.getElementById('shiftTypeDescription').value = '';
        document.getElementById('shiftTypeName').value = '';
        document.getElementById('shiftTypeCode').value = '';
        document.getElementById('shiftTypeStartTime').value = '';
        document.getElementById('shiftTypeEndTime').value = '';
        document.getElementById('shiftTypeDurationMinutes').value = '';
        document.getElementById('shiftTypeSortOrder').value = '0';
        document.getElementById('shiftTypeColor').value = '#3B82F6';
        document.getElementById('shiftTypeIcon').value = '';
        document.getElementById('shiftTypeIsActive').checked = true;
        document.getElementById('colorPreview').style.backgroundColor = '#3B82F6';
        document.getElementById('shiftTypeFormTitle').textContent = 'Nuovo Tipo Turno';
        const saveBtn = document.getElementById('saveShiftTypeBtn');
        if (saveBtn) saveBtn.textContent = 'Crea Tipo Turno';
        const cancelBtn = document.getElementById('cancelShiftTypeBtn');
        if (cancelBtn) cancelBtn.textContent = 'Annulla';
    }

    /**
     * Edit shift type
     */
    editShiftType(id) {
        const type = this.shiftTypes.find(t => t.id === id);
        if (!type) return;

        document.getElementById('editingShiftTypeId').value = type.id;
        document.getElementById('shiftTypeDescription').value = type.description || '';
        document.getElementById('shiftTypeName').value = type.name;
        document.getElementById('shiftTypeCode').value = type.code;
        document.getElementById('shiftTypeStartTime').value = type.start_time;
        document.getElementById('shiftTypeEndTime').value = type.end_time;
        document.getElementById('shiftTypeDurationMinutes').value = (type.duration_minutes === null || type.duration_minutes === undefined) ? '' : String(type.duration_minutes);
        document.getElementById('shiftTypeSortOrder').value = (type.sort_order === null || type.sort_order === undefined) ? '0' : String(type.sort_order);
        document.getElementById('shiftTypeColor').value = type.color;
        document.getElementById('shiftTypeIcon').value = type.icon || '';
        document.getElementById('shiftTypeIsActive').checked = !!type.is_active;
        document.getElementById('colorPreview').style.backgroundColor = type.color;
        document.getElementById('shiftTypeFormTitle').textContent = 'Modifica Tipo Turno';
        const saveBtn = document.getElementById('saveShiftTypeBtn');
        if (saveBtn) saveBtn.textContent = 'Aggiorna Tipo Turno';
        const cancelBtn = document.getElementById('cancelShiftTypeBtn');
        if (cancelBtn) cancelBtn.textContent = 'Nuovo';

        // Scroll form into view
        document.getElementById('shiftTypeForm').scrollIntoView({ behavior: 'smooth' });
    }

    /**
     * Save shift type
     */
    async saveShiftType() {
        if (!this.ensureSingleTenantForManage()) return;
        const id = document.getElementById('editingShiftTypeId').value;
        const description = (document.getElementById('shiftTypeDescription')?.value || '').trim();
        const name = document.getElementById('shiftTypeName').value.trim();
        const code = document.getElementById('shiftTypeCode').value.trim().toUpperCase();
        const startTime = document.getElementById('shiftTypeStartTime').value;
        const endTime = document.getElementById('shiftTypeEndTime').value;
        const durationMinutesRaw = (document.getElementById('shiftTypeDurationMinutes')?.value || '').trim();
        const sortOrderRaw = (document.getElementById('shiftTypeSortOrder')?.value || '').trim();
        const color = document.getElementById('shiftTypeColor').value;
        const icon = document.getElementById('shiftTypeIcon').value;
        const isActive = !!document.getElementById('shiftTypeIsActive')?.checked;

        const durationMinutes = durationMinutesRaw === '' ? null : (parseInt(durationMinutesRaw, 10) || 0);
        const sortOrder = sortOrderRaw === '' ? 0 : (parseInt(sortOrderRaw, 10) || 0);

        // Validation
        if (!name || !code || !startTime || !endTime) {
            this.showToast('Compila tutti i campi obbligatori', 'warning');
            return;
        }

        try {
            const action = id ? 'update' : 'create';
            const tenantId = this.getSelectedTenantId();
            if (tenantId <= 0) {
                this.showToast("Seleziona un’azienda per gestire Tipi Turno/creare turni", 'warning');
                return;
            }
            const payload = {
                action,
                tenant_id: tenantId,
                name,
                code,
                description: description || null,
                start_time: startTime,
                end_time: endTime,
                duration_minutes: durationMinutes,
                color,
                icon: (icon || '').trim() || null,
                sort_order: sortOrder,
                is_active: isActive
            };

            if (id) {
                payload.id = parseInt(id);
            }

            const result = await this.apiFetchWithFallback('types', {}, {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            if (result.success) {
                this.showToast(id ? 'Tipo turno aggiornato' : 'Tipo turno creato', 'success');
                await this.loadShiftTypes();
                this.renderShiftTypesList();
                this.resetShiftTypeForm();
            } else {
                throw new Error(result.message || 'Errore salvataggio');
            }
        } catch (error) {
            console.error('[ShiftsApp] Error saving shift type:', error);
            this.showToast(error.message || 'Errore nel salvataggio', 'error');
        }
    }

    /**
     * Delete shift type
     */
    async deleteShiftType(id) {
        if (!this.ensureSingleTenantForManage()) return;
        if (!confirm('Sei sicuro di voler eliminare questo tipo turno?')) return;

        try {
            const tenantId = this.getSelectedTenantId();
            if (tenantId <= 0) {
                this.showToast("Seleziona un’azienda per gestire Tipi Turno/creare turni", 'warning');
                return;
            }
            const result = await this.apiFetchWithFallback('types', {}, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'delete',
                    id: id,
                    tenant_id: tenantId
                })
            });

            if (result.success) {
                this.showToast('Tipo turno eliminato', 'success');
                await this.loadShiftTypes();
                this.renderShiftTypesList();
            } else {
                throw new Error(result.message || 'Errore eliminazione');
            }
        } catch (error) {
            console.error('[ShiftsApp] Error deleting shift type:', error);
            this.showToast(error.message || 'Errore nella eliminazione', 'error');
        }
    }

    /**
     * Open shift modal (create/edit)
     */
    openShiftModal(employeeId = null, date = null) {
        if (this.canManageShifts && !this.ensureSingleTenantForManage()) return;
        // Reset form
        document.getElementById('shiftForm').reset();
        document.getElementById('editingShiftId').value = '';
        document.getElementById('shiftModalTitle').textContent = 'Nuovo Turno';
        document.getElementById('deleteShiftBtn').style.display = 'none';
        document.getElementById('bulkOptions').style.display = 'none';

        // Pre-fill if provided
        if (employeeId) {
            document.getElementById('shiftEmployee').value = employeeId;
        }
        if (date) {
            document.getElementById('shiftDateStart').value = date;
        } else {
            document.getElementById('shiftDateStart').value = this.formatDateISO(new Date());
        }

        // Reset/refresh "turno libero" UI
        try {
            this.updateShiftOverrideTimesHints();
            this.updateShiftOverrideTimesUi();
        } catch (_) {}

        this.openModal('shiftModal');
    }

    // ============================================
    // SHIFT WIZARD (guided bulk creation)
    // ============================================
    async openShiftWizardModal() {
        // Never be silent: open modal even if single-tenant guard fails (show message inside).
        if (!this.ensureSingleTenantForManage()) {
            this.openModal('shiftWizardModal');
            const wrap = document.getElementById('shiftWizardEmployeesWrap');
            if (wrap) {
                wrap.innerHTML = `
                    <div class="shift-types-empty">
                        <p><strong>Seleziona un’azienda</strong> (una sola) per usare il Wizard Turni.</p>
                    </div>
                `;
            }
            return;
        }
        const tenantId = this.getSelectedTenantId();
        if (tenantId <= 0) return;

        // Open immediately so the click always gives feedback
        this.openModal('shiftWizardModal');
        const typeSel = document.getElementById('shiftWizardType');
        if (typeSel) {
            typeSel.innerHTML = `<option value="">Caricamento...</option>`;
        }
        const wrap = document.getElementById('shiftWizardEmployeesWrap');
        if (wrap) {
            wrap.innerHTML = `
                <div class="shifts-loading" style="padding: 12px;">
                    <div class="shifts-spinner" style="width:24px; height:24px; border-width:2px;"></div>
                    <span>Caricamento persone...</span>
                </div>
            `;
        }

        // Ensure data loaded
        await this.loadShiftTypes();
        await this.loadEmployees();

        this.shiftWizard = {
            step: 1,
            shift_type_id: 0,
            user_ids: [],
            start_date: this.formatDateISO(new Date()),
            end_date: this.formatDateISO(new Date()),
            days_of_week: [1, 2, 3, 4, 5],
            status: 'scheduled',
            notes: ''
        };

        // Populate type select
        const typeSel2 = document.getElementById('shiftWizardType');
        if (typeSel2) {
            typeSel2.innerHTML = `<option value="">Seleziona tipo turno...</option>` + (this.shiftTypes || []).map(t => {
                const label = `${t.name} (${t.code}) - ${this.formatTime(t.start_time)}-${this.formatTime(t.end_time)}`;
                return `<option value="${t.id}">${this.escapeHtml(label)}</option>`;
            }).join('');
        }

        // Defaults
        const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
        setVal('shiftWizardStartDate', this.shiftWizard.start_date);
        setVal('shiftWizardEndDate', this.shiftWizard.end_date);
        setVal('shiftWizardStatus', this.shiftWizard.status);
        setVal('shiftWizardNotes', '');
        const search = document.getElementById('shiftWizardEmployeeSearch');
        if (search) search.value = '';

        // Default weekdays: Mon-Fri checked
        const step3 = document.getElementById('shiftWizardStep3');
        if (step3) {
            step3.querySelectorAll('.weekdays-selector input[type="checkbox"]').forEach(cb => {
                const v = parseInt(cb.value, 10);
                cb.checked = [1,2,3,4,5].includes(v);
            });
        }

        this.shiftWizardRenderEmployees();
        this.shiftWizardRender();
        // (already opened above)
    }

    shiftWizardPrev() {
        this.shiftWizard.step = Math.max(1, (this.shiftWizard.step || 1) - 1);
        this.shiftWizardRender();
    }

    async shiftWizardNext() {
        const step = this.shiftWizard.step || 1;

        // Collect current step inputs
        if (step === 1) {
            const typeId = parseInt(document.getElementById('shiftWizardType')?.value || '0', 10) || 0;
            if (!typeId) return this.showToast('Seleziona un tipo turno', 'warning');
            this.shiftWizard.shift_type_id = typeId;
        }
        if (step === 2) {
            if (!this.shiftWizard.user_ids || this.shiftWizard.user_ids.length === 0) {
                return this.showToast('Seleziona almeno 1 persona', 'warning');
            }
        }
        if (step === 3) {
            const sd = (document.getElementById('shiftWizardStartDate')?.value || '').trim();
            const ed = (document.getElementById('shiftWizardEndDate')?.value || '').trim();
            if (!sd || !ed) return this.showToast('Seleziona data inizio/fine', 'warning');
            this.shiftWizard.start_date = sd;
            this.shiftWizard.end_date = ed;
            this.shiftWizard.status = (document.getElementById('shiftWizardStatus')?.value || 'scheduled');
            this.shiftWizard.notes = (document.getElementById('shiftWizardNotes')?.value || '').trim();

            const days = [];
            document.querySelectorAll('#shiftWizardStep3 .weekdays-selector input[type="checkbox"]').forEach(cb => {
                if (cb.checked) days.push(parseInt(cb.value, 10));
            });
            if (!days.length) return this.showToast('Seleziona almeno un giorno della settimana', 'warning');
            this.shiftWizard.days_of_week = days;
        }

        if (step < 4) {
            this.shiftWizard.step = step + 1;
            this.shiftWizardRender();
            return;
        }

        // Step 4: execute bulk_create
        if (!this.ensureSingleTenantForManage()) return;
        const tenantId = this.getSelectedTenantId();
        if (tenantId <= 0) return;

        const preview = this.shiftWizardBuildPreview({ limit: 999999 });
        if (preview.total > 100) {
            return this.showToast(`Troppi turni (${preview.total}). Riduci periodo/giorni o persone (max 100).`, 'warning');
        }
        if (preview.total <= 0) return this.showToast('Nessun turno da creare', 'warning');

        const nextBtn = document.getElementById('shiftWizardNextBtn');
        const prevLabel = nextBtn ? nextBtn.textContent : '';
        if (nextBtn) { nextBtn.disabled = true; nextBtn.textContent = 'Creazione...'; }

        try {
            const payload = {
                action: 'bulk_create',
                tenant_id: tenantId,
                shift_type_id: this.shiftWizard.shift_type_id,
                user_ids: this.shiftWizard.user_ids,
                start_date: this.shiftWizard.start_date,
                end_date: this.shiftWizard.end_date,
                days_of_week: this.shiftWizard.days_of_week,
                status: this.shiftWizard.status || 'scheduled',
                notes: this.shiftWizard.notes || null
            };

            const result = await this.apiFetchWithFallback('manage', {}, {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            const createdCount = result?.data?.created_count ?? 0;
            const errorCount = result?.data?.error_count ?? 0;
            const errors = Array.isArray(result?.data?.errors) ? result.data.errors : [];
            if (createdCount > 0) {
                this.showToast(`Creati ${createdCount} turni` + (errorCount ? ` (${errorCount} errori)` : ''), 'success');
            } else {
                const firstErr = (errors && errors.length) ? String(errors[0]?.error || '').trim() : '';
                const extra = firstErr ? `: ${firstErr}` : '';
                this.showToast((errorCount ? 'Nessun turno creato (errori presenti)' : 'Nessun turno creato') + extra, 'warning');
            }
            const warnings = Array.isArray(result?.data?.warnings) ? result.data.warnings : [];
            if (warnings.length) {
                const msg = warnings.length > 1 ? `${warnings[0]} (+${warnings.length - 1})` : warnings[0];
                this.showToast(msg, 'warning');
            }

            // If nothing was created, keep the wizard open and show the first errors in the preview box.
            // This avoids "silent" failures where the user only sees a generic toast.
            if (createdCount <= 0) {
                try { console.warn('[ShiftsApp] Wizard bulk_create: no shifts created', { createdCount, errorCount, errors, warnings }); } catch (_) {}
                const box = document.getElementById('shiftWizardPreviewBox');
                if (box) {
                    const fmtUser = (uid) => {
                        const id = parseInt(String(uid || '0'), 10) || 0;
                        const emp = (this.employees || []).find(e => parseInt(String(e.id || '0'), 10) === id);
                        return emp ? (emp.name || `#${id}`) : (id ? `#${id}` : '—');
                    };
                    const fmtDate = (d) => String(d || '').trim() || '—';
                    const fmtConflict = (c) => {
                        if (!c || typeof c !== 'object') return '';
                        const nm = String(c.conflict_shift_name || '').trim();
                        const sd = String(c.conflict_shift_date || '').trim();
                        const ss = String(c.conflict_start_datetime || '').trim();
                        const se = String(c.conflict_end_datetime || '').trim();
                        const head = [nm, sd].filter(Boolean).join(' — ');
                        const tail = [ss, se].filter(Boolean).join(' → ');
                        const msg = [head, tail].filter(Boolean).join(' — ');
                        return msg ? ` (${msg})` : '';
                    };

                    const errRows = (errors || []).slice(0, 12).map(er => {
                        const idx = (er && er.index !== undefined) ? String(er.index) : '';
                        const uid = er?.user_id;
                        const date = er?.shift_date;
                        const msg = String(er?.error || 'Errore').trim();
                        const conf = fmtConflict(er?.conflict);
                        return `<tr>
                            <td class="text-muted">${this.escapeHtml(idx)}</td>
                            <td>${this.escapeHtml(fmtDate(date))}</td>
                            <td>${this.escapeHtml(fmtUser(uid))}</td>
                            <td>${this.escapeHtml(msg)}${this.escapeHtml(conf)}</td>
                        </tr>`;
                    }).join('') || `<tr><td colspan="4" class="text-muted">Nessun dettaglio errori disponibile.</td></tr>`;

                    box.innerHTML = `
                        <div class="text-sm" style="color: var(--color-error); font-weight:700; margin-bottom:8px;">
                            Nessun turno creato
                        </div>
                        <div class="text-sm text-muted" style="margin-bottom:10px;">
                            Cause possibili: turni già presenti, sovrapposizioni, oppure utenti non associati all’azienda selezionata. Correggi persone/periodo/giorni e riprova.
                        </div>
                        <div style="overflow:auto;">
                            <table>
                                <thead><tr><th>#</th><th>Data</th><th>Persona</th><th>Dettaglio</th></tr></thead>
                                <tbody>${errRows}</tbody>
                            </table>
                        </div>
                        ${(errors || []).length > 12 ? `<div class="text-sm text-muted" style="margin-top:8px;">Mostrati i primi 12 errori su ${(errors || []).length}.</div>` : ``}
                    `;
                }
                return; // keep modal open
            }

            this.closeModal('shiftWizardModal');
            await this.loadShifts();
        } catch (e) {
            console.error('[ShiftsApp] Wizard bulk_create error:', e);
            this.showToast(e?.message || 'Errore creazione turni', 'error');
        } finally {
            if (nextBtn) { nextBtn.disabled = false; nextBtn.textContent = prevLabel || 'Avanti'; }
        }
    }

    shiftWizardRenderEmployees() {
        const wrap = document.getElementById('shiftWizardEmployeesWrap');
        const hint = document.getElementById('shiftWizardEmployeesHint');
        if (!wrap) return;
        const term = (document.getElementById('shiftWizardEmployeeSearch')?.value || '').trim().toLowerCase();

        const employees = Array.isArray(this.employees) ? this.employees : [];
        const filtered = term ? employees.filter(e => String(e.name || '').toLowerCase().includes(term)) : employees;

        wrap.innerHTML = filtered.map(emp => {
            const checked = (this.shiftWizard.user_ids || []).includes(emp.id) ? 'checked' : '';
            return `
                <label class="weekday-checkbox" style="justify-content:flex-start;">
                    <input type="checkbox" data-emp-id="${emp.id}" ${checked}>
                    <span>${this.escapeHtml(emp.name || '')}</span>
                </label>
            `;
        }).join('') || `<div class="text-muted">Nessun risultato</div>`;

        wrap.querySelectorAll('input[type="checkbox"][data-emp-id]').forEach(cb => {
            cb.addEventListener('change', () => {
                const id = parseInt(cb.getAttribute('data-emp-id') || '0', 10) || 0;
                if (!id) return;
                const set = new Set(this.shiftWizard.user_ids || []);
                if (cb.checked) set.add(id); else set.delete(id);
                this.shiftWizard.user_ids = Array.from(set.values());
                if (hint) hint.textContent = `Selezionati: ${this.shiftWizard.user_ids.length}`;
            });
        });

        if (hint) hint.textContent = `Selezionati: ${(this.shiftWizard.user_ids || []).length}`;
    }

    shiftWizardSelectAllVisibleEmployees() {
        const wrap = document.getElementById('shiftWizardEmployeesWrap');
        if (!wrap) return;
        const ids = [];
        wrap.querySelectorAll('input[type="checkbox"][data-emp-id]').forEach(cb => {
            const id = parseInt(cb.getAttribute('data-emp-id') || '0', 10) || 0;
            if (id > 0) ids.push(id);
        });
        if (!ids.length) return;
        const set = new Set(this.shiftWizard.user_ids || []);
        ids.forEach(id => set.add(id));
        this.shiftWizard.user_ids = Array.from(set.values());
        // Re-render to reflect checked state (and keep in sync with search filter)
        this.shiftWizardRenderEmployees();
    }

    shiftWizardSelectOnlyVisibleEmployees() {
        const wrap = document.getElementById('shiftWizardEmployeesWrap');
        if (!wrap) return;
        const ids = [];
        wrap.querySelectorAll('input[type="checkbox"][data-emp-id]').forEach(cb => {
            const id = parseInt(cb.getAttribute('data-emp-id') || '0', 10) || 0;
            if (id > 0) ids.push(id);
        });
        this.shiftWizard.user_ids = Array.from(new Set(ids));
        this.shiftWizardRenderEmployees();
    }

    shiftWizardClearAllEmployees() {
        this.shiftWizard.user_ids = [];
        this.shiftWizardRenderEmployees();
    }

    shiftWizardExportPreviewCsv() {
        try {
            const preview = this.shiftWizardBuildPreview({ limit: 999999 });
            if (!preview || preview.total <= 0) {
                return this.showToast('Nessun turno da esportare', 'warning');
            }

            const type = preview.type || (this.shiftTypes || []).find(t => t.id === this.shiftWizard.shift_type_id) || null;
            const typeName = type ? (type.name || '') : '';
            const typeCode = type ? (type.code || '') : '';
            const timeTxt = type ? `${this.formatTime(type.start_time)}-${this.formatTime(type.end_time)}${(String(type.end_time) < String(type.start_time)) ? ' (+1)' : ''}` : '';
            const status = this.shiftWizard.status || 'scheduled';
            const notes = this.shiftWizard.notes || '';

            const escapeCsv = (v) => {
                const s = String(v ?? '');
                const needs = /[",\n;]/.test(s);
                const out = s.replace(/"/g, '""');
                return needs ? `"${out}"` : out;
            };

            // Use ; as separator (common in Italian Excel locales)
            const lines = [];
            lines.push(['data','persona','tipo_turno','codice','orario','status','note'].map(escapeCsv).join(';'));
            for (const r of (preview.rows || [])) {
                lines.push([
                    r.date || '',
                    r.user || '',
                    typeName,
                    typeCode,
                    r.time || timeTxt,
                    status,
                    notes
                ].map(escapeCsv).join(';'));
            }

            const csv = lines.join('\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            const ts = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
            a.href = url;
            a.download = `turni_wizard_${ts}.csv`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 2500);
        } catch (e) {
            console.error('[ShiftsApp] export CSV failed:', e);
            this.showToast('Errore export CSV', 'error');
        }
    }

    shiftWizardBuildPreview({ limit = 20 } = {}) {
        const typeId = parseInt(String(this.shiftWizard.shift_type_id || '0'), 10) || 0;
        const userIds = Array.isArray(this.shiftWizard.user_ids) ? this.shiftWizard.user_ids : [];
        const days = Array.isArray(this.shiftWizard.days_of_week) ? this.shiftWizard.days_of_week : [];
        const sd = this.shiftWizard.start_date || '';
        const ed = this.shiftWizard.end_date || '';

        const start = new Date(sd + 'T00:00:00');
        const end = new Date(ed + 'T00:00:00');
        if (!typeId || !userIds.length || !sd || !ed || !(start <= end) || !days.length) return { total: 0, rows: [] };

        const type = (this.shiftTypes || []).find(t => t.id === typeId) || null;
        const startTime = type ? this.formatTime(type.start_time) : '';
        const endTime = type ? this.formatTime(type.end_time) : '';
        const overnight = (type && type.end_time && type.start_time) ? (String(type.end_time) < String(type.start_time)) : false;

        const rows = [];
        let total = 0;
        const cur = new Date(start.getTime());
        while (cur <= end) {
            const dow = cur.getDay(); // 0..6
            if (days.includes(dow)) {
                for (const uid of userIds) {
                    total += 1;
                    if (rows.length < limit) {
                        const emp = (this.employees || []).find(e => e.id === uid);
                        rows.push({
                            // IMPORTANT: keep dates in local calendar (avoid UTC shift in Italy/CEST).
                            // Using toISOString() would shift dates back by 1 day around midnight.
                            date: this.formatDateISO(cur),
                            user: emp ? (emp.name || `#${uid}`) : `#${uid}`,
                            time: (startTime && endTime) ? `${startTime}-${endTime}${overnight ? ' (+1)' : ''}` : ''
                        });
                    }
                }
            }
            cur.setDate(cur.getDate() + 1);
        }
        return { total, rows, type, overnight };
    }

    shiftWizardRender() {
        const step = this.shiftWizard.step || 1;
        const indicator = document.getElementById('shiftWizardStepIndicator');
        if (indicator) indicator.innerHTML = `<strong>Step ${step}/4</strong>`;

        const show = (id, on) => { const el = document.getElementById(id); if (el) el.style.display = on ? 'block' : 'none'; };
        show('shiftWizardStep1', step === 1);
        show('shiftWizardStep2', step === 2);
        show('shiftWizardStep3', step === 3);
        show('shiftWizardStep4', step === 4);

        const backBtn = document.getElementById('shiftWizardBackBtn');
        if (backBtn) backBtn.disabled = step === 1;

        const nextBtn = document.getElementById('shiftWizardNextBtn');
        if (nextBtn) nextBtn.textContent = (step === 4) ? 'Crea turni' : 'Avanti';

        if (step === 4) {
            const box = document.getElementById('shiftWizardPreviewBox');
            if (!box) return;
            const type = (this.shiftTypes || []).find(t => t.id === this.shiftWizard.shift_type_id);
            const preview = this.shiftWizardBuildPreview({ limit: 20 });
            const over = preview.total > 100;
            const warn = over ? `<div class="text-sm" style="color: var(--color-error); font-weight:600; margin-bottom:8px;">Troppi turni: ${preview.total} (max 100). Riduci periodo/giorni o persone.</div>` : '';

            const statusLabel = (this.statusLabels && this.statusLabels[this.shiftWizard.status]) ? this.statusLabels[this.shiftWizard.status] : (this.shiftWizard.status || 'scheduled');
            const daysNames = { 0: 'Dom', 1: 'Lun', 2: 'Mar', 3: 'Mer', 4: 'Gio', 5: 'Ven', 6: 'Sab' };
            const daysTxt = (this.shiftWizard.days_of_week || []).map(d => daysNames[d] || String(d)).join(', ');
            const timeTxt = type ? `${this.formatTime(type.start_time)}-${this.formatTime(type.end_time)}${(String(type.end_time) < String(type.start_time)) ? ' (+1)' : ''}` : '';

            box.innerHTML = `
                ${warn}
                <div class="text-sm text-muted" style="margin-bottom:10px;">
                    <div><strong>Tipo turno:</strong> ${this.escapeHtml(type ? type.name : String(this.shiftWizard.shift_type_id))} ${timeTxt ? `(${this.escapeHtml(timeTxt)})` : ''}</div>
                    <div><strong>Periodo:</strong> ${this.escapeHtml(String(this.shiftWizard.start_date || ''))} → ${this.escapeHtml(String(this.shiftWizard.end_date || ''))} — <strong>Giorni:</strong> ${this.escapeHtml(daysTxt || '—')}</div>
                    <div><strong>Status:</strong> ${this.escapeHtml(String(statusLabel))}${this.shiftWizard.notes ? ` — <strong>Note:</strong> ${this.escapeHtml(String(this.shiftWizard.notes))}` : ''}</div>
                    <div style="margin-top:6px;"><strong>Persone:</strong> ${(this.shiftWizard.user_ids || []).length} — <strong>Totale turni:</strong> ${preview.total}</div>
                </div>
                <div style="overflow:auto;">
                    <table>
                        <thead><tr><th>Data</th><th>Persona</th><th>Orario</th></tr></thead>
                        <tbody>
                            ${preview.rows.map(r => `<tr><td>${this.escapeHtml(r.date)}</td><td>${this.escapeHtml(r.user)}</td><td class="text-muted">${this.escapeHtml(r.time || '')}</td></tr>`).join('') || '<tr><td colspan="3" class="text-muted">—</td></tr>'}
                        </tbody>
                    </table>
                </div>
                ${preview.total > 20 ? `<div class="text-sm text-muted" style="margin-top:8px;">Anteprima limitata ai primi 20 turni.</div>` : ''}
            `;
        }
    }

    /**
     * Handle date end change (show bulk options)
     */
    handleDateEndChange(e) {
        const dateEnd = e.target.value;
        const bulkOptions = document.getElementById('bulkOptions');

        if (dateEnd) {
            bulkOptions.style.display = 'block';
        } else {
            bulkOptions.style.display = 'none';
        }

        // Bulk creation disables per-shift free time overrides
        try { this.updateShiftOverrideTimesUi(); } catch (_) {}
    }

    updateShiftOverrideTimesHints() {
        const sel = document.getElementById('shiftType');
        const typeId = parseInt(sel?.value || '0', 10) || 0;
        const type = (this.shiftTypes || []).find(t => t.id === typeId) || null;

        const hint = document.getElementById('shiftOverrideTimesHint');
        const hintStart = document.getElementById('shiftStartTimeOverrideHint');
        const hintEnd = document.getElementById('shiftEndTimeOverrideHint');

        if (!type) {
            if (hint) hint.textContent = 'Opzionale: modifica l’orario solo per questo turno (senza creare un nuovo tipo turno).';
            if (hintStart) hintStart.textContent = 'Default: —';
            if (hintEnd) hintEnd.textContent = 'Default: —';
            return;
        }

        const st = this.formatTime(type.start_time);
        const en = this.formatTime(type.end_time);
        const overnight = (type && type.end_time && type.start_time) ? (String(type.end_time) < String(type.start_time)) : false;
        if (hint) {
            const base = (st && en) ? `${st}-${en}${overnight ? ' (+1)' : ''}` : (st || en || '—');
            hint.textContent = `Opzionale: modifica l’orario solo per questo turno. Orario base: ${base}`;
        }
        if (hintStart) hintStart.textContent = `Default: ${st || '—'}`;
        if (hintEnd) hintEnd.textContent = `Default: ${(en || '—')}${overnight ? ' (+1)' : ''}`;
    }

    updateShiftOverrideTimesUi() {
        const group = document.getElementById('shiftOverrideTimesGroup');
        const row = document.getElementById('shiftOverrideTimesRow');
        const chkMain = document.getElementById('shiftOverrideTimesEnabled');
        const chkStart = document.getElementById('shiftStartOverrideEnabled');
        const chkEnd = document.getElementById('shiftEndOverrideEnabled');
        const inStart = document.getElementById('shiftStartTimeOverride');
        const inEnd = document.getElementById('shiftEndTimeOverride');
        const hint = document.getElementById('shiftOverrideTimesHint');

        if (!chkMain || !row) return;

        const dateStart = document.getElementById('shiftDateStart')?.value || '';
        const dateEnd = document.getElementById('shiftDateEnd')?.value || '';
        const isBulk = !!(dateEnd && dateStart && dateEnd !== dateStart);

        if (isBulk) {
            chkMain.checked = false;
            chkMain.disabled = true;
            if (hint) hint.textContent = 'Turno libero non disponibile per creazioni BULK (usa singolo giorno).';
            row.style.display = 'none';
            if (chkStart) { chkStart.checked = false; chkStart.disabled = true; }
            if (chkEnd) { chkEnd.checked = false; chkEnd.disabled = true; }
            if (inStart) { inStart.value = ''; inStart.disabled = true; }
            if (inEnd) { inEnd.value = ''; inEnd.disabled = true; }
            if (group) group.style.opacity = '0.65';
            return;
        }

        chkMain.disabled = false;
        if (group) group.style.opacity = '';

        const enabled = !!chkMain.checked;
        row.style.display = enabled ? 'flex' : 'none';

        if (!enabled) {
            if (chkStart) { chkStart.checked = false; chkStart.disabled = true; }
            if (chkEnd) { chkEnd.checked = false; chkEnd.disabled = true; }
            if (inStart) { inStart.value = ''; inStart.disabled = true; }
            if (inEnd) { inEnd.value = ''; inEnd.disabled = true; }
            return;
        }

        if (chkStart) chkStart.disabled = false;
        if (chkEnd) chkEnd.disabled = false;

        const startOn = !!(chkStart && chkStart.checked);
        const endOn = !!(chkEnd && chkEnd.checked);
        if (inStart) {
            inStart.disabled = !startOn;
            if (!startOn) inStart.value = '';
        }
        if (inEnd) {
            inEnd.disabled = !endOn;
            if (!endOn) inEnd.value = '';
        }
    }

    /**
     * Save shift
     */
    async saveShift() {
        if (!this.ensureSingleTenantForManage()) return;
        const id = document.getElementById('editingShiftId').value;
        const employeeId = document.getElementById('shiftEmployee').value;
        const shiftTypeId = document.getElementById('shiftType').value;
        const dateStart = document.getElementById('shiftDateStart').value;
        const dateEnd = document.getElementById('shiftDateEnd').value;
        const notes = document.getElementById('shiftNotes').value.trim();
        const overrideMain = !!document.getElementById('shiftOverrideTimesEnabled')?.checked;
        const overrideStartOn = !!document.getElementById('shiftStartOverrideEnabled')?.checked;
        const overrideEndOn = !!document.getElementById('shiftEndOverrideEnabled')?.checked;
        const overrideStart = String(document.getElementById('shiftStartTimeOverride')?.value || '').trim();
        const overrideEnd = String(document.getElementById('shiftEndTimeOverride')?.value || '').trim();

        // Validation
        if (!employeeId || !shiftTypeId || !dateStart) {
            this.showToast('Compila tutti i campi obbligatori', 'warning');
            return;
        }

        const isBulk = !!(dateEnd && dateEnd !== dateStart);

        // Turno libero validation (single-day only)
        if (isBulk && overrideMain) {
            this.showToast('Turno libero non disponibile per creazioni BULK (usa singolo giorno)', 'warning');
            return;
        }
        if (overrideMain) {
            if (!overrideStartOn && !overrideEndOn) {
                this.showToast('Seleziona “Entrata posticipata” e/o “Uscita anticipata”', 'warning');
                return;
            }
            const hhmm = /^\d{2}:\d{2}$/;
            if (overrideStartOn) {
                if (!overrideStart) {
                    this.showToast('Imposta l’orario di entrata', 'warning');
                    return;
                }
                if (!hhmm.test(overrideStart)) {
                    this.showToast('Formato orario entrata non valido (HH:MM)', 'warning');
                    return;
                }
            }
            if (overrideEndOn) {
                if (!overrideEnd) {
                    this.showToast('Imposta l’orario di uscita', 'warning');
                    return;
                }
                if (!hhmm.test(overrideEnd)) {
                    this.showToast('Formato orario uscita non valido (HH:MM)', 'warning');
                    return;
                }
            }
        }

        try {
            const tenantId = this.getSelectedTenantId();
            if (tenantId <= 0) {
                this.showToast("Seleziona un’azienda per gestire Tipi Turno/creare turni", 'warning');
                return;
            }
            let payload;

            if (isBulk) {
                // Bulk create
                const weekdays = Array.from(document.querySelectorAll('input[name="weekdays"]:checked'))
                    .map(cb => parseInt(cb.value));

                if (weekdays.length === 0) {
                    this.showToast('Seleziona almeno un giorno della settimana', 'warning');
                    return;
                }

                payload = {
                    action: 'bulk_create',
                    tenant_id: tenantId,
                    user_id: parseInt(employeeId),
                    shift_type_id: parseInt(shiftTypeId),
                    start_date: dateStart,
                    end_date: dateEnd,
                    days_of_week: weekdays,
                    notes: notes
                };
            } else if (id) {
                // Update existing
                payload = {
                    action: 'update',
                    id: parseInt(id),
                    tenant_id: tenantId,
                    shift_type_id: parseInt(shiftTypeId),
                    notes: notes,
                    // Always send explicit keys so unchecking clears overrides
                    start_time_override: (overrideMain && overrideStartOn) ? overrideStart : '',
                    end_time_override: (overrideMain && overrideEndOn) ? overrideEnd : ''
                };
            } else {
                // Create single
                payload = {
                    action: 'create',
                    tenant_id: tenantId,
                    user_id: parseInt(employeeId),
                    shift_type_id: parseInt(shiftTypeId),
                    shift_date: dateStart,
                    notes: notes,
                    start_time_override: (overrideMain && overrideStartOn) ? overrideStart : '',
                    end_time_override: (overrideMain && overrideEndOn) ? overrideEnd : ''
                };
            }

            const result = await this.apiFetchWithFallback('manage', {}, {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            if (result.success) {
                this.showToast(result.message || 'Turno salvato', 'success');
                const warnings = Array.isArray(result?.data?.warnings) ? result.data.warnings : [];
                if (warnings.length) {
                    const msg = warnings.length > 1 ? `${warnings[0]} (+${warnings.length - 1})` : warnings[0];
                    this.showToast(msg, 'warning');
                }
                this.closeModal('shiftModal');
                await this.loadShifts();
            } else {
                throw new Error(result.message || 'Errore salvataggio');
            }
        } catch (error) {
            console.error('[ShiftsApp] Error saving shift:', error);
            this.showToast(error.message || 'Errore nel salvataggio', 'error');
        }
    }

    // ============================================
    // SHIFT BALANCING (SUGGEST + APPLY)
    // ============================================

    ensureBalanceModal() {
        if (document.getElementById('shiftBalanceModal')) return;

        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.id = 'shiftBalanceModal';
        modal.innerHTML = `
            <div class="modal-backdrop"></div>
            <div class="modal-content modal-lg">
                <div class="modal-header">
                    <h2 class="modal-title">Bilanciamento Turni (Proposta)</h2>
                    <button type="button" class="modal-close" data-dismiss="modal">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="balanceShiftType">Tipo turno *</label>
                            <select id="balanceShiftType"></select>
                        </div>
                        <div class="form-group">
                            <label for="balanceRequiredPerDay">Turni / giorno</label>
                            <input type="number" id="balanceRequiredPerDay" min="1" max="10" value="1">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="balanceStartDate">Dal</label>
                            <input type="date" id="balanceStartDate">
                        </div>
                        <div class="form-group">
                            <label for="balanceEndDate">Al</label>
                            <input type="date" id="balanceEndDate">
                        </div>
                    </div>

                    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-top: 6px;">
                        <label style="display:flex; gap:8px; align-items:center;">
                            <input type="checkbox" id="balanceIncludeWeekend">
                            <span>Includi weekend</span>
                        </label>
                        <span class="text-sm text-muted">Usa automaticamente i dipendenti caricati per l'azienda selezionata.</span>
                    </div>

                    <div style="margin-top: 14px; display:flex; justify-content:flex-end; gap: 8px;">
                        <button class="btn btn-outline" id="balancePreviewBtn">Genera proposta</button>
                        <button class="btn btn-primary" id="balanceApplyBtn" disabled>Applica</button>
                    </div>

                    <div id="balanceSummary" style="margin-top: 14px; font-size: 13px; color: var(--color-gray-700);"></div>
                    <div id="balancePreview" style="margin-top: 10px; max-height: 280px; overflow:auto; border: 1px solid var(--color-gray-200); border-radius: var(--radius-md);"></div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Bind close handlers
        modal.querySelectorAll('.modal-close, [data-dismiss="modal"], .modal-backdrop').forEach(el => {
            el.addEventListener('click', () => this.closeModal('shiftBalanceModal'));
        });

        document.getElementById('balancePreviewBtn').addEventListener('click', () => this.generateBalanceSuggestion());
        document.getElementById('balanceApplyBtn').addEventListener('click', () => this.applyBalanceSuggestion());
    }

    openBalanceModal() {
        if (!this.canManageShifts) {
            this.showToast('Permessi insufficienti per bilanciare i turni', 'warning');
            return;
        }
        if (!this.hasSingleTenantSelection()) {
            this.showToast("Seleziona un’azienda per gestire Tipi Turno/creare turni", 'warning');
            return;
        }
        this.ensureBalanceModal();

        // Fill shift types
        const sel = document.getElementById('balanceShiftType');
        sel.innerHTML = `<option value="">Seleziona...</option>` + (this.shiftTypes || []).map(t =>
            `<option value="${t.id}">${this.escapeHtml(t.name)} (${this.escapeHtml(t.code || '')})</option>`
        ).join('');

        // Default range = current view range
        const r = this.getCurrentDateRange();
        document.getElementById('balanceStartDate').value = r.start;
        document.getElementById('balanceEndDate').value = r.end;
        document.getElementById('balanceRequiredPerDay').value = '1';
        document.getElementById('balanceIncludeWeekend').checked = false;

        document.getElementById('balanceApplyBtn').disabled = true;
        document.getElementById('balanceSummary').innerHTML = '';
        document.getElementById('balancePreview').innerHTML = '';

        this.openModal('shiftBalanceModal');
    }

    async generateBalanceSuggestion() {
        if (!this.ensureSingleTenantForManage()) return;
        const shiftTypeId = parseInt(document.getElementById('balanceShiftType').value || '0');
        const startDate = document.getElementById('balanceStartDate').value;
        const endDate = document.getElementById('balanceEndDate').value;
        const requiredPerDay = parseInt(document.getElementById('balanceRequiredPerDay').value || '1');
        const includeWeekend = !!document.getElementById('balanceIncludeWeekend').checked;

        if (!shiftTypeId || !startDate || !endDate) {
            this.showToast('Seleziona tipo turno e range date', 'warning');
            return;
        }
        const tenantId = this.getSelectedTenantId();
        if (!tenantId || tenantId <= 0) {
            this.showToast("Seleziona un’azienda per gestire Tipi Turno/creare turni", 'warning');
            return;
        }

        const userIds = (this.employees || []).map(e => parseInt(e.id)).filter(n => Number.isFinite(n) && n > 0);
        if (userIds.length === 0) {
            this.showToast('Nessun dipendente disponibile', 'warning');
            return;
        }

        const daysOfWeek = includeWeekend ? [0,1,2,3,4,5,6] : [1,2,3,4,5]; // Lun-Ven di default

        try {
            const result = await this.apiFetchWithFallback('suggest', {}, {
                method: 'POST',
                body: JSON.stringify({
                    tenant_id: tenantId,
                    start_date: startDate,
                    end_date: endDate,
                    shift_type_id: shiftTypeId,
                    required_per_day: requiredPerDay,
                    days_of_week: daysOfWeek,
                    user_ids: userIds
                })
            });

            if (!result.success || !result.data) {
                throw new Error(result.message || 'Errore generazione proposta');
            }

            const suggestions = result.data.suggestions || [];
            this._lastBalanceSuggestion = suggestions;

            const stats = result.data.stats || {};
            document.getElementById('balanceSummary').innerHTML =
                `Giorni: <strong>${stats.days ?? '-'}</strong> · Slot: <strong>${stats.total_slots ?? '-'}</strong> · Proposti: <strong>${stats.suggested_count ?? suggestions.length}</strong> · Saltati: <strong>${stats.skipped_slots ?? 0}</strong>`;

            const preview = document.getElementById('balancePreview');
            if (suggestions.length === 0) {
                preview.innerHTML = `<div style="padding:12px; color: var(--color-gray-600);">Nessuna assegnazione proposta (probabili collisioni con turni esistenti).</div>`;
                document.getElementById('balanceApplyBtn').disabled = true;
                return;
            }

            const rows = suggestions.slice(0, 200).map(s =>
                `<tr>
                    <td style="padding:8px 10px; border-bottom:1px solid var(--color-gray-200); white-space:nowrap;">${this.formatDateDisplay(s.shift_date)}</td>
                    <td style="padding:8px 10px; border-bottom:1px solid var(--color-gray-200);">${this.escapeHtml(s.user_name || '')}</td>
                 </tr>`
            ).join('');
            preview.innerHTML = `
                <table style="width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="background: var(--color-gray-100);">
                            <th style="padding:8px 10px; text-align:left; font-size:12px; text-transform:uppercase;">Data</th>
                            <th style="padding:8px 10px; text-align:left; font-size:12px; text-transform:uppercase;">Dipendente</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            `;
            document.getElementById('balanceApplyBtn').disabled = false;
        } catch (e) {
            console.error('[ShiftsApp] generateBalanceSuggestion error:', e);
            this.showToast(e.message || 'Errore generazione proposta', 'error');
            document.getElementById('balanceApplyBtn').disabled = true;
        }
    }

    async applyBalanceSuggestion() {
        if (!this.ensureSingleTenantForManage()) return;
        const suggestions = this._lastBalanceSuggestion || [];
        if (!Array.isArray(suggestions) || suggestions.length === 0) {
            this.showToast('Nessuna proposta da applicare', 'warning');
            return;
        }

        if (!confirm(`Applicare la proposta? Verranno creati fino a ${suggestions.length} turni.`)) return;

        const tenantId = this.getSelectedTenantId();
        if (!tenantId || tenantId <= 0) {
            this.showToast("Seleziona un’azienda per gestire Tipi Turno/creare turni", 'warning');
            return;
        }
        const chunks = [];
        for (let i = 0; i < suggestions.length; i += 100) {
            chunks.push(suggestions.slice(i, i + 100));
        }

        let createdTotal = 0;
        let errorTotal = 0;
        let warningsTotal = [];

        try {
            for (let idx = 0; idx < chunks.length; idx++) {
                const chunk = chunks[idx].map(s => ({
                    shift_type_id: parseInt(s.shift_type_id),
                    user_id: parseInt(s.user_id),
                    shift_date: s.shift_date
                }));

                const res = await this.apiFetchWithFallback('manage', {}, {
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'bulk_create',
                        tenant_id: tenantId,
                        shifts: chunk
                    })
                });

                if (!res.success || !res.data) {
                    throw new Error(res.message || 'Errore applicazione proposta');
                }

                createdTotal += (res.data.created_count || 0);
                errorTotal += (res.data.error_count || 0);
                const w = Array.isArray(res?.data?.warnings) ? res.data.warnings : [];
                if (w.length) warningsTotal = warningsTotal.concat(w);
            }

            this.showToast(`Bilanciamento applicato: ${createdTotal} creati, ${errorTotal} errori`, errorTotal ? 'warning' : 'success');
            if (warningsTotal.length) {
                const msg = warningsTotal.length > 1 ? `${warningsTotal[0]} (+${warningsTotal.length - 1})` : warningsTotal[0];
                this.showToast(msg, 'warning');
            }
            this.closeModal('shiftBalanceModal');
            await this.loadShifts();
        } catch (e) {
            console.error('[ShiftsApp] applyBalanceSuggestion error:', e);
            this.showToast(e.message || 'Errore applicazione proposta', 'error');
        }
    }

    /**
     * Delete shift
     */
    async deleteShift() {
        if (!this.ensureSingleTenantForManage()) return;
        const id = document.getElementById('editingShiftId').value;
        if (!id || !confirm('Sei sicuro di voler eliminare questo turno?')) return;

        try {
            const tenantId = this.getSelectedTenantId();
            if (tenantId <= 0) {
                this.showToast("Seleziona un’azienda per gestire Tipi Turno/creare turni", 'warning');
                return;
            }
            const result = await this.apiFetchWithFallback('manage', {}, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'delete',
                    id: parseInt(id),
                    tenant_id: tenantId
                })
            });

            if (result.success) {
                this.showToast('Turno eliminato', 'success');
                this.closeModal('shiftModal');
                await this.loadShifts();
            } else {
                throw new Error(result.message || 'Errore eliminazione');
            }
        } catch (error) {
            console.error('[ShiftsApp] Error deleting shift:', error);
            this.showToast(error.message || 'Errore nella eliminazione', 'error');
        }
    }

    /**
     * Open shift detail modal
     */
    openShiftDetail(shiftId) {
        // Bulk selection mode: toggle selection instead of opening details
        if (this.bulkSelectionMode) {
            this.toggleShiftSelection(shiftId);
            return;
        }
        const shift = this.shifts.find(s => s.id === shiftId);
        if (!shift) return;

        const detailCard = document.getElementById('shiftDetailCard');
        const requestForm = document.getElementById('requestChangeForm');
        const requestBtn = document.getElementById('requestChangeBtn');
        const submitBtn = document.getElementById('submitRequestBtn');
        const editBtn = document.getElementById('editShiftFromDetailBtn');

        // Render detail
        detailCard.innerHTML = `
            <div class="detail-header" style="border-left: 4px solid ${shift.color};">
                <h3>${this.escapeHtml(shift.shift_name)}</h3>
                <span class="status-badge status-${shift.status}">${this.statusLabels[shift.status] || shift.status}</span>
            </div>
            <div class="detail-info">
                <div class="detail-row">
                    <span class="detail-label">Dipendente</span>
                    <span class="detail-value">${this.escapeHtml(shift.user_name)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Data</span>
                    <span class="detail-value">${this.formatDateDisplay(shift.shift_date)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Orario</span>
                    <span class="detail-value">${this.formatTime(shift.start_time)} - ${this.formatTime(shift.end_time)}</span>
                </div>
                ${shift.notes ? `
                <div class="detail-row">
                    <span class="detail-label">Note</span>
                    <span class="detail-value">${this.escapeHtml(shift.notes)}</span>
                </div>
                ` : ''}
            </div>
        `;

        // Store current shift ID for actions
        this.currentShiftId = shiftId;

        // Reset request form visibility
        if (requestForm) requestForm.style.display = 'none';
        if (requestBtn) requestBtn.style.display = (!this.canManageShifts && shift.user_id === this.currentUserId) ? 'inline-flex' : 'none';
        if (submitBtn) submitBtn.style.display = 'none';
        if (editBtn) {
            // Management actions require exactly one tenant selected
            editBtn.style.display = (this.canManageShifts && this.hasSingleTenantSelection()) ? 'inline-flex' : 'none';
        }

        this.openModal('shiftDetailModal');
    }

    toggleBulkSelectionMode() {
        if (!this.canManageShifts || !this.hasSingleTenantSelection()) {
            this.showToast("Seleziona un’azienda per gestire Tipi Turno/creare turni", 'warning');
            return;
        }
        this.bulkSelectionMode = !this.bulkSelectionMode;
        if (!this.bulkSelectionMode) {
            this.bulkSelectedShiftIds.clear();
        }
        this.updateBulkSelectionUi();
        this.renderCalendar(); // re-render to reflect selection state
        this.loadShifts(); // ensure shifts present after re-render
    }

    toggleShiftSelection(shiftId) {
        if (!this.bulkSelectionMode) return;
        const id = parseInt(shiftId, 10);
        if (!Number.isFinite(id) || id <= 0) return;
        if (this.bulkSelectedShiftIds.has(id)) {
            this.bulkSelectedShiftIds.delete(id);
        } else {
            this.bulkSelectedShiftIds.add(id);
        }
        this.updateBulkSelectionUi();
        // Update class on existing DOM nodes (both week/month view)
        document.querySelectorAll(`[data-shift-id="${id}"]`).forEach(el => {
            el.classList.toggle('shift-selected', this.bulkSelectedShiftIds.has(id));
        });
    }

    updateBulkSelectionUi() {
        const toggleBtn = document.getElementById('toggleSelectShiftsBtn');
        const delBtn = document.getElementById('bulkDeleteShiftsBtn');
        if (toggleBtn) {
            toggleBtn.classList.toggle('btn-primary', this.bulkSelectionMode);
            toggleBtn.classList.toggle('btn-outline', !this.bulkSelectionMode);
            toggleBtn.textContent = this.bulkSelectionMode ? 'Selezione attiva' : 'Seleziona';
        }
        if (delBtn) {
            const n = this.bulkSelectedShiftIds.size;
            delBtn.disabled = !this.bulkSelectionMode || n === 0;
            delBtn.textContent = `Elimina selezionati (${n})`;
        }

        // Add a lightweight helper style once
        if (!document.getElementById('cnx-shifts-bulk-style')) {
            const style = document.createElement('style');
            style.id = 'cnx-shifts-bulk-style';
            style.textContent = `
                .turni-shift.shift-selected {
                    outline: 2px solid var(--shift-color, #3B82F6);
                    box-shadow: 0 0 0 3px rgba(59,130,246,0.18);
                }
            `;
            document.head.appendChild(style);
        }
    }

    async bulkDeleteSelectedShifts() {
        if (!this.ensureSingleTenantForManage()) return;
        if (!this.bulkSelectionMode) {
            this.showToast('Attiva prima la modalità selezione', 'warning');
            return;
        }
        const ids = Array.from(this.bulkSelectedShiftIds);
        if (ids.length === 0) {
            this.showToast('Nessun turno selezionato', 'warning');
            return;
        }
        if (!confirm(`Eliminare definitivamente (soft-delete) ${ids.length} turni selezionati?`)) return;

        try {
            const tenantId = this.getSelectedTenantId();
            const result = await this.apiFetchWithFallback('manage', {}, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'bulk_delete',
                    tenant_id: tenantId,
                    ids
                })
            });

            if (!result.success || !result.data) {
                throw new Error(result.message || 'Errore eliminazione multipla');
            }

            const deleted = result.data.deleted_count || 0;
            const skipped = result.data.skipped_count || 0;
            this.showToast(`Eliminazione completata: ${deleted} eliminati, ${skipped} ignorati`, skipped ? 'warning' : 'success');

            // Reset selection
            this.bulkSelectedShiftIds.clear();
            this.bulkSelectionMode = false;
            this.updateBulkSelectionUi();

            await this.loadShifts();
            this.renderCalendar();
        } catch (e) {
            console.error('[ShiftsApp] bulkDeleteSelectedShifts error:', e);
            this.showToast(e.message || 'Errore eliminazione multipla', 'error');
        }
    }

    /**
     * Edit shift from detail modal
     */
    editShiftFromDetail() {
        if (!this.ensureSingleTenantForManage()) return;
        const shift = this.shifts.find(s => s.id === this.currentShiftId);
        if (!shift) return;

        this.closeModal('shiftDetailModal');

        // Open edit modal with data
        document.getElementById('editingShiftId').value = shift.id;
        document.getElementById('shiftEmployee').value = shift.user_id;
        document.getElementById('shiftType').value = shift.shift_type_id;
        document.getElementById('shiftDateStart').value = shift.shift_date;
        document.getElementById('shiftDateEnd').value = '';
        document.getElementById('shiftNotes').value = shift.notes || '';
        document.getElementById('shiftModalTitle').textContent = 'Modifica Turno';
        document.getElementById('deleteShiftBtn').style.display = 'inline-flex';
        document.getElementById('bulkOptions').style.display = 'none';

        // Turno libero: prefill overrides (if any)
        try {
            const startOv = shift.start_time_override ? this.formatTime(shift.start_time_override) : '';
            const endOv = shift.end_time_override ? this.formatTime(shift.end_time_override) : '';
            const chkMain = document.getElementById('shiftOverrideTimesEnabled');
            const chkStart = document.getElementById('shiftStartOverrideEnabled');
            const chkEnd = document.getElementById('shiftEndOverrideEnabled');
            const inStart = document.getElementById('shiftStartTimeOverride');
            const inEnd = document.getElementById('shiftEndTimeOverride');

            if (chkMain) chkMain.checked = !!(startOv || endOv);
            if (chkStart) chkStart.checked = !!startOv;
            if (chkEnd) chkEnd.checked = !!endOv;
            if (inStart) inStart.value = startOv || '';
            if (inEnd) inEnd.value = endOv || '';

            this.updateShiftOverrideTimesHints();
            this.updateShiftOverrideTimesUi();
        } catch (_) {}

        this.openModal('shiftModal');
    }

    /**
     * Show request change form
     */
    showRequestChangeForm() {
        const requestForm = document.getElementById('requestChangeForm');
        const requestBtn = document.getElementById('requestChangeBtn');
        const submitBtn = document.getElementById('submitRequestBtn');

        if (requestForm) requestForm.style.display = 'block';
        if (requestBtn) requestBtn.style.display = 'none';
        if (submitBtn) submitBtn.style.display = 'inline-flex';

        // Reset form
        document.getElementById('requestType').value = '';
        document.getElementById('requestReason').value = '';
        document.querySelector('.swap-target-group').style.display = 'none';
        document.querySelector('.new-shift-group').style.display = 'none';
    }

    /**
     * Handle request type change
     */
    handleRequestTypeChange(e) {
        const type = e.target.value;
        const swapGroup = document.querySelector('.swap-target-group');
        const newShiftGroup = document.querySelector('.new-shift-group');

        if (swapGroup) swapGroup.style.display = type === 'swap' ? 'block' : 'none';
        if (newShiftGroup) newShiftGroup.style.display = type === 'change' ? 'block' : 'none';
    }

    /**
     * Submit change request
     */
    async submitChangeRequest() {
        const requestType = document.getElementById('requestType').value;
        const reason = document.getElementById('requestReason').value.trim();

        if (!requestType || !reason) {
            this.showToast('Compila tutti i campi obbligatori', 'warning');
            return;
        }

        if (!this.currentShiftId || Number.isNaN(parseInt(this.currentShiftId, 10))) {
            this.showToast('Seleziona un turno valido prima di inviare la richiesta', 'warning');
            return;
        }

        const shift = this.shifts.find(s => s.id === this.currentShiftId);
        if (!shift || !shift.id) {
            this.showToast('Turno non valido o non trovato', 'warning');
            return;
        }

        try {
            const payload = {
                action: 'create',
                work_shift_id: shift.id,
                request_type: requestType,
                reason: reason
            };

            if (requestType === 'swap') {
                const targetUserId = document.getElementById('swapTargetUser').value;
                if (!targetUserId) {
                    this.showToast('Seleziona un collega per lo scambio', 'warning');
                    return;
                }
                payload.target_user_id = parseInt(targetUserId);
            } else if (requestType === 'change') {
                const newShiftType = document.getElementById('requestedShiftType').value;
                if (newShiftType) {
                    payload.new_shift_type_id = parseInt(newShiftType);
                }
            }

            const result = await this.apiFetchWithFallback('requests', {}, {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            if (result.success) {
                this.showToast('Richiesta inviata con successo', 'success');
                this.closeModal('shiftDetailModal');
            } else {
                throw new Error(result.message || 'Errore invio richiesta');
            }
        } catch (error) {
            console.error('[ShiftsApp] Error submitting request:', error);
            this.showToast(error.message || 'Errore nell\'invio della richiesta', 'error');
        }
    }

    /**
     * Open request detail modal
     */
    openRequestDetail(requestId, showReject = false) {
        const request = this.pendingRequests.find(r => r.id === requestId);
        if (!request) return;

        const detailCard = document.getElementById('requestDetailCard');
        const rejectionGroup = document.getElementById('rejectionReasonGroup');

        detailCard.innerHTML = `
            <div class="detail-header">
                <h3>${this.requestTypeLabels[request.request_type] || request.request_type}</h3>
                <span class="status-badge status-pending">In attesa</span>
            </div>
            <div class="detail-info">
                <div class="detail-row">
                    <span class="detail-label">Richiedente</span>
                    <span class="detail-value">${this.escapeHtml(request.user_name)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Data Turno</span>
                    <span class="detail-value">${this.formatDateDisplay(request.shift_date)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Turno Attuale</span>
                    <span class="detail-value">${this.escapeHtml(request.shift_name)} (${this.formatTime(request.start_time)} - ${this.formatTime(request.end_time)})</span>
                </div>
                ${request.target_user_name ? `
                <div class="detail-row">
                    <span class="detail-label">Scambio con</span>
                    <span class="detail-value">${this.escapeHtml(request.target_user_name)}</span>
                </div>
                ` : ''}
                ${request.requested_shift_name ? `
                <div class="detail-row">
                    <span class="detail-label">Turno Richiesto</span>
                    <span class="detail-value">${this.escapeHtml(request.requested_shift_name)}</span>
                </div>
                ` : ''}
                <div class="detail-row">
                    <span class="detail-label">Motivo</span>
                    <span class="detail-value">${this.escapeHtml(request.reason)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Richiesta il</span>
                    <span class="detail-value">${new Date(request.created_at).toLocaleString('it-IT')}</span>
                </div>
            </div>
        `;

        this.currentRequestId = requestId;

        if (rejectionGroup) {
            rejectionGroup.style.display = showReject ? 'block' : 'none';
            document.getElementById('rejectionReason').value = '';
        }

        this.openModal('requestDetailModal');
    }

    /**
     * Quick approve request
     */
    async quickApprove(requestId) {
        if (!confirm('Approvare questa richiesta?')) return;

        try {
            const result = await this.apiFetchWithFallback('requests', {}, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'approve',
                    id: requestId
                })
            });

            if (result.success) {
                this.showToast('Richiesta approvata', 'success');
                await this.loadPendingRequests();
                await this.loadShifts();
            } else {
                throw new Error(result.message || 'Errore approvazione');
            }
        } catch (error) {
            console.error('[ShiftsApp] Error approving request:', error);
            this.showToast(error.message || 'Errore nell\'approvazione', 'error');
        }
    }

    /**
     * Approve request from modal
     */
    async approveRequest() {
        if (!this.currentRequestId) return;
        await this.quickApprove(this.currentRequestId);
        this.closeModal('requestDetailModal');
    }

    /**
     * Handle reject request (show reason field or submit)
     */
    handleRejectRequest() {
        const rejectionGroup = document.getElementById('rejectionReasonGroup');

        if (rejectionGroup.style.display === 'none') {
            rejectionGroup.style.display = 'block';
        } else {
            this.rejectRequest();
        }
    }

    /**
     * Reject request
     */
    async rejectRequest() {
        const reason = document.getElementById('rejectionReason').value.trim();

        if (!reason) {
            this.showToast('Inserisci un motivo per il rifiuto', 'warning');
            return;
        }

        try {
            const result = await this.apiFetchWithFallback('requests', {}, {
                method: 'POST',
                body: JSON.stringify({
                    action: 'reject',
                    id: this.currentRequestId,
                    manager_notes: reason
                })
            });

            if (result.success) {
                this.showToast('Richiesta rifiutata', 'success');
                this.closeModal('requestDetailModal');
                await this.loadPendingRequests();
            } else {
                throw new Error(result.message || 'Errore rifiuto');
            }
        } catch (error) {
            console.error('[ShiftsApp] Error rejecting request:', error);
            this.showToast(error.message || 'Errore nel rifiuto', 'error');
        }
    }

    /**
     * Open modal
     */
    openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            // Hardening: in some environments global CSS variables/overrides can make modals invisible
            // even when the `active` class is present. Inline styles ensure visibility.
            modal.style.display = 'flex';
            modal.style.opacity = '1';
            modal.style.visibility = 'visible';
            modal.style.zIndex = '2147483647';
            document.body.style.overflow = 'hidden';
        }
    }

    /**
     * Close modal
     */
    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('active');
            // Clear hardening inline overrides
            modal.style.display = '';
            modal.style.opacity = '';
            modal.style.visibility = '';
            modal.style.zIndex = '';
            document.body.style.overflow = '';
        }
        // UX: ensure the next open starts in CREATE mode (avoid accidental overwrites).
        if (modalId === 'shiftTypesModal') {
            try { this.resetShiftTypeForm(); } catch (_) {}
        }
    }

    /**
     * Show loading state
     */
    showLoading() {
        this.container.innerHTML = `
            <div class="shifts-loading">
                <div class="shifts-spinner"></div>
                <span>Caricamento turni...</span>
            </div>
        `;
    }

    /**
     * Hide loading state
     */
    hideLoading() {
        // Loading is replaced by calendar render
    }

    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;

        const icons = {
            success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>',
            error: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>',
            warning: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
            info: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
        };

        toast.innerHTML = `
            <span class="toast-icon">${icons[type] || icons.info}</span>
            <span class="toast-message">${this.escapeHtml(message)}</span>
            <button class="toast-close" onclick="this.parentElement.remove()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        `;

        container.appendChild(toast);

        // Auto remove after 5 seconds
        setTimeout(() => {
            toast.classList.add('toast-fade-out');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

// Export for global access (turni.php expects window.ShiftsApp)
try { window.ShiftsApp = ShiftsApp; } catch (_) {}
