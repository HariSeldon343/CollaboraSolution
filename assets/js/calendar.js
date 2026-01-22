/**
 * Calendar Application Module
 * Complete calendar interface with month, week, and day views
 * Supports drag & drop, recurring events, and real-time updates
 */

/**
 * Local date key (YYYY-MM-DD) using local timezone.
 * IMPORTANT: Do NOT use toISOString() for date-only keys; it shifts by timezone.
 */
function cnxLocalDateKey(value) {
    if (!value) return '';
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Local datetime string (YYYY-MM-DDTHH:MM) using local timezone.
 * Use this format when sending datetimes to the API to avoid UTC shifts (no toISOString()).
 */
function cnxLocalDateTimeKey(value) {
    if (!value) return '';
    const d = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(d.getTime())) return '';
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const hours = String(d.getHours()).padStart(2, '0');
    const minutes = String(d.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

/**
 * Parse a calendar input value into a local Date (supports YYYY-MM-DD and YYYY-MM-DDTHH:MM).
 */
function cnxParseLocalInputDate(value) {
    if (!value) return null;
    if (value instanceof Date) return value;
    const s = String(value);
    const d = s.includes('T') ? new Date(s) : new Date(s + 'T00:00:00');
    return Number.isNaN(d.getTime()) ? null : d;
}

function cnxAddDaysLocalDateKey(dateKey, days) {
    if (!dateKey) return '';
    const d = new Date(String(dateKey) + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return '';
    d.setDate(d.getDate() + (parseInt(String(days), 10) || 0));
    return cnxLocalDateKey(d);
}

class CalendarApp {
    constructor(container, options = {}) {
        this.container = typeof container === 'string' ?
            document.getElementById(container) : container;

        const defaultApiBase = window.CN_API_BASE || '/CollaboraNexio/api/';

        this.config = {
            apiBase: defaultApiBase,
            locale: 'it-IT',
            firstDayOfWeek: 1, // Monday
            weekNumbers: true,
            defaultView: 'month',
            timeFormat: 24,
            slotDuration: 30, // minutes
            minTime: '00:00',
            maxTime: '24:00',
            dragEnabled: true,
            resizeEnabled: true,
            // Realtime Phase 1 (safe default): polling when tab is visible
            pollingEnabled: true,
            pollingIntervalMs: 30000,
            ...options
        };

        if (this.config.apiBase.slice(-1) !== '/') {
            this.config.apiBase += '/';
        }

        // BUG-104 FIX: Initialize CSRF token from hidden input (CLAUDE.md compliance)
        this.csrfToken = document.getElementById('csrfToken')?.value || '';

        // BUG-123 VERO: Initialize user role for calendar dropdown tenant name display
        this.userRole = document.getElementById('currentUserRole')?.value || 'user';

        this.state = {
            currentView: this.config.defaultView,
            currentDate: new Date(),
            selectedDate: null,
            events: [],
            calendars: [],
            selectedCalendars: new Set(),
            draggedEvent: null,
            selectedEvent: null,
            loading: false,
            filters: {},
            viewBounds: {},
            // SHIFT-INTEGRATION: Array to store user's work shifts
            shifts: []
        };

        this.components = {};
        this._loadingEvents = false;
        this._pollingTimer = null;
        this.init();
    }

    init() {
        this.renderLayout();
        this.setupComponents();
        this.bindEvents();
        this.loadInitialData();
        this.setupKeyboardShortcuts();

        // Setup WebSocket for real-time updates
        if (this.config.realtimeEnabled) {
            this.setupWebSocket();
        } else if (this.config.pollingEnabled) {
            this.setupPolling();
        }
    }

    renderLayout() {
        // BUG-114 FIX: Removed calendar-sidebar (redundant with main navigation)
        // Calendar now occupies full page-content width
        // BUG-119 FIX: Modal should NOT be in calendar-wrapper, must be appended to document.body
        this.container.innerHTML = `
            <div class="calendar-wrapper">
                <div class="calendar-header" id="calendar-toolbar"></div>
                <div class="calendar-body">
                    <div class="calendar-main" style="display: block !important; flex: 1; min-height: 600px; width: 100%;">
                        <div class="calendar-view-container" id="calendar-view"></div>
                    </div>
                </div>
            </div>
            <div id="context-menu" class="context-menu"></div>
            <div id="calendar-toast" class="toast-container"></div>
        `;

        // BUG-119 FIX: Create event-modal as separate element appended to document.body
        // This ensures position: fixed works correctly with high z-index overlay
        if (!document.getElementById('event-modal')) {
            const modalDiv = document.createElement('div');
            modalDiv.id = 'event-modal';
            modalDiv.className = 'event-modal';  // Use event-modal class, not generic 'modal'
            document.body.appendChild(modalDiv);
            console.log('[CalendarApp] EventModal appended to document.body for proper overlay positioning');
        }
    }

    setupComponents() {
        this.components.view = new CalendarView(this);
        this.components.eventManager = new EventManager(this);
        this.components.eventModal = new EventModal(this);
        this.components.dragDropHandler = new DragDropHandler(this);
        // BUG-114 FIX: Removed sidebar component initialization (redundant with main navigation)
        // this.components.sidebar = new CalendarSidebar(this);
        this.components.toolbar = new CalendarToolbar(this);
        this.components.contextMenu = new ContextMenu(this);

        this.components.toolbar.render();
        // BUG-114 FIX: Removed sidebar render call
        // this.components.sidebar.render();
        this.components.view.render();
    }

    bindEvents() {
        // View container events
        const viewContainer = document.getElementById('calendar-view');

        // Click events with delegation
        viewContainer.addEventListener('click', (e) => {
            const eventEl = e.target.closest('.calendar-event');
            const dayEl = e.target.closest('.calendar-day');
            const slotEl = e.target.closest('.calendar-hour, .calendar-time-slot');

            if (eventEl) {
                this.handleEventClick({ ...e, target: eventEl });
            } else if (dayEl) {
                this.handleDayClick({ ...e, target: dayEl });
            } else if (slotEl) {
                this.handleTimeSlotClick({ ...e, target: slotEl });
            }
        });

        // Double click for quick event creation
        viewContainer.addEventListener('dblclick', (e) => {
            const dayEl = e.target.closest('.calendar-day, .calendar-hour, .calendar-time-slot');
            if (dayEl) {
                this.quickCreateEvent({ ...e, target: dayEl });
            }
        });

        // Context menu
        viewContainer.addEventListener('contextmenu', (e) => {
            if (e.target.closest('.calendar-event')) {
                e.preventDefault();
                this.components.contextMenu.show(e);
            }
        });

        // Drag and drop events
        if (this.config.dragEnabled) {
            this.components.dragDropHandler.init();
        }

        // Window resize
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                this.components.view.adjustLayout();
            }, 250);
        });

        // Custom events
        this.container.addEventListener('calendar:viewChange', (e) => {
            this.changeView(e.detail.view);
        });

        this.container.addEventListener('calendar:navigate', (e) => {
            this.navigateDate(e.detail.direction);
        });

        this.container.addEventListener('calendar:eventUpdate', (e) => {
            this.updateEvent(e.detail.event);
        });
    }

    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Check if we're not in an input field
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }

            switch(e.key) {
                case 'ArrowLeft':
                    e.preventDefault();
                    this.navigateDate('prev');
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    this.navigateDate('next');
                    break;
                case 't':
                    if (!e.ctrlKey && !e.metaKey) {
                        e.preventDefault();
                        this.goToToday();
                    }
                    break;
                case 'n':
                    if (e.ctrlKey || e.metaKey) {
                        e.preventDefault();
                        this.components.eventModal.show();
                    }
                    break;
                case 'Escape':
                    this.components.eventModal.hide();
                    this.components.contextMenu.hide();
                    break;
                case 'Delete':
                    if (this.state.selectedEvent) {
                        this.deleteEvent(this.state.selectedEvent);
                    }
                    break;
                case '1':
                case '2':
                case '3':
                    if (e.altKey) {
                        e.preventDefault();
                        const views = ['month', 'week', 'day'];
                        this.changeView(views[parseInt(e.key) - 1]);
                    }
                    break;
            }
        });
    }

    async loadInitialData() {
        this.state.loading = true;

        try {
            // Load calendars
            await this.loadCalendars();

            // Load events for current view
            await this.loadEvents();

            // SHIFT-INTEGRATION: Load work shifts only when enabled
            if (this.state.filters && this.state.filters.showShifts) {
                await this.loadShifts();
            }

            // Render view
            this.components.view.render();
        } catch (error) {
            console.error('Error loading initial data:', error);
            this.showToast('Errore nel caricamento dei dati', 'error');
        } finally {
            this.state.loading = false;
        }
    }

    async loadCalendars() {
        try {
            const response = await this.apiCall('calendars.php');
            let calendars = response?.data?.calendars ?? response?.data ?? [];

            // Tenant-aware filtering (super_admin/admin): keep calendars for the selected Azienda
            const currentUserRole = document.getElementById('currentUserRole')?.value || 'user';
            const currentTenantIdsRaw = document.getElementById('currentTenantIds')?.value || '';
            let currentTenantIds = [];
            try {
                const parsed = JSON.parse(currentTenantIdsRaw || '[]');
                if (Array.isArray(parsed)) currentTenantIds = parsed.map(x => parseInt(String(x), 10)).filter(n => n > 0);
            } catch (_) {
                currentTenantIds = [];
            }

            const currentTenantIdRaw = document.getElementById('currentTenantId')?.value || '';
            const currentTenantId = parseInt(String(currentTenantIdRaw || '0'), 10) || 0;
            const isTenantScopedRole = ['admin', 'super_admin'].includes(String(currentUserRole));

            if (isTenantScopedRole && currentTenantIds.length > 0) {
                calendars = calendars.filter((c) => {
                    const tid = parseInt(String((c?.tenant?.id ?? c?.tenant_id ?? c?.tenantId ?? 0)), 10) || 0;
                    return currentTenantIds.includes(tid);
                });
            }

            this.state.calendars = calendars;

            // Select all calendars by default (but reset on tenant changes / filtering)
            this.state.selectedCalendars = new Set();
            this.state.calendars.forEach(cal => {
                if (cal && typeof cal.id !== 'undefined') {
                    this.state.selectedCalendars.add(cal.id);
                }
            });

            // REMOVED: updateCalendarList() method was removed during sidebar mini calendar optimization
            // this.components.sidebar.updateCalendarList();

            // Re-render toolbar now that calendars are available (needed for calendar filter UI)
            this.components.toolbar?.render();
        } catch (error) {
            console.error('Error loading calendars:', error);
        }
    }

    async loadEvents() {
        const bounds = this.getViewBounds();

        try {
            // Display filter: if events are disabled, clear and stop
            if (this.state.filters && this.state.filters.showEvents === false) {
                this.state.events = [];
                this.components.view.renderEvents();
                return;
            }

            if (this._loadingEvents) return;
            this._loadingEvents = true;

            const currentUserRole = document.getElementById('currentUserRole')?.value || 'user';
            const isSuperAdmin = String(currentUserRole) === 'super_admin';

            // If user unselects all calendars, show no events ONLY when calendars exist.
            // (Some tenants may return 0 calendars for super_admin due to visibility rules,
            // while events still exist; in that case we must not hide events.)
            if (!isSuperAdmin && Array.isArray(this.state.calendars) && this.state.calendars.length > 0 && this.state.selectedCalendars.size === 0) {
                this.state.events = [];
                this.components.view.renderEvents();
                return;
            }

            const currentTenantIdsRaw = document.getElementById('currentTenantIds')?.value || '';
            let tenantIds = [];
            try {
                const parsed = JSON.parse(currentTenantIdsRaw || '[]');
                if (Array.isArray(parsed)) tenantIds = parsed.map(x => parseInt(String(x), 10)).filter(n => n > 0);
            } catch (_) {
                tenantIds = [];
            }
            if (tenantIds.length === 0) {
                // fallback to scalar
                const tRaw = document.getElementById('currentTenantId')?.value || '';
                const t = parseInt(String(tRaw || '0'), 10) || 0;
                if (t > 0) tenantIds = [t];
            }

            const buildParams = () => {
                const p = new URLSearchParams({
                    start: bounds.start.toISOString(),
                    end: bounds.end.toISOString()
                });
                // Add other filters
                Object.entries(this.state.filters).forEach(([key, value]) => {
                    if (key === 'showEvents' || key === 'showShifts') return;
                    if (value) p.append(key, value);
                });
                return p;
            };

            const fetchForTenant = async (tenantId, { planningClientTenantId = null, asTenantId = null } = {}) => {
                const p = buildParams();
                p.set('tenant_id', String(tenantId));
                if (planningClientTenantId && Number.isFinite(planningClientTenantId) && planningClientTenantId > 0) {
                    p.set('planning_client_tenant_id', String(planningClientTenantId));
                }

                // Calendar filter: keep it for non-super_admin only when subset is selected
                if (!isSuperAdmin && Array.isArray(this.state.calendars) && this.state.calendars.length > 0) {
                    const allSelected = this.state.selectedCalendars.size >= this.state.calendars.length;
                    if (!allSelected && this.state.selectedCalendars.size > 0) {
                        this.state.selectedCalendars.forEach(id => {
                            p.append('calendar_ids[]', id);
                        });
                    }
                }

                const r = await this.apiCall(`events.php?${p}`);
                const payload = r?.data?.events ?? r?.data ?? [];
                if (!Array.isArray(payload)) return [];
                const forcedTenantId = (asTenantId && Number.isFinite(asTenantId) && asTenantId > 0) ? asTenantId : null;
                return payload.map(ev => {
                    const sourceTenantId = parseInt(String(ev?.tenant_id ?? tenantId ?? 0), 10) || 0;
                    return {
                        ...ev,
                        // Real tenant where the event is stored (used for PUT/DELETE calls)
                        source_tenant_id: sourceTenantId,
                        // Keep current UI behavior for cross-tenant planning events
                        tenant_id: forcedTenantId ?? ev?.tenant_id ?? tenantId
                    };
                });
            };

            let merged = [];
            if (tenantIds.length <= 1) {
                const mainTid = tenantIds[0];
                merged = await fetchForTenant(mainTid);

                // Planning cross-tenant view:
                // When filtering a single client company, also include vendor (tenant 28) events linked via metadata.planning.client_tenant_id
                // so that planned S.CO events are visible under the client filter (without duplicating events).
                const vendorTenantId = 28;
                const isPrivileged = String(currentUserRole) === 'super_admin' || String(currentUserRole) === 'admin';
                if (isPrivileged && mainTid && mainTid > 0 && mainTid !== vendorTenantId) {
                    const extra = await fetchForTenant(vendorTenantId, { planningClientTenantId: mainTid, asTenantId: mainTid }).catch(() => []);
                    merged = merged.concat(extra);
                }
            } else {
                // Concurrency limit to avoid too many parallel requests when selecting many tenants
                const limit = 5;
                const results = [];
                for (let i = 0; i < tenantIds.length; i += limit) {
                    const chunk = tenantIds.slice(i, i + limit);
                    // eslint-disable-next-line no-await-in-loop
                    const chunkRes = await Promise.all(chunk.map(tid => fetchForTenant(tid).catch(() => [])));
                    results.push(...chunkRes);
                }
                merged = results.flat();

                // Planning cross-tenant view for multi-company selection:
                // If vendor tenant 28 is NOT selected, add a best-effort pass to include vendor events per selected client.
                const vendorTenantId = 28;
                const isPrivileged = String(currentUserRole) === 'super_admin' || String(currentUserRole) === 'admin';
                if (isPrivileged && !tenantIds.includes(vendorTenantId)) {
                    const extraLimit = 5;
                    const extraAll = [];
                    for (let i = 0; i < tenantIds.length; i += extraLimit) {
                        const chunk = tenantIds.slice(i, i + extraLimit);
                        // eslint-disable-next-line no-await-in-loop
                        const chunkRes = await Promise.all(chunk.map(ctid => fetchForTenant(vendorTenantId, { planningClientTenantId: ctid, asTenantId: ctid }).catch(() => [])));
                        extraAll.push(...chunkRes);
                    }
                    merged = merged.concat(extraAll.flat());
                }
            }

            this.state.events = this.processEvents(merged);

            // Update view with new events
            this.components.view.renderEvents();
        } catch (error) {
            console.error('Error loading events:', error);
            this.showToast('Errore nel caricamento degli eventi', 'error');
        } finally {
            this._loadingEvents = false;
        }
    }

    /**
     * SHIFT-INTEGRATION: Load current user's work shifts for the visible date range
     * Shifts are displayed as informational badges in the calendar (not interactive)
     */
    async loadShifts() {
        // Only load shifts when user explicitly enables the filter (avoid clutter)
        if (!this.state.filters || !this.state.filters.showShifts) {
            this.state.shifts = [];
            return;
        }

        // Get current user ID from hidden input
        const currentUserId = document.getElementById('currentUserId')?.value;
        if (!currentUserId) {
            console.log('[CalendarApp] No currentUserId found, skipping shift load');
            return;
        }

        const currentUserRole = document.getElementById('currentUserRole')?.value || 'user';
        const currentTenantIdsRaw = document.getElementById('currentTenantIds')?.value || '';
        let tenantIds = [];
        try {
            const parsed = JSON.parse(currentTenantIdsRaw || '[]');
            if (Array.isArray(parsed)) tenantIds = parsed.map(x => parseInt(String(x), 10)).filter(n => n > 0);
        } catch (_) {
            tenantIds = [];
        }
        if (tenantIds.length === 0) {
            const currentTenantId = document.getElementById('currentTenantId')?.value;
            const t = parseInt(String(currentTenantId || '0'), 10) || 0;
            if (t > 0) tenantIds = [t];
        }

        const bounds = this.getViewBounds();
        if (!bounds || !bounds.start || !bounds.end) {
            console.warn('[CalendarApp] No view bounds available for shift loading');
            return;
        }

        // Format dates as YYYY-MM-DD for API (LOCAL, not UTC)
        const startDate = cnxLocalDateKey(bounds.start);
        const endDate = cnxLocalDateKey(bounds.end);

        // Managers/Admins: show tenant-wide shifts; Users: only own shifts
        const isManagerScope = ['admin', 'manager', 'super_admin'].includes(String(currentUserRole));
        const buildShiftParams = (tenantId) => {
            const params = new URLSearchParams({
                start_date: startDate,
                end_date: endDate,
                tenant_id: String(tenantId)
            });
            if (!isManagerScope) {
                params.set('user_id', String(currentUserId));
            }
            return params;
        };

        try {
            let merged = [];
            if (tenantIds.length <= 1) {
                const url = `shifts/list.php?${buildShiftParams(tenantIds[0]).toString()}`;
                const response = await this.apiCall(url);
                const shiftsPayload = (response && response.success && response.data) ? (response.data.shifts || []) : [];
                merged = Array.isArray(shiftsPayload) ? shiftsPayload : [];
            } else {
                const limit = 5;
                const results = [];
                for (let i = 0; i < tenantIds.length; i += limit) {
                    const chunk = tenantIds.slice(i, i + limit);
                    // eslint-disable-next-line no-await-in-loop
                    const chunkRes = await Promise.all(chunk.map(async (tid) => {
                        const url = `shifts/list.php?${buildShiftParams(tid).toString()}`;
                        try {
                            const r = await this.apiCall(url);
                            return (r && r.success && r.data) ? (r.data.shifts || []) : [];
                        } catch (_) {
                            return [];
                        }
                    }));
                    results.push(...chunkRes);
                }
                merged = results.flat();
            }

            this.state.shifts = this.processShifts(merged);
            console.log(`[CalendarApp] Loaded ${this.state.shifts.length} shifts (${isManagerScope ? 'tenant' : 'user'} scope)`);
        } catch (err) {
            // SHIFT-INTEGRATION: Non-blocking - shifts are optional, don't show error toast
            console.error('[CalendarApp] Error loading shifts:', err);
            this.state.shifts = [];
        }
    }

    /**
     * SHIFT-INTEGRATION: Process raw shift data into calendar-friendly format
     * @param {Array} shifts - Raw shifts from API
     * @returns {Array} Processed shifts with Date objects
     */
    processShifts(shifts) {
        if (!Array.isArray(shifts)) return [];

        return shifts.map(shift => {
            const extractSurname = (fullName) => {
                if (!fullName || typeof fullName !== 'string') return '';
                const parts = fullName.trim().split(/\s+/).filter(Boolean);
                return parts.length ? parts[parts.length - 1] : '';
            };

            // Normalize datetime strings
            const normalizeDateTime = (value) => {
                if (!value) return null;
                if (typeof value !== 'string') return value;
                if (value.includes(' ') && !value.includes('T')) return value.replace(' ', 'T');
                return value;
            };

            const startStr = normalizeDateTime(shift.start_datetime || shift.start);
            const endStr = normalizeDateTime(shift.end_datetime || shift.end);

            return {
                ...shift,
                start: startStr ? new Date(startStr) : null,
                end: endStr ? new Date(endStr) : null,
                // SHIFT-INTEGRATION: Mark as shift for rendering distinction
                isShift: true,
                shiftTypeName: shift.shift_name || shift.title || 'Turno',
                shiftColor: shift.color || shift.backgroundColor || '#6B7280',
                // UI helpers
                shiftTypeIcon: shift.icon || shift.shift_icon || shift.shiftIcon || '',
                shiftUserSurname: extractSurname(shift.user_name || shift.userName || '')
            };
        });
    }

    setupPolling() {
        // Avoid double-start
        if (this._pollingTimer) return;

        const tick = () => {
            // Only poll when visible to reduce server load
            if (document.visibilityState !== 'visible') return;
            // Don't poll while modal is open to avoid UX jumps
            const modal = document.getElementById('event-modal');
            if (modal && modal.classList.contains('active')) return;
            if (!this.state.filters || this.state.filters.showEvents !== false) {
                this.loadEvents();
            }
            // SHIFT-INTEGRATION: Also reload shifts on poll (only when enabled)
            if (this.state.filters && this.state.filters.showShifts) {
                this.loadShifts();
            }
        };

        this._pollingTimer = setInterval(tick, this.config.pollingIntervalMs);

        // Also refresh once when tab becomes visible again
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') tick();
        });
    }

    processEvents(events) {
        const list = Array.isArray(events) ? events : [];

        return list.map(event => {
            const normalizeDateTime = (value) => {
                if (!value) return null;
                if (typeof value !== 'string') return value;
                // Normalize "YYYY-MM-DD HH:MM:SS" -> "YYYY-MM-DDTHH:MM:SS" (safer parsing)
                if (value.includes(' ') && !value.includes('T')) return value.replace(' ', 'T');
                return value;
            };

            const startDateStr = normalizeDateTime(event.start_date || event.start || event.start_datetime || event.start_datetime_local);
            const endDateStr = normalizeDateTime(event.end_date || event.end || event.end_datetime || event.end_datetime_local);

            const calendarId = event.calendar_id ?? event.calendar?.id ?? null;

            // Derive participant_count for UI badges/icons when API doesn't provide it
            let participantCount = event.participant_count ?? event.participants_count ?? null;
            if (participantCount === null || participantCount === undefined) {
                if (Array.isArray(event.participants)) {
                    participantCount = event.participants.length;
                } else {
                    participantCount = 0;
                }
            }

            return {
                ...event,
                calendar_id: calendarId,
                start_date: startDateStr,
                end_date: endDateStr,
                start: startDateStr ? new Date(startDateStr) : null,
                end: endDateStr ? new Date(endDateStr) : null,
                allDay: event.all_day === 1 || event.all_day === true,
                participant_count: participantCount,
                color: event.color || this.getCalendarColor(calendarId)
            };
        });
    }

    getCalendarColor(calendarId) {
        const calendar = this.state.calendars.find(c => c.id === calendarId);
        return calendar?.color || '#3788d8';
    }

    getViewBounds() {
        const { currentDate, currentView } = this.state;
        const start = new Date(currentDate);
        const end = new Date(currentDate);

        switch (currentView) {
            case 'month':
                start.setDate(1);
                start.setDate(start.getDate() - start.getDay() + this.config.firstDayOfWeek);
                end.setDate(1);
                end.setMonth(end.getMonth() + 1);
                end.setDate(end.getDate() + (6 - end.getDay() + this.config.firstDayOfWeek));
                break;

            case 'week':
                start.setDate(start.getDate() - start.getDay() + this.config.firstDayOfWeek);
                end.setDate(start.getDate() + 7);
                break;

            case 'day':
                end.setDate(start.getDate() + 1);
                break;
        }

        start.setHours(0, 0, 0, 0);
        end.setHours(23, 59, 59, 999);

        this.state.viewBounds = { start, end };
        return this.state.viewBounds;
    }

    changeView(viewType) {
        if (this.state.currentView === viewType) return;

        this.state.currentView = viewType;
        if (!this.state.filters || this.state.filters.showEvents !== false) {
            this.loadEvents();
        }
        // SHIFT-INTEGRATION: Reload shifts when view changes (only when enabled)
        if (this.state.filters && this.state.filters.showShifts) {
            this.loadShifts();
        }
        this.components.view.render();
        this.components.toolbar.updateViewButtons();
    }

    navigateDate(direction) {
        const { currentDate, currentView } = this.state;

        switch (direction) {
            case 'prev':
                if (currentView === 'month') {
                    currentDate.setMonth(currentDate.getMonth() - 1);
                } else if (currentView === 'week') {
                    currentDate.setDate(currentDate.getDate() - 7);
                } else if (currentView === 'day') {
                    currentDate.setDate(currentDate.getDate() - 1);
                }
                break;

            case 'next':
                if (currentView === 'month') {
                    currentDate.setMonth(currentDate.getMonth() + 1);
                } else if (currentView === 'week') {
                    currentDate.setDate(currentDate.getDate() + 7);
                } else if (currentView === 'day') {
                    currentDate.setDate(currentDate.getDate() + 1);
                }
                break;

            case 'today':
                this.state.currentDate = new Date();
                break;
        }

        if (!this.state.filters || this.state.filters.showEvents !== false) {
            this.loadEvents();
        }
        // SHIFT-INTEGRATION: Reload shifts when navigating dates (only when enabled)
        if (this.state.filters && this.state.filters.showShifts) {
            this.loadShifts();
        }
        this.components.view.render();
        this.components.toolbar.updateDateDisplay();
    }

    goToToday() {
        this.navigateDate('today');
    }

    handleDayClick(e) {
        const dateStr = e.target.dataset.date;
        if (!dateStr) return;

        this.state.selectedDate = new Date(dateStr);

        // Remove previous selection
        document.querySelectorAll('.calendar-day.selected').forEach(el => {
            el.classList.remove('selected');
        });

        // Add selection to clicked day
        e.target.classList.add('selected');

        // Trigger day selected event
        this.container.dispatchEvent(new CustomEvent('calendar:daySelected', {
            detail: { date: this.state.selectedDate }
        }));
    }

    handleEventClick(e) {
        if (typeof e.stopPropagation === 'function') {
            e.stopPropagation();
        }
        const eventEl = e.target.closest('[data-event-id]');
        const rawId = eventEl?.dataset?.eventId;
        if (!rawId) return;

        const events = this.state.events || [];
        let event = events.find(ev => String(ev.id) === String(rawId));

        // Fallback: if instance id like "11_20251219" not found, try parent id
        if (!event && rawId.includes('_')) {
            const parentId = rawId.split('_')[0];
            event = events.find(ev => String(ev.id) === String(parentId));
        }

        if (event) {
            this.state.selectedEvent = event;
            this.components.eventModal.show(event);
        } else {
            console.warn('[Calendar] Evento non trovato per id:', rawId);
        }
    }

    handleTimeSlotClick(e) {
        const time = e.target.dataset.time;
        const date = e.target.dataset.date ||
            cnxLocalDateKey(this.state.currentDate);

        const startDate = new Date(`${date}T${time}`);
        this.quickCreateEvent({ startDate });
    }

    quickCreateEvent(options = {}) {
        const { startDate } = options;

        const newEvent = {
            title: '',
            start_date: startDate || this.state.selectedDate || new Date(),
            end_date: new Date((startDate || this.state.selectedDate || new Date()).getTime() + 3600000),
            all_day: false,
            calendar_id: this.state.calendars[0]?.id
        };

        this.components.eventModal.show(newEvent);
    }

    async createEvent(eventData) {
        try {
            const response = await this.apiCall('events.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(eventData)
            });

            if (response.success) {
                this.showToast('Evento creato con successo', 'success');
                await this.loadEvents();
                return response.data;
            }
        } catch (error) {
            console.error('Error creating event:', error);
            this.showToast('Errore nella creazione dell\'evento', 'error');
            throw error;
        }
    }

    async updateEvent(eventData) {
        try {
            // Normalize payload for API (PUT expects start/end/is_all_day/recurrence)
            const payload = { ...eventData };
            if (payload.start_date && !payload.start) payload.start = payload.start_date;
            if (payload.end_date && !payload.end) payload.end = payload.end_date;
            if (payload.all_day !== undefined && payload.is_all_day === undefined) payload.is_all_day = payload.all_day;
            if (payload.recurrence_rule !== undefined && payload.recurrence === undefined) payload.recurrence = payload.recurrence_rule;

            const apiTenantId = parseInt(String(eventData?.source_tenant_id ?? eventData?.tenant_id ?? ''), 10) || 0;
            const tenantQuery = apiTenantId > 0 ? `&tenant_id=${encodeURIComponent(String(apiTenantId))}` : '';
            const response = await this.apiCall(`events.php?id=${eventData.id}${tenantQuery}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });

            if (response.success) {
                this.showToast('Evento aggiornato', 'success');
                await this.loadEvents();
                return response.data;
            }
        } catch (error) {
            console.error('Error updating event:', error);
            this.showToast('Errore nell\'aggiornamento dell\'evento', 'error');
            throw error;
        }
    }

    async deleteEvent(event) {
        if (!confirm('Sei sicuro di voler eliminare questo evento?')) {
            return;
        }

        const toDateOnly = (value) => {
            if (!value) return null;
            if (value instanceof Date) return cnxLocalDateKey(value);
            const parsed = new Date(value);
            if (Number.isNaN(parsed.getTime())) return null;
            return cnxLocalDateKey(parsed);
        };

        const isInstance = event?.is_recurring_instance || (typeof event?.id === 'string' && event.id.includes('_'));
        const parentId = isInstance ? (event.parent_event_id || parseInt(String(event.id).split('_')[0], 10)) : event?.id;
        if (!parentId) {
            this.showToast('ID evento non valido', 'error');
            return;
        }

        const instanceDate = isInstance ? toDateOnly(event.start || event.start_date || event.start_datetime) : null;
        const instanceQuery = instanceDate ? `&instance_date=${encodeURIComponent(instanceDate)}` : '';
        const apiTenantId = parseInt(String(event?.source_tenant_id ?? event?.tenant_id ?? ''), 10) || 0;
        const tenantQuery = apiTenantId > 0 ? `&tenant_id=${encodeURIComponent(String(apiTenantId))}` : '';

        try {
            const response = await this.apiCall(`events.php?id=${parentId}${instanceQuery}${tenantQuery}`, {
                method: 'DELETE'
            });

            if (response.success) {
                this.showToast('Evento eliminato', 'success');
                await this.loadEvents();
            }
        } catch (error) {
            console.error('Error deleting event:', error);
            this.showToast('Errore nell\'eliminazione dell\'evento', 'error');
        }
    }

    async duplicateEvent(event) {
        const newEvent = {
            ...event,
            id: undefined,
            title: `${event.title} (Copia)`,
            created_at: undefined,
            updated_at: undefined
        };

        await this.createEvent(newEvent);
    }

    setupWebSocket() {
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const wsUrl = `${protocol}//${window.location.host}/ws/calendar`;

        this.ws = new WebSocket(wsUrl);

        this.ws.onopen = () => {
            console.log('WebSocket connected');
        };

        this.ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            this.handleRealtimeUpdate(data);
        };

        this.ws.onerror = (error) => {
            console.error('WebSocket error:', error);
        };

        this.ws.onclose = () => {
            console.log('WebSocket disconnected');
            // Reconnect after 5 seconds
            setTimeout(() => this.setupWebSocket(), 5000);
        };
    }

    handleRealtimeUpdate(data) {
        switch (data.type) {
            case 'event_created':
            case 'event_updated':
            case 'event_deleted':
                this.loadEvents();
                break;

            case 'reminder':
                this.showReminder(data.event);
                break;
        }
    }

    showReminder(event) {
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(event.title, {
                body: `Inizia alle ${this.formatTime(new Date(event.start_date))}`,
                icon: '/assets/images/calendar-icon.png',
                tag: `event-${event.id}`
            });
        }

        this.showToast(`Promemoria: ${event.title}`, 'info');
    }

    formatTime(date) {
        return date.toLocaleTimeString(this.config.locale, {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    formatDate(date) {
        return date.toLocaleDateString(this.config.locale, {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    // BUG-104 FIX: Add getCsrfToken() method (CLAUDE.md pattern)
    getCsrfToken() {
        return this.csrfToken;
    }

    async apiCall(endpoint, options = {}) {
        try {
            // BUG-104 FIX: Include CSRF token in ALL requests (CLAUDE.md compliance)
            const headers = {
                'X-CSRF-Token': this.getCsrfToken(),
                ...(options.headers || {})
            };

            const response = await fetch(this.config.apiBase + endpoint, {
                credentials: 'same-origin',
                ...options,
                headers
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            this.showToast('Errore di comunicazione con il server', 'error');
            throw error;
        }
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;

        const container = document.getElementById('calendar-toast');
        container.appendChild(toast);

        // Animate in
        setTimeout(() => toast.classList.add('show'), 10);

        // Remove after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}

class CalendarView {
    constructor(app) {
        this.app = app;
        this.container = document.getElementById('calendar-view');
        this.timeIndicatorInterval = null;
    }

    render() {
        const { currentView } = this.app.state;

        switch (currentView) {
            case 'week':
                this.stopTimeIndicatorUpdate();
                this.renderWeek();
                break;
            case 'day':
                this.renderDay();
                break;
            case 'month':
            default:
                this.stopTimeIndicatorUpdate();
                this.renderMonth();
                break;
        }
    }

    renderMonth() {
        const { currentDate, events } = this.app.state;
        const bounds = this.app.getViewBounds();

        // PREMIUM CALENDAR REDESIGN: Simplified month view without week numbers in grid
        let html = '<div class="calendar-grid-container">';

        // Weekday headers (7 columns)
        html += '<div class="calendar-weekdays">';
        const dayNames = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
        for (let i = 0; i < 7; i++) {
            html += `<div class="calendar-weekday">${dayNames[i]}</div>`;
        }
        html += '</div>';

        // Calendar grid (strict 7 columns)
        html += '<div class="calendar-grid">';

        const currentDay = new Date(bounds.start);

        while (currentDay <= bounds.end) {
            const isToday = this.isToday(currentDay);
            const isCurrentMonth = currentDay.getMonth() === currentDate.getMonth();
            const dateStr = cnxLocalDateKey(currentDay);

            html += `
                <div class="calendar-day ${isToday ? 'today' : ''} ${!isCurrentMonth ? 'other-month' : ''}"
                     data-date="${dateStr}"
                     draggable="false">
                    <div class="day-number">${currentDay.getDate()}</div>
                    <div class="calendar-events" data-date="${dateStr}"></div>
                </div>
            `;

            currentDay.setDate(currentDay.getDate() + 1);
        }

        html += '</div></div>';

        this.container.innerHTML = html;

        // BUG-115 FIX: Add double-click handlers for event creation
        document.querySelectorAll('.calendar-day').forEach(dayCell => {
            dayCell.addEventListener('dblclick', (e) => {
                const date = e.currentTarget.dataset.date;
                this.openEventModal(date);
            });
        });

        this.renderEvents();
    }

    /**
     * Open event creation modal for specific date
     * BUG-115: Double-click creates event
     * @param {string} dateStr - Date in ISO format (YYYY-MM-DD)
     */
    openEventModal(dateStr) {
        const eventModal = this.app.components.eventModal;

        if (!eventModal || typeof eventModal.show !== 'function') {
            console.error('[CalendarView] EventModal component not found');
            alert('Impossibile aprire il modal. Ricaricare la pagina.');
            return;
        }

        // Create Date objects for start and end times (9:00 AM - 10:00 AM)
        const startDate = new Date(dateStr + 'T09:00:00');
        const endDate = new Date(dateStr + 'T10:00:00');

        // Create event data with pre-filled date
        const eventData = {
            title: '',
            description: '',
            start_date: startDate,
            end_date: endDate,
            all_day: false,
            location: '',
            calendar_id: this.app.state.calendars && this.app.state.calendars.length > 0
                ? this.app.state.calendars[0].id
                : null,
            participants: [],
            recurrence_rule: '',
            reminders: [],
            category: '',
            tags: [],
            color: '#3788d8'
        };

        // Show modal (null = new event mode)
        eventModal.show(eventData);
        console.log('[CalendarView] Opened event modal for date:', dateStr);
    }

    renderWeek() {
        const weekStart = this.getWeekStart(this.app.state.currentDate);
        const days = [];

        // Build 7 days array (Sunday to Saturday)
        for (let i = 0; i < 7; i++) {
            const day = new Date(weekStart);
            day.setDate(weekStart.getDate() + i);
            days.push(day);
        }

        const slotDuration = Math.max(5, Math.min(60, this.app.config.slotDuration || 30));
        const slotsPerHour = Math.max(1, Math.round(60 / slotDuration));
        const slotHeight = 60 / slotsPerHour;

        // 1. Header with Day Names
        let headerHtml = '';
        days.forEach((day) => {
            const dayName = day.toLocaleDateString(this.app.config.locale, { weekday: 'short' });
            const dayNumber = day.getDate();
            const isToday = this.isToday(day);

            headerHtml += `
                <div class="calendar-week-day-header ${isToday ? 'today' : ''}">
                    <div class="day-name">${dayName}</div>
                    <div class="day-number">${dayNumber}</div>
                </div>
            `;
        });

        // 2. All-Day Row
        let allDayHtml = '';
        days.forEach((day) => {
            const dateStr = cnxLocalDateKey(day);
            allDayHtml += `<div class="calendar-week-allday-cell" data-date="${dateStr}"></div>`;
        });

        // 3. Time Column
        let timeColumn = '';
        for (let hour = 0; hour <= 23; hour++) {
            timeColumn += `
                <div class="calendar-hour-label">
                    <span>${hour.toString().padStart(2, '0')}:00</span>
                </div>
            `;
        }
        timeColumn += `
            <div class="calendar-hour-label end-label">
                <span>24:00</span>
            </div>
        `;

        // 4. Main Grid (7 columns)
        let gridHtml = '';
        days.forEach((day) => {
            const dateStr = cnxLocalDateKey(day);
            const isToday = this.isToday(day);
            
            // Current time indicator for today's column
            const currentTimeIndicator = isToday
                ? (() => {
                    const now = new Date();
                    const minutes = now.getHours() * 60 + now.getMinutes();
                    return `<div class="current-time-indicator" style="top: ${minutes}px"></div>`;
                })()
                : '';

            // Grid lines for this day column
            let dayGrid = '';
            for (let hour = 0; hour < 24; hour++) {
                dayGrid += '<div class="calendar-grid-hour">';
                for (let i = 0; i < slotsPerHour; i++) {
                    const minute = i * slotDuration;
                    const time = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
                    dayGrid += `
                        <div class="calendar-time-slot" 
                             data-date="${dateStr}" 
                             data-time="${time}"
                             style="height: ${slotHeight}px"></div>
                    `;
                }
                dayGrid += '</div>';
            }

            gridHtml += `
                <div class="calendar-week-day-column" data-date="${dateStr}">
                    ${currentTimeIndicator}
                    ${dayGrid}
                </div>
            `;
        });

        const html = `
            <div class="calendar-week-view">
                <div class="calendar-week-header">
                    <div class="calendar-time-column-header"></div>
                    <div class="calendar-week-days-header">
                        ${headerHtml}
                    </div>
                </div>
                <div class="calendar-week-body">
                    <div class="calendar-week-allday">
                        <div class="calendar-time-column-allday">Tutto il giorno</div>
                        <div class="calendar-week-allday-content">
                            ${allDayHtml}
                        </div>
                    </div>
                    <div class="calendar-week-timeline">
                        <div class="calendar-time-column">
                            ${timeColumn}
                        </div>
                        <div class="calendar-week-grid">
                            ${gridHtml}
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.container.innerHTML = html;

        // Scroll to 8:00 AM or current time
        setTimeout(() => {
            const timeline = this.container.querySelector('.calendar-week-timeline');
            if (timeline) {
                const now = new Date();
                // If current week contains today, use current hour, otherwise 8:00
                const isCurrentWeek = days.some(d => this.isToday(d));
                const targetHour = isCurrentWeek ? now.getHours() : 8;
                
                timeline.scrollTop = Math.max((targetHour * 60) - 120, 0);
            }
        }, 0);

        if (days.some(d => this.isToday(d))) {
            this.startTimeIndicatorUpdate();
        } else {
            this.stopTimeIndicatorUpdate();
        }

        // Render events in week grid
        // BUG-112 FIX: Pass events from state with defensive check
        const events = this.app.state.events || [];
        this.renderWeekEvents(events);
    }

    /**
     * Get start of week (Sunday) for given date
     * @param {Date} date - Reference date
     * @returns {Date} Sunday of the week containing the given date
     */
    getWeekStart(date) {
        const d = new Date(date);
        const day = d.getDay(); // 0 = Sunday, 6 = Saturday
        const diff = day; // Days to go back to Sunday
        d.setDate(d.getDate() - diff);
        d.setHours(0, 0, 0, 0); // Start of day
        return d;
    }

    renderDay() {
        const { currentDate } = this.app.state;
        const dateStr = cnxLocalDateKey(currentDate);
        const dateFormatted = currentDate.toLocaleDateString(this.app.config.locale, {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
        const weekday = currentDate.toLocaleDateString(this.app.config.locale, { weekday: 'long' });

        const slotDuration = Math.max(5, Math.min(60, this.app.config.slotDuration || 30));
        const slotsPerHour = Math.max(1, Math.round(60 / slotDuration));
        const slotHeight = 60 / slotsPerHour;

        let timeColumn = '';
        for (let hour = 0; hour <= 23; hour++) {
            timeColumn += `
                <div class="calendar-hour-label">
                    <span>${hour.toString().padStart(2, '0')}:00</span>
                </div>
            `;
        }
        timeColumn += `
            <div class="calendar-hour-label end-label">
                <span>24:00</span>
            </div>
        `;

        let gridHtml = '<div class="calendar-grid">';
        for (let hour = 0; hour < 24; hour++) {
            gridHtml += '<div class="calendar-grid-hour">';
            for (let i = 0; i < slotsPerHour; i++) {
                const minute = i * slotDuration;
                const time = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
                gridHtml += `
                    <div class="calendar-time-slot"
                         data-date="${dateStr}"
                         data-time="${time}"
                         style="height: ${slotHeight}px"></div>
                `;
            }
            gridHtml += '</div>';
        }
        gridHtml += '</div>';

        const currentTimeIndicator = this.isToday(currentDate)
            ? (() => {
                const now = new Date();
                const minutes = now.getHours() * 60 + now.getMinutes();
                return `<div class="current-time-indicator" style="top: ${minutes}px"></div>`;
            })()
            : '';

        const html = `
            <div class="calendar-day-view">
                <div class="calendar-day-header">
                    <h2>${dateFormatted}</h2>
                    <div class="weekday">${weekday}</div>
                </div>
                <div class="calendar-day-body">
                    <div class="calendar-day-allday">
                        <div class="calendar-allday-label">Tutto il giorno</div>
                        <div class="calendar-allday-events" data-date="${dateStr}"></div>
                    </div>
                    <div class="calendar-day-timeline">
                        <div class="calendar-time-column">
                            ${timeColumn}
                        </div>
                        <div class="calendar-day-column" data-date="${dateStr}">
                            ${currentTimeIndicator}
                            ${gridHtml}
                        </div>
                    </div>
                </div>
            </div>
        `;

        this.container.innerHTML = html;
        this.renderEvents();

        setTimeout(() => {
            const timeline = this.container.querySelector('.calendar-day-timeline');
            if (timeline) {
                const targetHour = this.isToday(currentDate) ? new Date().getHours() : 8;
                timeline.scrollTop = Math.max((targetHour * 60) - 120, 0);
            }
        }, 0);

        if (this.isToday(currentDate)) {
            this.startTimeIndicatorUpdate();
        } else {
            this.stopTimeIndicatorUpdate();
        }
    }

    renderEvents() {
        const { events, currentView, shifts } = this.app.state;

        if (currentView === 'month') {
            this.renderMonthEvents(events);
            // SHIFT-INTEGRATION: Render shifts after events in month view
            this.renderMonthShifts(shifts || []);
        } else if (currentView === 'week') {
            this.renderWeekEvents(events);
            // SHIFT-INTEGRATION: Render shifts after events in week view
            this.renderWeekShifts(shifts || []);
        } else if (currentView === 'day') {
            this.renderDayViewEvents(events);
            // SHIFT-INTEGRATION: Render shifts after events in day view
            this.renderDayShifts(shifts || []);
        }
    }

    getEventDisplayEnd(event) {
        if (!event || !(event.start instanceof Date) || Number.isNaN(event.start.getTime())) return null;
        if (!(event.end instanceof Date) || Number.isNaN(event.end.getTime())) return event.start;
        if (event.end <= event.start) return event.start;

        // End is treated as exclusive for day-span calculations (displayEnd = end - 1ms)
        const display = new Date(event.end.getTime() - 1);
        if (Number.isNaN(display.getTime()) || display < event.start) return event.start;
        return display;
    }

    getEventDaySpanKeys(event) {
        if (!event || !(event.start instanceof Date) || Number.isNaN(event.start.getTime())) return null;
        const startKey = cnxLocalDateKey(event.start);
        const displayEnd = this.getEventDisplayEnd(event) || event.start;
        const endKey = cnxLocalDateKey(displayEnd);
        return { startKey, endKey };
    }

    clampDayKeyRange(startKey, endKey, rangeStartKey, rangeEndKey) {
        if (!startKey || !endKey) return null;
        let fromKey = startKey;
        let toKey = endKey;
        if (rangeStartKey && fromKey < rangeStartKey) fromKey = rangeStartKey;
        if (rangeEndKey && toKey > rangeEndKey) toKey = rangeEndKey;
        if (fromKey > toKey) return null;
        return { fromKey, toKey };
    }

    iterateDayKeys(fromKey, toKey, limit = 370) {
        const out = [];
        if (!fromKey || !toKey) return out;
        const start = new Date(String(fromKey) + 'T00:00:00');
        const end = new Date(String(toKey) + 'T00:00:00');
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return out;

        const cur = new Date(start.getTime());
        let n = 0;
        while (cur <= end && n < limit) {
            out.push(cnxLocalDateKey(cur));
            cur.setDate(cur.getDate() + 1);
            n += 1;
        }
        return out;
    }

    getEventDayKeysInRange(event, rangeStartKey, rangeEndKey) {
        const span = this.getEventDaySpanKeys(event);
        if (!span) return [];
        const clamped = this.clampDayKeyRange(span.startKey, span.endKey, rangeStartKey, rangeEndKey);
        if (!clamped) return [];
        return this.iterateDayKeys(clamped.fromKey, clamped.toKey, 370);
    }

    splitTimedEventByDay(event, rangeStartKey, rangeEndKey) {
        if (!event || event.allDay) return [];
        if (!(event.start instanceof Date) || Number.isNaN(event.start.getTime())) return [];
        if (!(event.end instanceof Date) || Number.isNaN(event.end.getTime())) return [];

        const span = this.getEventDaySpanKeys(event);
        if (!span) return [];
        const clamped = this.clampDayKeyRange(span.startKey, span.endKey, rangeStartKey, rangeEndKey);
        if (!clamped) return [];

        const keys = this.iterateDayKeys(clamped.fromKey, clamped.toKey, 370);
        const segments = [];

        keys.forEach((dayKey) => {
            const dayStart = new Date(String(dayKey) + 'T00:00:00');
            if (Number.isNaN(dayStart.getTime())) return;
            const dayEndExclusive = new Date(dayStart.getTime());
            dayEndExclusive.setDate(dayEndExclusive.getDate() + 1);
            const dayEndDisplay = new Date(dayEndExclusive.getTime() - 1); // 23:59:59.999

            const segStart = (event.start < dayStart) ? dayStart : event.start;
            const segEnd = (event.end >= dayEndExclusive) ? dayEndDisplay : event.end;
            if (!(segStart instanceof Date) || !(segEnd instanceof Date)) return;
            if (Number.isNaN(segStart.getTime()) || Number.isNaN(segEnd.getTime())) return;
            if (segEnd <= segStart) return;

            segments.push({
                ...event,
                start: new Date(segStart.getTime()),
                end: new Date(segEnd.getTime())
            });
        });

        return segments;
    }

    renderMonthEvents(events) {
        // PREMIUM CALENDAR REDESIGN: Clear existing events before rendering to avoid duplicates
        document.querySelectorAll('.calendar-events').forEach(container => {
            container.innerHTML = '';
        });

        const bounds = this.app.getViewBounds();
        const viewStartKey = cnxLocalDateKey(bounds.start);
        const viewEndKey = cnxLocalDateKey(bounds.end);

        (Array.isArray(events) ? events : []).forEach(event => {
            if (!event || !(event.start instanceof Date) || Number.isNaN(event.start.getTime())) return;
            const startKey = cnxLocalDateKey(event.start);
            const dayKeys = this.getEventDayKeysInRange(event, viewStartKey, viewEndKey);

            dayKeys.forEach((dayKey) => {
                const container = document.querySelector(`.calendar-events[data-date="${dayKey}"]`);
                if (!container) return;

                // For continuation days, set a neutral start time (00:00) for display.
                const evForDay = (dayKey === startKey)
                    ? event
                    : { ...event, start: new Date(String(dayKey) + 'T00:00:00') };

                const eventEl = this.createEventElement(evForDay, 'month');
                container.appendChild(eventEl);
            });
        });
    }

    /**
     * SHIFT-INTEGRATION: Render shifts in month view
     * Shifts appear as non-interactive badges below events
     * @param {Array} shifts - Processed shifts array
     */
    renderMonthShifts(shifts) {
        if (!Array.isArray(shifts) || shifts.length === 0) return;

        // Clear existing shift elements to avoid duplicates
        document.querySelectorAll('.calendar-shift').forEach(el => el.remove());

        shifts.forEach(shift => {
            if (!shift.start) return;

            const dateStr = cnxLocalDateKey(shift.start);
            const container = document.querySelector(`.calendar-events[data-date="${dateStr}"]`);

            if (container) {
                const shiftEl = this.createShiftElement(shift, 'month');
                container.appendChild(shiftEl);
            }
        });
    }

    renderWeekEvents(events) {
        // BUG-112 FIX: Defensive check for undefined/null events
        if (!Array.isArray(events)) {
            console.warn('[CalendarView] renderWeekEvents called with non-array:', events);
            events = [];
        }

        if (events.length === 0) {
            console.log('[CalendarView] No events to render in week view');
            return;
        }

        const allDayEvents = events.filter(e => e.allDay);
        const timedEvents = events.filter(e => !e.allDay);

        // Visible week range (Sunday -> Saturday, per getWeekStart())
        const weekStart = this.getWeekStart(this.app.state.currentDate);
        const weekEnd = new Date(weekStart.getTime());
        weekEnd.setDate(weekEnd.getDate() + 6);
        const viewStartKey = cnxLocalDateKey(weekStart);
        const viewEndKey = cnxLocalDateKey(weekEnd);

        // Render all-day events
        allDayEvents.forEach(event => {
            if (!event || !(event.start instanceof Date) || Number.isNaN(event.start.getTime())) return;
            const startKey = cnxLocalDateKey(event.start);
            const dayKeys = this.getEventDayKeysInRange(event, viewStartKey, viewEndKey);
            dayKeys.forEach((dayKey) => {
                const container = document.querySelector(`.calendar-week-allday-cell[data-date="${dayKey}"]`);
                if (!container) return;
                const evForDay = (dayKey === startKey)
                    ? event
                    : { ...event, start: new Date(String(dayKey) + 'T00:00:00') };
                const eventEl = this.createEventElement(evForDay, 'allday');
                container.appendChild(eventEl);
            });
        });

        // Render timed events with overlap handling
        const timedSegments = [];
        timedEvents.forEach(ev => {
            timedSegments.push(...this.splitTimedEventByDay(ev, viewStartKey, viewEndKey));
        });
        this.renderTimedEvents(timedSegments, 'week');
    }

    /**
     * SHIFT-INTEGRATION: Render shifts in week view
     * Shifts appear in the all-day section as informational badges
     * @param {Array} shifts - Processed shifts array
     */
    renderWeekShifts(shifts) {
        if (!Array.isArray(shifts) || shifts.length === 0) return;

        // Clear existing shift elements in week all-day cells
        document.querySelectorAll('.calendar-week-allday-cell .calendar-shift').forEach(el => el.remove());

        shifts.forEach(shift => {
            if (!shift.start) return;

            const dateStr = cnxLocalDateKey(shift.start);
            const container = document.querySelector(`.calendar-week-allday-cell[data-date="${dateStr}"]`);

            if (container) {
                const shiftEl = this.createShiftElement(shift, 'week');
                container.appendChild(shiftEl);
            }
        });
    }

    renderDayViewEvents(events) {
        const allDayEvents = events.filter(e => e.allDay);
        const timedEvents = events.filter(e => !e.allDay);
        const dayKey = cnxLocalDateKey(this.app.state.currentDate);

        // Render all-day events
        const allDayContainer = document.querySelector('.calendar-allday-events');
        if (allDayContainer) {
            allDayContainer.innerHTML = '';
        }
        allDayEvents.forEach(event => {
            // Only show events that overlap the current day (defensive)
            if (!event || !(event.start instanceof Date) || Number.isNaN(event.start.getTime())) return;
            const keys = this.getEventDayKeysInRange(event, dayKey, dayKey);
            if (!keys.length) return;
            const eventEl = this.createEventElement(event, 'allday');
            allDayContainer?.appendChild(eventEl);
        });

        // Render timed events
        const timedSegments = [];
        timedEvents.forEach(ev => {
            timedSegments.push(...this.splitTimedEventByDay(ev, dayKey, dayKey));
        });
        this.renderTimedEvents(timedSegments, 'day');
    }

    /**
     * SHIFT-INTEGRATION: Render shifts in day view
     * Shifts appear in the all-day section as informational badges
     * @param {Array} shifts - Processed shifts array
     */
    renderDayShifts(shifts) {
        if (!Array.isArray(shifts) || shifts.length === 0) return;

        // Clear existing shift elements in day all-day section
        document.querySelectorAll('.calendar-allday-events .calendar-shift').forEach(el => el.remove());

        const { currentDate } = this.app.state;
        const currentDateStr = cnxLocalDateKey(currentDate);

        shifts.forEach(shift => {
            if (!shift.start) return;

            const shiftDateStr = cnxLocalDateKey(shift.start);

            // Only render shifts for the current day
            if (shiftDateStr !== currentDateStr) return;

            const container = document.querySelector('.calendar-allday-events');

            if (container) {
                const shiftEl = this.createShiftElement(shift, 'day');
                container.appendChild(shiftEl);
            }
        });
    }

    renderTimedEvents(events, viewType) {
        // Clean up existing timeline events for the specific view
        if (viewType === 'day') {
            document.querySelectorAll('.calendar-day-column .calendar-event-timeline').forEach(el => el.remove());
        } else if (viewType === 'week') {
            document.querySelectorAll('.calendar-week-day-column .calendar-event-timeline').forEach(el => el.remove());
        }

        // Group overlapping events
        const eventGroups = this.groupOverlappingEvents(events);

        eventGroups.forEach(group => {
            const columns = this.layoutEventColumns(group);

            columns.forEach((column, colIndex) => {
                column.forEach(event => {
                    const eventEl = this.createTimelineEvent(event, colIndex, columns.length);
                    this.positionTimelineEvent(eventEl, event, viewType);
                });
            });
        });
    }

    createEventElement(event, viewType) {
        const div = document.createElement('div');
        div.className = `calendar-event calendar-event-${viewType}`;
        div.dataset.eventId = event.id;

        // PREMIUM CALENDAR REDESIGN: Use custom color or default gradient
        if (event.color && event.color !== '#3788d8') {
            div.style.background = event.color;
        }
        // Default gradient is applied via CSS class

        // Add priority indicator
        if (event.priority === 'high') {
            div.classList.add('high-priority');
        }

        // Event content with premium layout
        const time = event.allDay ? '' : this.app.formatTime(event.start);
        let content = '';

        if (time) {
            content += `<span class="event-time">${time}</span>`;
        }

        content += `<span class="event-title">${event.title}</span>`;

        // Add icons for special event types
        if (event.recurrence_rule) {
            content += '<span class="event-icon" title="Ricorrente">🔁</span>';
        }

        if (event.participant_count > 1) {
            content += `<span class="event-icon" title="${event.participant_count} partecipanti">👥</span>`;
        }

        div.innerHTML = content;

        // Make draggable if enabled
        if (this.app.config.dragEnabled) {
            div.draggable = true;
        }

        return div;
    }

    /**
     * SHIFT-INTEGRATION: Create a shift element for calendar display
     * Shifts are visually distinct from events (dashed border, muted colors, non-interactive)
     * @param {Object} shift - Processed shift data
     * @param {string} viewType - 'month', 'week', or 'day'
     * @returns {HTMLElement} The shift element
     */
    createShiftElement(shift, viewType) {
        const div = document.createElement('div');
        div.className = `calendar-shift calendar-shift-${viewType}`;
        // SHIFT-INTEGRATION: No data-event-id to prevent click handling
        div.dataset.shiftId = shift.id;

        // Apply shift color with transparency
        const shiftColor = shift.shiftColor || shift.color || '#6B7280';
        div.style.setProperty('--shift-color', shiftColor);
        div.style.backgroundColor = this.hexToRgba(shiftColor, 0.15);
        div.style.borderColor = shiftColor;

        // Format time range
        const formatTime = (date) => {
            if (!date) return '';
            return date.toLocaleTimeString(this.app.config.locale, {
                hour: '2-digit',
                minute: '2-digit'
            });
        };

        const startTimeStr = formatTime(shift.start);
        const endTimeStr = formatTime(shift.end);
        const timeRange = startTimeStr && endTimeStr ? `${startTimeStr}-${endTimeStr}` : '';

        // Build content (safe textContent, avoid HTML injection)
        const iconSpan = document.createElement('span');
        iconSpan.className = 'shift-icon';
        const icon = this.resolveShiftIcon(shift.shiftTypeIcon);
        iconSpan.textContent = icon;
        iconSpan.title = 'Tipo turno';

        const nameSpan = document.createElement('span');
        nameSpan.className = 'shift-name';
        const surname = (shift.shiftUserSurname && String(shift.shiftUserSurname).trim() !== '') ? String(shift.shiftUserSurname) : '';
        const typeName = shift.shiftTypeName || 'Turno';
        nameSpan.textContent = surname ? `${surname} · ${typeName}` : typeName;

        div.appendChild(iconSpan);
        div.appendChild(nameSpan);

        if (timeRange) {
            const timeSpan = document.createElement('span');
            timeSpan.className = 'shift-time';
            timeSpan.textContent = timeRange;
            div.appendChild(timeSpan);
        }

        // SHIFT-INTEGRATION: Shifts are NOT draggable (informational only)
        div.draggable = false;

        return div;
    }

    /**
     * SHIFT-INTEGRATION: Normalize shift icon.
     * Accepts emoji, or keyword like "sun" and maps to an emoji.
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
     * SHIFT-INTEGRATION: Convert hex color to rgba
     * @param {string} hex - Hex color code
     * @param {number} alpha - Alpha value (0-1)
     * @returns {string} RGBA color string
     */
    hexToRgba(hex, alpha) {
        // Remove # if present
        hex = hex.replace('#', '');

        // Handle shorthand hex (e.g., #FFF)
        if (hex.length === 3) {
            hex = hex.split('').map(char => char + char).join('');
        }

        const r = parseInt(hex.substring(0, 2), 16);
        const g = parseInt(hex.substring(2, 4), 16);
        const b = parseInt(hex.substring(4, 6), 16);

        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    createTimelineEvent(event, columnIndex, totalColumns) {
        const div = this.createEventElement(event, 'timeline');

        // Calculate width and position for overlapping events
        const width = (100 / totalColumns) - 1;
        const left = columnIndex * (100 / totalColumns);

        div.style.width = `${width}%`;
        div.style.left = `${left}%`;

        return div;
    }

    positionTimelineEvent(element, event, viewType) {
        const startMinutes = event.start.getHours() * 60 + event.start.getMinutes();
        const endMinutes = event.end.getHours() * 60 + event.end.getMinutes();
        const duration = endMinutes - startMinutes;

        // 60px per hour (1px per minute) fixed height
        const top = startMinutes;
        const height = Math.max(duration, 30); // Minimum 30 mins height for visibility

        element.style.top = `${top}px`;
        element.style.height = `${height}px`;

        // Find the correct column
        const dateStr = cnxLocalDateKey(event.start);
        let column = null;

        if (viewType === 'day') {
            column = document.querySelector(`.calendar-day-column[data-date="${dateStr}"]`);
        } else if (viewType === 'week') {
            column = document.querySelector(`.calendar-week-day-column[data-date="${dateStr}"]`);
        }

        if (!column) {
            column = document.querySelector(`.calendar-hour-slot[data-date="${dateStr}"]`) ||
                document.querySelector('.calendar-hour-slot');
        }

        if (column) {
            column.appendChild(element);
        }
    }

    groupOverlappingEvents(events) {
        const groups = [];
        const sortedEvents = [...events].sort((a, b) => a.start - b.start);

        sortedEvents.forEach(event => {
            let added = false;

            for (const group of groups) {
                if (this.eventsOverlap(event, group[group.length - 1])) {
                    group.push(event);
                    added = true;
                    break;
                }
            }

            if (!added) {
                groups.push([event]);
            }
        });

        return groups;
    }

    layoutEventColumns(events) {
        const columns = [];

        events.forEach(event => {
            let placed = false;

            for (const column of columns) {
                if (!column.some(e => this.eventsOverlap(event, e))) {
                    column.push(event);
                    placed = true;
                    break;
                }
            }

            if (!placed) {
                columns.push([event]);
            }
        });

        return columns;
    }

    eventsOverlap(event1, event2) {
        return event1.start < event2.end && event1.end > event2.start;
    }

    renderDayEvents() {
        // Month events are rendered dynamically after the layout is mounted.
        // We intentionally return an empty string here to let renderMonthEvents()
        // append the interactive elements and avoid duplicated markup.
        return '';
    }

    renderAllDayEvents(date, events) {
        const dayEvents = events.filter(event => {
            const eventDate = cnxLocalDateKey(event.start);
            const checkDate = cnxLocalDateKey(date);
            return eventDate === checkDate && event.allDay;
        });

        return dayEvents.map(event => `
            <div class="calendar-event calendar-event-allday"
                 style="background-color: ${event.color}"
                 data-event-id="${event.id}">
                ${event.title}
            </div>
        `).join('');
    }

    renderAgendaList(date, events) {
        const dayEvents = events.filter(event => {
            const eventDate = cnxLocalDateKey(event.start);
            const checkDate = cnxLocalDateKey(date);
            return eventDate === checkDate;
        }).sort((a, b) => a.start - b.start);

        if (dayEvents.length === 0) {
            return '<div class="agenda-empty">Nessun evento programmato</div>';
        }

        return dayEvents.map(event => `
            <div class="agenda-item" data-event-id="${event.id}">
                <div class="agenda-time">
                    ${event.allDay ? 'Tutto il giorno' :
                      `${this.app.formatTime(event.start)} - ${this.app.formatTime(event.end)}`}
                </div>
                <div class="agenda-content">
                    <div class="agenda-title" style="border-left: 3px solid ${event.color}">
                        ${event.title}
                    </div>
                    ${event.location ? `<div class="agenda-location">📍 ${event.location}</div>` : ''}
                    ${event.description ? `<div class="agenda-description">${event.description}</div>` : ''}
                </div>
            </div>
        `).join('');
    }

    renderCurrentTimeIndicator() {
        const now = new Date();
        const minutes = now.getHours() * 60 + now.getMinutes();
        const top = minutes / this.app.config.slotDuration * 30;

        return `<div class="current-time-indicator" style="top: ${top}px"></div>`;
    }

    startTimeIndicatorUpdate() {
        this.stopTimeIndicatorUpdate();

        // Update every minute
        this.timeIndicatorInterval = setInterval(() => {
            const indicator = document.querySelector('.current-time-indicator');
            if (indicator) {
                const now = new Date();
                const minutes = now.getHours() * 60 + now.getMinutes();
                // 1px per minute
                const top = minutes;
                indicator.style.top = `${top}px`;
            }
        }, 60000);
    }

    stopTimeIndicatorUpdate() {
        if (this.timeIndicatorInterval) {
            clearInterval(this.timeIndicatorInterval);
            this.timeIndicatorInterval = null;
        }
    }

    isToday(date) {
        const today = new Date();
        return date.getDate() === today.getDate() &&
               date.getMonth() === today.getMonth() &&
               date.getFullYear() === today.getFullYear();
    }

    isCurrentWeek() {
        const bounds = this.app.getViewBounds();
        const today = new Date();
        return today >= bounds.start && today <= bounds.end;
    }

    getWeekNumber(date) {
        const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
        const dayNum = d.getUTCDay() || 7;
        d.setUTCDate(d.getUTCDate() + 4 - dayNum);
        const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
        return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
    }

    getDayNames(format = 'long') {
        const days = [];
        const baseDate = new Date(2024, 0, 7); // A Sunday

        for (let i = 0; i < 7; i++) {
            const date = new Date(baseDate);
            date.setDate(date.getDate() + i);
            days.push(date.toLocaleDateString(this.app.config.locale, { weekday: format }));
        }

        return days;
    }

    getDayName(dayIndex, format = 'long') {
        const date = new Date(2024, 0, 7 + dayIndex); // Sunday + dayIndex
        return date.toLocaleDateString(this.app.config.locale, { weekday: format });
    }

    adjustLayout() {
        // Responsive adjustments
        const width = this.container.clientWidth;

        if (width < 768) {
            // Mobile view adjustments
            this.container.classList.add('mobile-view');
        } else {
            this.container.classList.remove('mobile-view');
        }
    }
}

class EventManager {
    constructor(app) {
        this.app = app;
    }

    async createEvent(eventData) {
        return await this.app.createEvent(eventData);
    }

    async updateEvent(eventData) {
        return await this.app.updateEvent(eventData);
    }

    async deleteEvent(event) {
        return await this.app.deleteEvent(event);
    }

    async duplicateEvent(event) {
        return await this.app.duplicateEvent(event);
    }

    async checkConflicts(eventData) {
        try {
            const params = new URLSearchParams({
                start: eventData.start_date,
                end: eventData.end_date,
                exclude_id: eventData.id || ''
            });

            const response = await this.app.apiCall(`events.php?action=conflicts&${params}`);
            return response.data || [];
        } catch (error) {
            console.error('Error checking conflicts:', error);
            return [];
        }
    }

    async respondToInvitation(eventId, response) {
        try {
            const result = await this.app.apiCall(`events.php?action=respond&id=${eventId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ response })
            });

            if (result.success) {
                this.app.showToast('Risposta inviata', 'success');
                await this.app.loadEvents();
            }
        } catch (error) {
            console.error('Error responding to invitation:', error);
            this.app.showToast('Errore nell\'invio della risposta', 'error');
        }
    }
}

class EventModal {
    constructor(app) {
        this.app = app;
        this.modal = document.getElementById('event-modal');
        this.event = null;
        this.isNew = true;
        this.recurrenceBuilder = new RecurrenceBuilder(this);
        this.init();
    }

    init() {
        // BUG-116 FIX: Defensive null check (pattern BUG-103)
        // BUG-119 FIX: Modal is now appended to document.body by renderLayout()
        if (!this.modal) {
            console.error('[EventModal] Modal element not found in init()');
            return;
        }

        this.modal.addEventListener('click', (e) => {
            if (e.target === this.modal) {
                this.hide();
            }
        });
    }

    async show(event = null) {
        // BUG-116 FIX: Defensive check before render (pattern BUG-103)
        if (!this.modal) {
            console.error('[EventModal] Modal element not found in show()');
            return;
        }

        this.event = event || {
            title: '',
            description: '',
            start_date: new Date(),
            end_date: new Date(Date.now() + 3600000),
            all_day: false,
            location: '',
            calendar_id: this.app.state.calendars[0]?.id,
            participants: [],
            recurrence_rule: '',
            reminders: [],
            category: '',
            tags: [],
            color: '#3788d8'
        };

        this.isNew = !event || !event.id;
        // BUG-147a FIX: Await render to ensure participants are loaded before showing modal
        await this.render();
        // BUG-117 FIX: Use 'active' class to match CSS
        this.modal.classList.add('active');

        // Focus on title field
        setTimeout(() => {
            const titleInput = document.getElementById('event-title');
            if (titleInput) titleInput.focus();
        }, 100);
    }

    hide() {
        // BUG-116 FIX: Defensive null check (pattern BUG-103)
        if (!this.modal) {
            console.warn('[EventModal] Modal element not found in hide()');
            return;
        }

        // BUG-117 FIX: Use 'active' class to match CSS
        this.modal.classList.remove('active');
        this.event = null;
    }

    async render() {
        // BUG-116 FIX: Defensive check before rendering (pattern BUG-103)
        if (!this.modal) {
            console.error('[EventModal] Cannot render - modal element not found');
            return;
        }

        const { calendars } = this.app.state;
        const isAllDay = !!(this.event && this.event.all_day);
        const startVal = isAllDay
            ? this.formatDateOnlyForAllDay(this.event.start_date)
            : this.formatDateTimeLocal(this.event.start_date);
        const endVal = isAllDay
            ? this.formatDateOnlyForAllDay(this.getAllDayDisplayEndDate(this.event.end_date, this.event.start_date))
            : this.formatDateTimeLocal(this.event.end_date);

        // BUG-117 FIX: Use event-modal-* classes to match CSS definitions
        this.modal.innerHTML = `
            <div class="event-modal-content">
                <div class="event-modal-header">
                    <h2>${this.isNew ? 'Nuovo Evento' : 'Modifica Evento'}</h2>
                    <button class="modal-close" onclick="window.calendar.components.eventModal.hide()">×</button>
                </div>

                <div class="event-modal-body">
                    <form id="event-form" class="event-form">
                        <!-- Title -->
                        <div class="form-group">
                            <input type="text"
                                   id="event-title"
                                   class="form-control form-control-large"
                                   placeholder="Titolo evento"
                                   value="${this.event.title || ''}"
                                   required>
                        </div>

                        <!-- Date/Time -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>Data e ora inizio</label>
                                <input type="${isAllDay ? 'date' : 'datetime-local'}"
                                       id="event-start"
                                       class="form-control"
                                       value="${startVal}"
                                       required>
                            </div>
                            <div class="form-group">
                                <label>Data e ora fine</label>
                                <input type="${isAllDay ? 'date' : 'datetime-local'}"
                                       id="event-end"
                                       class="form-control"
                                       value="${endVal}"
                                       required>
                            </div>
                        </div>

                        <!-- All day -->
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox"
                                       id="event-allday"
                                       ${this.event.all_day ? 'checked' : ''}>
                                <span>Evento tutto il giorno</span>
                            </label>
                        </div>

                        <!-- Location -->
                        <div class="form-group">
                            <label>Luogo</label>
                            <div class="input-group">
                                <input type="text"
                                       id="event-location"
                                       class="form-control"
                                       placeholder="Aggiungi luogo"
                                       value="${this.event.location || ''}">
                                <button type="button" class="btn btn-icon" onclick="window.calendar.components.eventModal.openMap()">
                                    📍
                                </button>
                            </div>
                        </div>

                        <!-- Calendar selection -->
                        <div class="form-group">
                            <label>Calendario</label>
                            <select id="event-calendar" class="form-control">
                                ${calendars.map(cal => {
                                    let displayName;

                                    // BUG-126 FIX: Display logic based on calendar type
                                    if (cal.visibility === 'private') {
                                        // Personal calendar: show calendar name (includes owner name)
                                        displayName = cal.name;
                                    } else {
                                        // Public/Shared calendar: show TENANT NAME directly
                                        // BUG-126A HOTFIX: API returns tenant.name not tenant_name
                                        displayName = cal.tenant?.name || cal.name;
                                    }

                                    return `
                                    <option value="${cal.id}"
                                            ${cal.id === this.event.calendar_id ? 'selected' : ''}>
                                        ${displayName}
                                    </option>
                                    `;
                                }).join('')}
                            </select>
                        </div>

                        <!-- Description -->
                        <div class="form-group">
                            <label>Descrizione</label>
                            <textarea id="event-description"
                                      class="form-control"
                                      rows="3"
                                      placeholder="Aggiungi descrizione">${this.event.description || ''}</textarea>
                        </div>

                        <!-- Participants Section - Complete UI -->
                        <div class="form-group" id="event-participants-container">
                            <label>Partecipanti</label>
                            <div id="participants-selector">
                                <button type="button" class="btn btn-outline btn-sm" onclick="window.calendar.components.eventModal.showParticipantModal()">
                                    + Aggiungi Partecipanti
                                </button>
                                <div id="selected-participants" class="selected-participants-list">
                                    <!-- BUG-147a FIX: Show loading indicator for existing events -->
                                    ${!this.isNew && this.event.id ? '<p class="text-muted" style="margin-top: 8px; font-size: 13px;">Caricamento partecipanti...</p>' : '<p class="text-muted" style="margin-top: 8px; font-size: 13px;">Nessun partecipante selezionato</p>'}
                                </div>
                            </div>
                        </div>

                        <!-- Recurrence -->
                        <div class="form-group">
                            <label>Ricorrenza</label>
                            <div class="recurrence-container">
                                <select id="event-recurrence" class="form-control">
                                    <option value="">Non si ripete</option>
                                    <option value="DAILY">Ogni giorno</option>
                                    <option value="WEEKLY">Ogni settimana</option>
                                    <option value="MONTHLY">Ogni mese</option>
                                    <option value="YEARLY">Ogni anno</option>
                                    <option value="CUSTOM">Personalizzata...</option>
                                </select>
                                <div id="recurrence-details" class="recurrence-details">
                                    ${this.recurrenceBuilder.render()}
                                </div>
                            </div>
                        </div>

                        <!-- Reminders -->
                        <div class="form-group">
                            <label>Promemoria</label>
                            <div id="reminders-list" class="reminders-list">
                                ${this.renderReminders()}
                            </div>
                            <button type="button" class="btn btn-sm" onclick="window.calendar.components.eventModal.addReminder()">
                                + Aggiungi promemoria
                            </button>
                        </div>

                        <!-- Category and Tags -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>Categoria</label>
                                <select id="event-category" class="form-control">
                                    <option value="">Seleziona categoria</option>
                                    <option value="meeting">Riunione</option>
                                    <option value="task">Attività</option>
                                    <option value="reminder">Promemoria</option>
                                    <option value="event">Evento</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Tag</label>
                                <input type="text"
                                       id="event-tags"
                                       class="form-control"
                                       placeholder="Aggiungi tag (separati da virgola)"
                                       value="${(this.event.tags || []).join(', ')}">
                            </div>
                        </div>

                        <!-- Color -->
                        <div class="form-group">
                            <label>Colore</label>
                            <div class="color-picker">
                                ${this.renderColorPicker()}
                            </div>
                        </div>

                        <!-- Attachments -->
                        <div class="form-group">
                            <label>Allegati</label>
                            <div class="attachments-area">
                                <input type="file" id="event-attachments" multiple class="hidden">
                                <button type="button" class="btn btn-outline" onclick="document.getElementById('event-attachments').click()">
                                    📎 Aggiungi allegato
                                </button>
                                <div id="attachments-list" class="attachments-list"></div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="event-modal-footer">
                    <button type="button" class="btn btn-text" onclick="window.calendar.components.eventModal.hide()">
                        Annulla
                    </button>
                    ${!this.isNew ? `
                        <button type="button" class="btn btn-danger" onclick="window.calendar.components.eventModal.delete()">
                            Elimina
                        </button>
                    ` : ''}
                    <button type="button" class="btn btn-outline" onclick="window.calendar.components.eventModal.saveAsDraft()">
                        Salva come bozza
                    </button>
                    <button type="button" class="btn btn-primary" onclick="window.calendar.components.eventModal.save()">
                        ${this.isNew ? 'Crea' : 'Salva'}
                    </button>
                </div>
            </div>
        `;

        this.bindFormEvents();

        // Initialize empty participants array for new events
        if (!this.selectedParticipants) {
            this.selectedParticipants = [];
        }

        // Load and render participants for existing events
        // BUG-147a FIX: Await participant loading to prevent race condition
        if (!this.isNew && this.event.id) {
            await this.loadExistingParticipants(this.event.id);
        }
    }

    async loadEventParticipants(event) {
        const container = document.getElementById('event-participants-container');
        if (!container) return;

        try {
            const participantsHtml = await this.renderEventParticipants(event);
            container.innerHTML = participantsHtml;
        } catch (error) {
            console.error('[Calendar] Error loading participants:', error);
            container.innerHTML = '<label>Partecipanti</label><p class="text-muted">Errore nel caricamento dei partecipanti</p>';
        }
    }

    bindFormEvents() {
        // BUG-116 FIX: Defensive null checks BEFORE addEventListener (pattern BUG-103)

        // All day toggle
        const allDayCheckbox = document.getElementById('event-allday');
        if (!allDayCheckbox) {
            console.warn('[EventModal] All-day checkbox not found in bindFormEvents');
            return;
        }

        const startInput = document.getElementById('event-start');
        if (!startInput) {
            console.warn('[EventModal] Start input not found in bindFormEvents');
            return;
        }

        const endInput = document.getElementById('event-end');
        if (!endInput) {
            console.warn('[EventModal] End input not found in bindFormEvents');
            return;
        }

        allDayCheckbox.addEventListener('change', (e) => {
            if (e.target.checked) {
                startInput.type = 'date';
                endInput.type = 'date';
                // Convert current values to date-only (YYYY-MM-DD)
                if (startInput.value) startInput.value = String(startInput.value).split('T')[0];
                if (endInput.value) endInput.value = String(endInput.value).split('T')[0];
            } else {
                startInput.type = 'datetime-local';
                endInput.type = 'datetime-local';
                // Convert date-only values to datetime-local with a sensible default time
                if (startInput.value && !String(startInput.value).includes('T')) startInput.value = `${startInput.value}T09:00`;
                if (endInput.value && !String(endInput.value).includes('T')) endInput.value = `${endInput.value}T10:00`;
            }
        });

        // Recurrence selector
        const recurrenceSelect = document.getElementById('event-recurrence');
        if (!recurrenceSelect) {
            console.warn('[EventModal] Recurrence select not found in bindFormEvents');
            return;
        }

        recurrenceSelect.addEventListener('change', (e) => {
            const details = document.getElementById('recurrence-details');
            if (details) {
                if (e.target.value === 'CUSTOM') {
                    details.style.display = 'block';
                } else {
                    details.style.display = 'none';
                }
            }
        });

        // Participant search
        const participantSearch = document.getElementById('participant-search');
        if (!participantSearch) {
            // Optional UI element (some modal variants don't include participant search)
            console.debug('[EventModal] Participant search not found in bindFormEvents (skipping)');
        } else {
            participantSearch.addEventListener('input', debounce((e) => {
                this.searchParticipants(e.target.value);
            }, 300));
        }

        // Color picker
        document.querySelectorAll('.color-option').forEach(option => {
            option.addEventListener('click', (e) => {
                document.querySelectorAll('.color-option').forEach(o => o.classList.remove('selected'));
                e.target.classList.add('selected');
                this.event.color = e.target.dataset.color;
            });
        });
    }

    async save() {
        if (!this.validateForm()) return;

        const formData = this.getFormData();

        // Check for conflicts
        const conflicts = await this.app.components.eventManager.checkConflicts(formData);
        if (conflicts.length > 0) {
            if (!confirm('Ci sono conflitti con altri eventi. Vuoi continuare?')) {
                return;
            }
        }

        try {
            if (this.isNew) {
                await this.app.createEvent(formData);
            } else {
                await this.app.updateEvent({
                    ...formData,
                    id: this.event.id,
                    tenant_id: this.event?.tenant_id,
                    source_tenant_id: this.event?.source_tenant_id
                });
            }

            this.hide();
        } catch (error) {
            console.error('Error saving event:', error);
        }
    }

    async saveAsDraft() {
        const formData = this.getFormData();
        formData.status = 'draft';

        try {
            if (this.isNew) {
                await this.app.createEvent(formData);
            } else {
                await this.app.updateEvent({
                    ...formData,
                    id: this.event.id,
                    tenant_id: this.event?.tenant_id,
                    source_tenant_id: this.event?.source_tenant_id
                });
            }

            this.app.showToast('Bozza salvata', 'success');
            this.hide();
        } catch (error) {
            console.error('Error saving draft:', error);
        }
    }

    async delete() {
        if (!this.event.id) return;

        if (confirm('Sei sicuro di voler eliminare questo evento?')) {
            await this.app.deleteEvent(this.event);
            this.hide();
        }
    }

    validateForm() {
        // BUG-116 FIX: Defensive null checks before accessing form elements (pattern BUG-103)
        const titleElement = document.getElementById('event-title');
        if (!titleElement) {
            console.error('[EventModal] Title element not found in validateForm');
            return false;
        }

        const startElement = document.getElementById('event-start');
        if (!startElement) {
            console.error('[EventModal] Start element not found in validateForm');
            return false;
        }

        const endElement = document.getElementById('event-end');
        if (!endElement) {
            console.error('[EventModal] End element not found in validateForm');
            return false;
        }

        const title = titleElement.value.trim();
        const start = startElement.value;
        const end = endElement.value;
        const allDayElement = document.getElementById('event-allday');
        const isAllDay = !!(allDayElement && allDayElement.checked);

        if (!title) {
            this.app.showToast('Il titolo è obbligatorio', 'error');
            return false;
        }

        if (!start || !end) {
            this.app.showToast('Le date sono obbligatorie', 'error');
            return false;
        }

        const startDt = cnxParseLocalInputDate(start);
        const endDt = cnxParseLocalInputDate(end);
        if (!startDt || !endDt) {
            this.app.showToast('Le date non sono valide', 'error');
            return false;
        }

        if (isAllDay) {
            // All-day: allow start == end (1 day)
            if (startDt > endDt) {
                this.app.showToast('La data di fine deve essere uguale o dopo quella di inizio', 'error');
                return false;
            }
        } else {
            if (startDt >= endDt) {
                this.app.showToast('La data di fine deve essere dopo quella di inizio', 'error');
                return false;
            }
        }

        return true;
    }

    getFormData() {
        // BUG-116 FIX: Defensive null checks with fallback values (pattern BUG-103)
        const titleElement = document.getElementById('event-title');
        const descriptionElement = document.getElementById('event-description');
        const startElement = document.getElementById('event-start');
        const endElement = document.getElementById('event-end');
        const allDayElement = document.getElementById('event-allday');
        const locationElement = document.getElementById('event-location');
        const calendarElement = document.getElementById('event-calendar');
        const categoryElement = document.getElementById('event-category');
        const tagsElement = document.getElementById('event-tags');

        // Determine recurrence based on selector (avoid default DAILY causing unintended recurrence)
        const recurrenceSelect = document.getElementById('event-recurrence');
        const recurrenceChoice = recurrenceSelect ? String(recurrenceSelect.value || '') : '';
        let recurrenceRule = '';
        if (recurrenceChoice === 'CUSTOM') {
            recurrenceRule = this.recurrenceBuilder.getRule() || '';
        } else if (['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'].includes(recurrenceChoice)) {
            recurrenceRule = `FREQ=${recurrenceChoice}`;
        } else {
            recurrenceRule = '';
        }

        const isAllDay = !!(allDayElement && allDayElement.checked);
        let startOut = startElement ? String(startElement.value || '') : '';
        let endOut = endElement ? String(endElement.value || '') : '';

        if (isAllDay) {
            // All-day events:
            // - UI uses inclusive end date (start=end means 1 day)
            // - Storage/API expects end > start; we serialize as end-exclusive at 00:00 of (end + 1 day)
            const startKey = startOut ? startOut.split('T')[0] : '';
            const endKey = endOut ? endOut.split('T')[0] : '';
            const endPlus1 = endKey ? cnxAddDaysLocalDateKey(endKey, 1) : '';
            startOut = startKey ? `${startKey}T00:00` : '';
            endOut = endPlus1 ? `${endPlus1}T00:00` : '';
        } else {
            // Defensive: if inputs are date-only, add default times
            if (startOut && !startOut.includes('T')) startOut = `${startOut}T09:00`;
            if (endOut && !endOut.includes('T')) endOut = `${endOut}T10:00`;
        }

        return {
            title: titleElement ? titleElement.value.trim() : '',
            description: descriptionElement ? descriptionElement.value.trim() : '',
            start_date: startOut,
            end_date: endOut,
            all_day: isAllDay,
            location: locationElement ? locationElement.value.trim() : '',
            calendar_id: calendarElement ? parseInt(calendarElement.value) : null,
            category: categoryElement ? categoryElement.value : '',
            tags: tagsElement ? tagsElement.value.split(',').map(t => t.trim()).filter(t => t) : [],
            color: this.event.color,
            recurrence_rule: recurrenceRule,
            reminders: this.getReminders(),
            participants: this.getParticipants()
        };
    }

    formatDateTimeLocal(date) {
        if (!date) return '';
        const d = new Date(date);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hours = String(d.getHours()).padStart(2, '0');
        const minutes = String(d.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    formatDateOnlyForAllDay(date) {
        if (!date) return '';
        const d = new Date(date);
        if (Number.isNaN(d.getTime())) return '';
        return cnxLocalDateKey(d);
    }

    /**
     * For all-day UI: treat end as inclusive. We derive a display end date by subtracting 1ms
     * from the stored end datetime (which is often end-exclusive at 00:00 of the next day).
     */
    getAllDayDisplayEndDate(endDate, startDate) {
        const s = startDate ? new Date(startDate) : null;
        const e = endDate ? new Date(endDate) : null;
        const startOk = s && !Number.isNaN(s.getTime());
        const endOk = e && !Number.isNaN(e.getTime());

        if (!startOk && endOk) return e;
        if (!startOk && !endOk) return new Date();
        if (!endOk) return s;

        const display = new Date(e.getTime() - 1);
        if (Number.isNaN(display.getTime()) || display < s) return s;
        return display;
    }

    renderParticipants() {
        if (!this.event.participants || this.event.participants.length === 0) {
            return '<div class="empty-state">Nessun partecipante</div>';
        }

        return this.event.participants.map(p => `
            <div class="participant-chip">
                <span>${p.name}</span>
                <button type="button" class="remove" data-id="${p.id}">×</button>
            </div>
        `).join('');
    }

    renderReminders() {
        if (!this.event.reminders || this.event.reminders.length === 0) {
            return '';
        }

        return this.event.reminders.map((r, index) => `
            <div class="reminder-item">
                <select class="form-control form-control-sm">
                    <option value="0" ${r.minutes === 0 ? 'selected' : ''}>Al momento</option>
                    <option value="5" ${r.minutes === 5 ? 'selected' : ''}>5 minuti prima</option>
                    <option value="15" ${r.minutes === 15 ? 'selected' : ''}>15 minuti prima</option>
                    <option value="30" ${r.minutes === 30 ? 'selected' : ''}>30 minuti prima</option>
                    <option value="60" ${r.minutes === 60 ? 'selected' : ''}>1 ora prima</option>
                    <option value="1440" ${r.minutes === 1440 ? 'selected' : ''}>1 giorno prima</option>
                </select>
                <button type="button" class="btn btn-sm btn-icon" onclick="window.calendar.components.eventModal.removeReminder(${index})">
                    ×
                </button>
            </div>
        `).join('');
    }

    renderColorPicker() {
        const colors = [
            '#3788d8', '#f44336', '#ff9800', '#ffc107',
            '#4caf50', '#00bcd4', '#9c27b0', '#607d8b'
        ];

        return colors.map(color => `
            <div class="color-option ${color === this.event.color ? 'selected' : ''}"
                 style="background-color: ${color}"
                 data-color="${color}"></div>
        `).join('');
    }

    addReminder() {
        if (!this.event.reminders) {
            this.event.reminders = [];
        }
        this.event.reminders.push({ minutes: 15 });
        this.render();
    }

    removeReminder(index) {
        this.event.reminders.splice(index, 1);
        this.render();
    }

    getReminders() {
        const reminders = [];
        document.querySelectorAll('.reminder-item select').forEach(select => {
            reminders.push({ minutes: parseInt(select.value) });
        });
        return reminders;
    }

    getParticipants() {
        // Return selected participants (array of user IDs for API)
        return this.selectedParticipants ? this.selectedParticipants.map(p => p.id) : [];
    }

    async searchParticipants(query) {
        if (query.length < 2) return;

        try {
            const response = await this.app.apiCall(`users/search?q=${encodeURIComponent(query)}`);
            // Display search results
            this.displayParticipantResults(response.data || []);
        } catch (error) {
            console.error('Error searching participants:', error);
        }
    }

    displayParticipantResults(results) {
        // Implement search results display
    }

    openMap() {
        const location = document.getElementById('event-location').value;
        if (location) {
            window.open(`https://maps.google.com/?q=${encodeURIComponent(location)}`, '_blank');
        }
    }


    async renderEventParticipants(event) {
        try {
            // Load participants from API
            const token = this.app.getCsrfToken();
            const response = await fetch(`${this.app.config.apiBase}events.php?action=participants&event_id=${event.id}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': token
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            const participants = data?.data?.participants || [];

            let html = '<div class="event-participants">';
            html += '<h4>Partecipanti</h4>';

            if (participants.length > 0) {
                html += '<ul class="participant-list">';
                participants.forEach(p => {
                    const statusClass = p.status || 'pending'; // pending|accepted|declined
                    const statusLabel = {
                        'pending': 'In attesa',
                        'accepted': 'Accettato',
                        'declined': 'Rifiutato'
                    }[statusClass] || statusClass;

                    html += `
                        <li class="participant-item">
                            <span class="participant-name">${p.name}</span>
                            <span class="participant-status status-${statusClass}">${statusLabel}</span>
                        </li>
                    `;
                });
                html += '</ul>';
            } else {
                html += '<p class="text-muted">Nessun partecipante</p>';
            }

            // Add "Add Participants" button (only for organizer)
            const currentUserId = this.getCurrentUserId();
            if (event.organizer_id === currentUserId || event.created_by === currentUserId) {
                html += `<button class="btn btn-sm btn-primary" onclick="window.calendar.components.eventModal.showParticipantModal()">+ Aggiungi Partecipanti</button>`;
            }

            html += '</div>';

            return html;

        } catch (error) {
            console.error('[Calendar] Error loading participants:', error);
            return '<div class="event-participants"><p class="text-muted">Errore nel caricamento dei partecipanti</p></div>';
        }
    }

    getCurrentUserId() {
        // Extract current user ID from session or hidden input
        const userIdInput = document.getElementById('currentUserId');
        if (userIdInput) {
            return parseInt(userIdInput.value);
        }
        // Fallback: try to get from global state
        return window.currentUserId || null;
    }

    /**
     * Load existing participants for an event
     */
    async loadExistingParticipants(eventId) {
        try {
            const response = await this.app.apiCall(`events.php?action=participants&event_id=${eventId}`, {
                method: 'GET'
            });

            if (response.success && response.data?.participants) {
                this.selectedParticipants = response.data.participants.map(p => ({
                    id: parseInt(p.user_id || p.id),
                    name: p.name,
                    email: p.email,
                    status: p.status || 'pending'
                }));
                this.renderSelectedParticipants();
            }
        } catch (error) {
            console.error('[EventModal] Error loading existing participants:', error);
        }
    }

    /**
     * Show participant selection modal with RBAC-filtered users
     */
    async showParticipantModal() {
        try {
            // Get current event data
            const eventId = document.getElementById('event-id')?.value || null;
            const tenantId = document.getElementById('event-tenant')?.value || null;

            // Build query params
            const params = new URLSearchParams();
            if (eventId) params.append('event_id', eventId);
            if (tenantId) params.append('tenant_id', tenantId);

            // Call API for available users (RBAC-filtered)
            const response = await this.app.apiCall(`events.php?action=available_users&${params.toString()}`, {
                method: 'GET'
            });

            if (!response.success) {
                this.app.showToast('Errore caricamento utenti', 'error');
                return;
            }

            const users = response.data?.users || [];

            // Pre-select already selected participants
            const selectedIds = new Set(this.selectedParticipants.map(p => parseInt(p.id)));

            // Create modal HTML
            const modalHTML = `
                <div id="participant-modal-overlay" class="event-modal active">
                    <div class="event-modal-content" style="max-width: 600px;">
                        <div class="event-modal-header">
                            <h2>Seleziona Partecipanti</h2>
                            <button class="modal-close" onclick="window.calendar.components.eventModal.closeParticipantModal()">&times;</button>
                        </div>
                        <div class="event-modal-body">
                            <div class="form-group">
                                <input type="text" id="participant-search" class="form-control" placeholder="Cerca utenti..." />
                            </div>
                            <div class="participant-list" style="max-height: 400px; overflow-y: auto;">
                                ${users.map(user => `
                                    <label class="participant-item">
                                        <input type="checkbox" value="${user.id}" data-name="${user.name}" data-email="${user.email}" ${selectedIds.has(parseInt(user.id)) ? 'checked' : ''}>
                                        <div class="participant-info">
                                            <div class="participant-name">${user.name}</div>
                                            <div class="participant-meta">
                                                <span class="role-badge">${user.role}</span>
                                                <span class="text-muted">${user.email}</span>
                                            </div>
                                        </div>
                                    </label>
                                `).join('')}
                            </div>
                        </div>
                        <div class="event-modal-footer">
                            <button class="btn btn-secondary" onclick="window.calendar.components.eventModal.closeParticipantModal()">Annulla</button>
                            <button class="btn btn-primary" onclick="window.calendar.components.eventModal.saveParticipants()">Aggiungi <span id="selected-count">(${selectedIds.size})</span></button>
                        </div>
                    </div>
                </div>
            `;

            // Append to body
            document.body.insertAdjacentHTML('beforeend', modalHTML);

            // Add search handler
            const searchInput = document.getElementById('participant-search');
            if (searchInput) {
                searchInput.addEventListener('input', this.filterParticipants.bind(this));
            }

            // Update count on checkbox change
            document.querySelectorAll('.participant-item input[type="checkbox"]').forEach(cb => {
                cb.addEventListener('change', this.updateParticipantCount.bind(this));
            });

        } catch (error) {
            console.error('[EventModal] Error loading participants:', error);
            this.app.showToast('Errore caricamento partecipanti', 'error');
        }
    }

    /**
     * Close participant modal
     */
    closeParticipantModal() {
        const modal = document.getElementById('participant-modal-overlay');
        if (modal) {
            modal.remove();
        }
    }

    /**
     * Save selected participants
     */
    saveParticipants() {
        const selected = Array.from(document.querySelectorAll('.participant-item input[type="checkbox"]:checked'));

        this.selectedParticipants = selected.map(cb => ({
            id: parseInt(cb.value),
            name: cb.dataset.name,
            email: cb.dataset.email,
            status: 'pending'
        }));

        this.renderSelectedParticipants();
        this.closeParticipantModal();
        this.app.showToast(`${this.selectedParticipants.length} partecipante/i selezionato/i`, 'success');
    }

    /**
     * Render selected participants as chips
     */
    renderSelectedParticipants() {
        const container = document.getElementById('selected-participants');
        if (!container) return;

        if (this.selectedParticipants.length === 0) {
            container.innerHTML = '<p class="text-muted" style="margin-top: 8px; font-size: 13px;">Nessun partecipante selezionato</p>';
            return;
        }

        container.innerHTML = this.selectedParticipants.map(p => `
            <div class="participant-chip">
                <span>${p.name}</span>
                <span class="participant-chip-remove" onclick="window.calendar.components.eventModal.removeParticipant(${p.id})">&times;</span>
            </div>
        `).join('');
    }

    /**
     * Remove a participant
     */
    removeParticipant(participantId) {
        this.selectedParticipants = this.selectedParticipants.filter(p => p.id !== participantId);
        this.renderSelectedParticipants();
        this.app.showToast('Partecipante rimosso', 'info');
    }

    /**
     * Filter participants by search term
     */
    filterParticipants(event) {
        const searchTerm = event.target.value.toLowerCase();
        const items = document.querySelectorAll('.participant-item');

        items.forEach(item => {
            const name = item.querySelector('.participant-name').textContent.toLowerCase();
            const email = item.querySelector('.text-muted').textContent.toLowerCase();
            const matches = name.includes(searchTerm) || email.includes(searchTerm);
            item.style.display = matches ? 'flex' : 'none';
        });
    }

    /**
     * Update participant count in modal footer
     */
    updateParticipantCount() {
        const checkedCount = document.querySelectorAll('.participant-item input[type="checkbox"]:checked').length;
        const countElement = document.getElementById('selected-count');
        if (countElement) {
            countElement.textContent = `(${checkedCount})`;
        }
    }
}

class RecurrenceBuilder {
    constructor(modal) {
        this.modal = modal;
        this.rule = {};
    }

    render() {
        return `
            <div class="recurrence-builder" style="display: none;">
                <div class="form-group">
                    <label>Ripeti ogni</label>
                    <div class="input-group">
                        <input type="number" id="recurrence-interval" min="1" value="1" class="form-control">
                        <select id="recurrence-freq" class="form-control">
                            <option value="DAILY">giorni</option>
                            <option value="WEEKLY">settimane</option>
                            <option value="MONTHLY">mesi</option>
                            <option value="YEARLY">anni</option>
                        </select>
                    </div>
                </div>

                <div id="weekly-options" class="form-group" style="display: none;">
                    <label>Ripeti il</label>
                    <div class="weekday-selector">
                        ${this.renderWeekdaySelector()}
                    </div>
                </div>

                <div class="form-group">
                    <label>Termina</label>
                    <select id="recurrence-end" class="form-control">
                        <option value="never">Mai</option>
                        <option value="after">Dopo</option>
                        <option value="on">Il</option>
                    </select>

                    <div id="recurrence-count" style="display: none;">
                        <input type="number" min="1" value="10" class="form-control">
                        <span>occorrenze</span>
                    </div>

                    <div id="recurrence-until" style="display: none;">
                        <input type="date" class="form-control">
                    </div>
                </div>
            </div>
        `;
    }

    renderWeekdaySelector() {
        const days = ['LU', 'MA', 'ME', 'GI', 'VE', 'SA', 'DO'];
        return days.map((day, index) => `
            <label class="weekday-option">
                <input type="checkbox" value="${index + 1}">
                <span>${day}</span>
            </label>
        `).join('');
    }

    getRule() {
        const freq = document.getElementById('recurrence-freq')?.value;
        if (!freq || freq === '') return '';

        let rule = `FREQ=${freq}`;

        const interval = document.getElementById('recurrence-interval')?.value;
        if (interval && interval !== '1') {
            rule += `;INTERVAL=${interval}`;
        }

        // Weekly options
        if (freq === 'WEEKLY') {
            const selectedDays = [];
            document.querySelectorAll('.weekday-option input:checked').forEach(input => {
                selectedDays.push(input.value);
            });
            if (selectedDays.length > 0) {
                rule += `;BYDAY=${selectedDays.join(',')}`;
            }
        }

        // End conditions
        const endType = document.getElementById('recurrence-end')?.value;
        if (endType === 'after') {
            const count = document.querySelector('#recurrence-count input')?.value;
            if (count) rule += `;COUNT=${count}`;
        } else if (endType === 'on') {
            const until = document.querySelector('#recurrence-until input')?.value;
            if (until) rule += `;UNTIL=${until}`;
        }

        return rule;
    }

    parseRule(rrule) {
        if (!rrule) return;

        const parts = rrule.split(';');
        parts.forEach(part => {
            const [key, value] = part.split('=');
            this.rule[key] = value;
        });
    }
}

class DragDropHandler {
    constructor(app) {
        this.app = app;
        this.draggedEvent = null;
        this.dragGhost = null;
        this.dropTarget = null;
    }

    init() {
        const viewContainer = document.getElementById('calendar-view');

        // Drag start
        viewContainer.addEventListener('dragstart', (e) => {
            if (!e.target.classList.contains('calendar-event')) return;

            const rawId = e.target.dataset.eventId;
            // BUG-112 FIX: Defensive check for undefined events
            const events = this.app.state.events || [];
            this.draggedEvent = events.find(ev => String(ev.id) === String(rawId));

            if (!this.draggedEvent) return;

            e.target.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(rawId));

            // Create ghost element
            this.createGhost(e.target);
        });

        // Drag over
        viewContainer.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            const target = this.getDropTarget(e.target);
            if (target && target !== this.dropTarget) {
                this.dropTarget?.classList.remove('drag-over');
                this.dropTarget = target;
                this.dropTarget.classList.add('drag-over');
            }

            // Update ghost position
            if (this.dragGhost) {
                this.updateGhostPosition(e);
            }
        });

        // Drag leave
        viewContainer.addEventListener('dragleave', (e) => {
            const target = this.getDropTarget(e.target);
            if (target) {
                target.classList.remove('drag-over');
            }
        });

        // Drop
        viewContainer.addEventListener('drop', async (e) => {
            e.preventDefault();

            const target = this.getDropTarget(e.target);
            if (!target || !this.draggedEvent) return;

            target.classList.remove('drag-over');

            // Calculate new date/time
            const newDate = this.calculateNewDate(target, e);
            if (!newDate) return;

            // Update event
            const duration = this.draggedEvent.end - this.draggedEvent.start;
            const updatedEvent = {
                ...this.draggedEvent,
                // IMPORTANT: keep local datetime to avoid UTC shift in storage/UI
                start_date: cnxLocalDateTimeKey(newDate),
                end_date: cnxLocalDateTimeKey(new Date(newDate.getTime() + duration))
            };

            // Check for conflicts
            const conflicts = await this.app.components.eventManager.checkConflicts(updatedEvent);
            if (conflicts.length > 0) {
                this.showConflictWarning(conflicts, updatedEvent);
            } else {
                await this.app.updateEvent(updatedEvent);
            }
        });

        // Drag end
        viewContainer.addEventListener('dragend', (e) => {
            e.target.classList.remove('dragging');
            this.cleanup();
        });

        // Handle resize
        this.initResize();
    }

    initResize() {
        const viewContainer = document.getElementById('calendar-view');

        viewContainer.addEventListener('mousedown', (e) => {
            if (!e.target.classList.contains('event-resize-handle')) return;

            e.preventDefault();
            const eventEl = e.target.closest('.calendar-event');
            const rawId = eventEl?.dataset?.eventId;
            // BUG-112 FIX: Defensive check for undefined events
            const events = this.app.state.events || [];
            const event = events.find(ev => String(ev.id) === String(rawId));

            if (!event) return;

            this.startResize(event, eventEl, e);
        });
    }

    startResize(event, element, mouseEvent) {
        const startY = mouseEvent.clientY;
        const startHeight = element.offsetHeight;

        const handleMouseMove = (e) => {
            const deltaY = e.clientY - startY;
            const newHeight = Math.max(30, startHeight + deltaY);
            element.style.height = `${newHeight}px`;

            // Calculate new duration
            const slotHeight = 30;
            const slots = Math.round(newHeight / slotHeight);
            const newDuration = slots * this.app.config.slotDuration * 60000;

            // Update visual feedback
            const newEnd = new Date(event.start.getTime() + newDuration);
            element.dataset.tempEnd = cnxLocalDateTimeKey(newEnd);
        };

        const handleMouseUp = async () => {
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('mouseup', handleMouseUp);

            const tempEnd = element.dataset.tempEnd;
            if (tempEnd) {
                const updatedEvent = {
                    ...event,
                    end_date: tempEnd
                };

                await this.app.updateEvent(updatedEvent);
                delete element.dataset.tempEnd;
            }
        };

        document.addEventListener('mousemove', handleMouseMove);
        document.addEventListener('mouseup', handleMouseUp);
    }

    createGhost(element) {
        this.dragGhost = element.cloneNode(true);
        this.dragGhost.classList.add('drag-ghost');
        this.dragGhost.style.position = 'fixed';
        this.dragGhost.style.pointerEvents = 'none';
        this.dragGhost.style.opacity = '0.5';
        this.dragGhost.style.zIndex = '9999';
        document.body.appendChild(this.dragGhost);
    }

    updateGhostPosition(e) {
        if (!this.dragGhost) return;
        this.dragGhost.style.left = `${e.clientX + 10}px`;
        this.dragGhost.style.top = `${e.clientY + 10}px`;
    }

    getDropTarget(element) {
        return element.closest('.calendar-day, .calendar-time-slot, .calendar-hour');
    }

    calculateNewDate(target, dropEvent) {
        const dateStr = target.dataset.date;
        if (!dateStr) return null;

        // Parse as local day (avoid Date("YYYY-MM-DD") UTC semantics)
        const newDate = new Date(String(dateStr) + 'T00:00:00');

        // If dropping on a time slot, use that time
        if (target.dataset.time) {
            const [hours, minutes] = target.dataset.time.split(':').map(Number);
            newDate.setHours(hours, minutes, 0, 0);
        } else if (!this.draggedEvent.allDay) {
            // Keep the same time if not all-day event
            newDate.setHours(
                this.draggedEvent.start.getHours(),
                this.draggedEvent.start.getMinutes(),
                0, 0
            );
        }

        return newDate;
    }

    showConflictWarning(conflicts, updatedEvent) {
        const message = `Conflitto con: ${conflicts.map(c => c.title).join(', ')}`;

        if (confirm(`${message}\n\nVuoi continuare comunque?`)) {
            this.app.updateEvent(updatedEvent);
        }
    }

    cleanup() {
        this.draggedEvent = null;
        this.dropTarget = null;

        if (this.dragGhost) {
            this.dragGhost.remove();
            this.dragGhost = null;
        }

        // Remove all drag-over classes
        document.querySelectorAll('.drag-over').forEach(el => {
            el.classList.remove('drag-over');
        });
    }
}

class CalendarToolbar {
    constructor(app) {
        this.app = app;
        this.container = document.getElementById('calendar-toolbar');
    }

    render() {
        const { calendars, selectedCalendars } = this.app.state;
        const selectedCount = selectedCalendars?.size ?? 0;
        const showEvents = this.app.state?.filters?.showEvents !== false;
        const showShifts = !!this.app.state?.filters?.showShifts;

        const getCalendarDisplayName = (cal) => {
            if (cal?.visibility === 'private') return cal.name;
            return cal?.tenant?.name || cal?.tenant_name || cal.name;
        };

        this.container.innerHTML = `
            <div class="toolbar-section">
                <button class="btn btn-primary" onclick="window.calendar.components.eventModal.show()">
                    + Nuovo
                </button>
            </div>

            <div class="toolbar-section">
                <div class="view-switcher">
                    <button class="btn active" onclick="window.calendar.changeView('month')">Mese</button>
                    <button class="btn" onclick="window.calendar.changeView('week')">Settimana</button>
                    <button class="btn" onclick="window.calendar.changeView('day')">Giorno</button>
                </div>
            </div>

            <div class="toolbar-section">
                <button class="btn btn-icon" onclick="window.calendar.navigateDate('prev')">
                    ◀
                </button>
                <button class="btn btn-outline" onclick="window.calendar.goToToday()">
                    Oggi
                </button>
                <button class="btn btn-icon" onclick="window.calendar.navigateDate('next')">
                    ▶
                </button>
                <span class="current-date">${this.formatCurrentDate()}</span>
            </div>

            <div class="toolbar-section">
                <details class="calendar-calendars-dropdown">
                    <summary class="btn btn-outline calendar-calendars-summary">
                        Calendari (<span class="calendar-selected-count">${selectedCount}</span>)
                    </summary>
                    <div class="calendar-calendars-menu">
                        <div class="calendar-calendars-actions">
                            <button type="button" class="btn btn-outline btn-sm" data-action="select-all">Tutti</button>
                            <button type="button" class="btn btn-outline btn-sm" data-action="select-none">Nessuno</button>
                        </div>
                        <div class="calendar-calendars-list">
                            ${(Array.isArray(calendars) ? calendars : []).map(cal => {
                                const isChecked = selectedCalendars?.has(cal.id) ? 'checked' : '';
                                const displayName = getCalendarDisplayName(cal);
                                const perm = cal.user_permission || 'read';
                                const color = cal.color || '#3B82F6';
                                return `
                                    <label class="calendar-cal-item">
                                        <input class="calendar-cal-checkbox" type="checkbox" data-id="${cal.id}" ${isChecked}>
                                        <span class="calendar-cal-dot" style="background:${color}"></span>
                                        <span class="calendar-cal-name" title="${displayName}">${displayName}</span>
                                        <span class="calendar-cal-perm" title="Permesso: ${perm}">${perm}</span>
                                    </label>
                                `;
                            }).join('')}
                        </div>
                    </div>
                </details>
            </div>

            <div class="toolbar-section">
                <div class="calendar-display-filters" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <label class="filter-option" style="display:flex; gap:6px; align-items:center; margin:0;">
                        <input type="checkbox" id="filter-show-events" ${showEvents ? 'checked' : ''}>
                        <span>Eventi</span>
                    </label>
                    <label class="filter-option" style="display:flex; gap:6px; align-items:center; margin:0;">
                        <input type="checkbox" id="filter-show-shifts" ${showShifts ? 'checked' : ''}>
                        <span>Turni</span>
                    </label>
                </div>
            </div>

            <div class="toolbar-section">
                <input type="text"
                       class="search-box"
                       placeholder="Cerca eventi..."
                       onkeyup="window.calendar.components.toolbar.handleSearch(event)">
                <button class="btn btn-icon" onclick="window.calendar.components.toolbar.print()">
                    🖨
                </button>
                <button class="btn btn-icon" onclick="window.calendar.components.toolbar.importICS()">
                    ⬆
                </button>
                <button class="btn btn-icon" onclick="window.calendar.components.toolbar.export()">
                    ⬇
                </button>
            </div>
        `;

        this.bindCalendarFilterEvents();
        this.bindDisplayFilterEvents();
    }

    bindCalendarFilterEvents() {
        // Defensive: container can be null during early boot
        if (!this.container) return;

        const dropdown = this.container.querySelector('.calendar-calendars-dropdown');
        if (!dropdown) return;

        // Buttons: select all / none
        dropdown.querySelectorAll('[data-action]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const action = btn.dataset.action;

                if (action === 'select-all') {
                    this.app.state.selectedCalendars = new Set((this.app.state.calendars || []).map(c => c.id));
                } else if (action === 'select-none') {
                    this.app.state.selectedCalendars = new Set();
                }

                // Reflect UI
                dropdown.querySelectorAll('.calendar-cal-checkbox').forEach(cb => {
                    const id = parseInt(cb.dataset.id, 10);
                    cb.checked = this.app.state.selectedCalendars.has(id);
                });
                this.updateSelectedCount(dropdown);

                this.app.loadEvents();
            });
        });

        // Checkbox toggles
        dropdown.querySelectorAll('.calendar-cal-checkbox').forEach(cb => {
            cb.addEventListener('change', () => {
                const id = parseInt(cb.dataset.id, 10);
                if (Number.isNaN(id)) return;

                if (cb.checked) {
                    this.app.state.selectedCalendars.add(id);
                } else {
                    this.app.state.selectedCalendars.delete(id);
                }

                this.updateSelectedCount(dropdown);
                this.app.loadEvents();
            });
        });
    }

    bindDisplayFilterEvents() {
        if (!this.container) return;

        const cbEvents = this.container.querySelector('#filter-show-events');
        const cbShifts = this.container.querySelector('#filter-show-shifts');

        if (cbEvents) {
            cbEvents.addEventListener('change', async (e) => {
                const checked = !!e.target.checked;
                // Default: events visible. Store explicit false to hide.
                if (checked) {
                    this.app.state.filters.showEvents = true;
                    await this.app.loadEvents();
                } else {
                    this.app.state.filters.showEvents = false;
                    this.app.state.events = [];
                }
                this.app.components.view.render();
            });
        }

        if (cbShifts) {
            cbShifts.addEventListener('change', async (e) => {
                const checked = !!e.target.checked;
                if (checked) {
                    this.app.state.filters.showShifts = true;
                    await this.app.loadShifts();
                } else {
                    delete this.app.state.filters.showShifts;
                    this.app.state.shifts = [];
                }
                this.app.components.view.render();
            });
        }
    }

    updateSelectedCount(dropdownEl) {
        const countEl = dropdownEl.querySelector('.calendar-selected-count');
        if (countEl) countEl.textContent = String(this.app.state.selectedCalendars.size);
    }

    formatCurrentDate() {
        const { currentDate, currentView } = this.app.state;

        if (currentView === 'month') {
            return currentDate.toLocaleDateString(this.app.config.locale, {
                month: 'long',
                year: 'numeric'
            });
        } else if (currentView === 'week') {
            const start = new Date(currentDate);
            start.setDate(start.getDate() - start.getDay() + this.app.config.firstDayOfWeek);
            const end = new Date(start);
            end.setDate(start.getDate() + 6);

            return `${start.getDate()} - ${end.getDate()} ${start.toLocaleDateString(this.app.config.locale, {
                month: 'long',
                year: 'numeric'
            })}`;
        } else {
            return currentDate.toLocaleDateString(this.app.config.locale, {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
        }
    }

    updateDateDisplay() {
        const dateElement = this.container.querySelector('.current-date');
        if (dateElement) {
            dateElement.textContent = this.formatCurrentDate();
        }
    }

    updateViewButtons() {
        // Update internal buttons (based on onclick content as fallback)
        const internalButtons = this.container.querySelectorAll('.view-switcher button');
        internalButtons.forEach(btn => {
            btn.classList.remove('active');
            const onclick = btn.getAttribute('onclick');
            if (onclick && onclick.includes(`'${this.app.state.currentView}'`)) {
                btn.classList.add('active');
            }
        });

        // Update external buttons (in page header)
        const externalButtons = document.querySelectorAll('.calendar-view-btn');
        externalButtons.forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.view === this.app.state.currentView) {
                btn.classList.add('active');
            }
        });
    }

    handleSearch(event) {
        const query = event.target.value.trim();

        if (event.key === 'Enter' && query) {
            this.app.state.filters.search = query;
            this.app.loadEvents();
        } else if (!query) {
            delete this.app.state.filters.search;
            this.app.loadEvents();
        }
    }

    print() {
        window.print();
    }

    async export() {
        try {
            const params = new URLSearchParams({
                start: this.app.state.viewBounds.start.toISOString(),
                end: this.app.state.viewBounds.end.toISOString()
            });

            const response = await fetch(`${this.app.config.apiBase}events.php?action=export&${params}`, {
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': this.app.getCsrfToken()
                }
            });
            const blob = await response.blob();

            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `calendar_${cnxLocalDateKey(new Date())}.ics`;
            a.click();
        } catch (error) {
            console.error('Error exporting calendar:', error);
            this.app.showToast('Errore nell\'esportazione', 'error');
        }
    }

    importICS() {
        // Create a hidden file input on demand
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.ics,text/calendar';
        input.style.display = 'none';

        input.addEventListener('change', async () => {
            const file = input.files && input.files[0];
            if (!file) return;

            try {
                const form = new FormData();
                form.append('ics_file', file);

                const resp = await fetch(`${this.app.config.apiBase}events.php?action=import`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': this.app.getCsrfToken()
                    },
                    body: form
                });

                if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
                const result = await resp.json();

                const imported = result?.data?.imported?.length ?? 0;
                const errors = result?.data?.errors?.length ?? 0;
                if (errors > 0) {
                    this.app.showToast(`Import completato: ${imported} eventi, ${errors} errori`, 'warning');
                } else {
                    this.app.showToast(`Import completato: ${imported} eventi`, 'success');
                }

                await this.app.loadEvents();
            } catch (e) {
                console.error('Error importing calendar:', e);
                this.app.showToast('Errore nell\'importazione', 'error');
            } finally {
                input.remove();
            }
        });

        document.body.appendChild(input);
        input.click();
    }
}

class CalendarSidebar {
    constructor(app) {
        this.app = app;
        this.container = document.getElementById('calendar-sidebar');
        this.isCollapsed = false;
    }

    render() {
        // CALENDAR OPTIMIZATION: Removed Mini Calendar and Calendar List sections
        // Only keeping Upcoming Events and Quick Filters for cleaner UI
        this.container.innerHTML = `
            <div class="sidebar-section">
                <h3>Prossimi Eventi</h3>
                <div class="upcoming-events" id="upcoming-events"></div>
            </div>

            <div class="sidebar-section">
                <h3>Filtri Rapidi</h3>
                <div class="quick-filters">
                    <label class="filter-option">
                        <input type="checkbox" id="filter-my-events">
                        <span>I miei eventi</span>
                    </label>
                    <label class="filter-option">
                        <input type="checkbox" id="filter-pending">
                        <span>In attesa</span>
                    </label>
                    <label class="filter-option">
                        <input type="checkbox" id="filter-show-shifts">
                        <span>Mostra turni</span>
                    </label>
                </div>
            </div>
        `;

        // REMOVED: renderMiniCalendar() and updateCalendarList() calls
        this.renderUpcomingEvents();
        this.bindFilterEvents();
    }

    // CALENDAR OPTIMIZATION: Commented out Mini Calendar rendering
    // Mini Calendar not needed - main calendar always visible
    /* renderMiniCalendar() {
        const container = document.getElementById('mini-calendar');
        const currentDate = new Date();
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();

        let html = '<div class="mini-calendar-grid">';

        // Day headers
        const dayNames = ['D', 'L', 'M', 'M', 'G', 'V', 'S'];
        dayNames.forEach(day => {
            html += `<div class="mini-day-header">${day}</div>`;
        });

        // Days
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        // Empty cells
        for (let i = 0; i < firstDay; i++) {
            html += '<div class="mini-day empty"></div>';
        }

        // Days of month
        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            const isToday = this.isToday(date);
            const dateStr = cnxLocalDateKey(date);

            html += `
                <div class="mini-day ${isToday ? 'today' : ''}"
                     data-date="${dateStr}"
                     onclick="window.calendar.components.sidebar.selectDate('${dateStr}')">
                    ${day}
                </div>
            `;
        }

        html += '</div>';
        container.innerHTML = html;
    } */

    // CALENDAR OPTIMIZATION: Commented out Calendar List rendering
    // Calendar filtering handled by main API, list not needed
    /* updateCalendarList() {
        const container = document.getElementById('calendar-list');
        const { calendars, selectedCalendars } = this.app.state;

        container.innerHTML = calendars.map(cal => `
            <label class="calendar-item">
                <input type="checkbox"
                       value="${cal.id}"
                       ${selectedCalendars.has(cal.id) ? 'checked' : ''}
                       onchange="window.calendar.components.sidebar.toggleCalendar(${cal.id})">
                <span class="calendar-color" style="background-color: ${cal.color}"></span>
                <span class="calendar-name">${cal.name}</span>
            </label>
        `).join('');
    } */

    renderUpcomingEvents() {
        const container = document.getElementById('upcoming-events');
        const now = new Date();
        // BUG-112 FIX: Defensive check for undefined events
        const events = this.app.state.events || [];
        const upcoming = events
            .filter(e => e.start > now)
            .sort((a, b) => a.start - b.start)
            .slice(0, 5);

        if (upcoming.length === 0) {
            container.innerHTML = '<div class="empty-state">Nessun evento imminente</div>';
            return;
        }

        container.innerHTML = upcoming.map(event => `
            <div class="upcoming-event" onclick="window.calendar.components.eventModal.show(${JSON.stringify(event).replace(/"/g, '&quot;')})">
                <div class="upcoming-date">
                    ${event.start.toLocaleDateString(this.app.config.locale, {
                        month: 'short',
                        day: 'numeric'
                    })}
                </div>
                <div class="upcoming-details">
                    <div class="upcoming-title">${event.title}</div>
                    <div class="upcoming-time">
                        ${event.allDay ? 'Tutto il giorno' : this.app.formatTime(event.start)}
                    </div>
                </div>
            </div>
        `).join('');
    }

    bindFilterEvents() {
        document.getElementById('filter-my-events').addEventListener('change', (e) => {
            if (e.target.checked) {
                this.app.state.filters.myEvents = true;
            } else {
                delete this.app.state.filters.myEvents;
            }
            this.app.loadEvents();
        });

        document.getElementById('filter-pending').addEventListener('change', (e) => {
            if (e.target.checked) {
                this.app.state.filters.status = 'pending';
            } else {
                delete this.app.state.filters.status;
            }
            this.app.loadEvents();
        });

        document.getElementById('filter-show-shifts').addEventListener('change', async (e) => {
            if (e.target.checked) {
                this.app.state.filters.showShifts = true;
                await this.app.loadShifts();
            } else {
                delete this.app.state.filters.showShifts;
                this.app.state.shifts = [];
            }
            this.app.components.view.render();
        });
    }

    toggleCalendar(calendarId) {
        if (this.app.state.selectedCalendars.has(calendarId)) {
            this.app.state.selectedCalendars.delete(calendarId);
        } else {
            this.app.state.selectedCalendars.add(calendarId);
        }
        this.app.loadEvents();
    }

    selectDate(dateStr) {
        this.app.state.currentDate = new Date(dateStr);
        this.app.state.currentView = 'day';
        this.app.components.view.render();
        this.app.loadEvents();
    }

    toggle() {
        this.isCollapsed = !this.isCollapsed;
        this.container.classList.toggle('collapsed', this.isCollapsed);
    }

    isToday(date) {
        const today = new Date();
        return date.getDate() === today.getDate() &&
               date.getMonth() === today.getMonth() &&
               date.getFullYear() === today.getFullYear();
    }
}

class ContextMenu {
    constructor(app) {
        this.app = app;
        this.menu = document.getElementById('context-menu');
        this.targetEvent = null;
    }

    show(e) {
        const eventEl = e.target.closest('.calendar-event');
        if (!eventEl) return;

        const rawId = eventEl.dataset.eventId;
        // BUG-112 FIX: Defensive check for undefined events
        const events = this.app.state.events || [];
        this.targetEvent = events.find(ev => String(ev.id) === String(rawId));

        if (!this.targetEvent) return;

        this.render();

        // Position menu
        this.menu.style.left = `${e.clientX}px`;
        this.menu.style.top = `${e.clientY}px`;
        this.menu.classList.add('show');

        // Close on click outside
        document.addEventListener('click', this.handleOutsideClick);
    }

    hide() {
        this.menu.classList.remove('show');
        this.targetEvent = null;
        document.removeEventListener('click', this.handleOutsideClick);
    }

    handleOutsideClick = (e) => {
        if (!this.menu.contains(e.target)) {
            this.hide();
        }
    }

    render() {
        this.menu.innerHTML = `
            <div class="context-menu-item" onclick="window.calendar.components.eventModal.show(window.calendar.components.contextMenu.targetEvent)">
                ✏️ Modifica
            </div>
            <div class="context-menu-item" onclick="window.calendar.components.contextMenu.duplicate()">
                📋 Duplica
            </div>
            <div class="context-menu-item" onclick="window.calendar.components.contextMenu.viewDetails()">
                👁 Visualizza dettagli
            </div>
            <div class="context-menu-separator"></div>
            <div class="context-menu-item context-menu-danger" onclick="window.calendar.components.contextMenu.delete()">
                🗑 Elimina
            </div>
        `;
    }

    async duplicate() {
        await this.app.duplicateEvent(this.targetEvent);
        this.hide();
    }

    viewDetails() {
        this.app.components.eventModal.show(this.targetEvent);
        this.hide();
    }

    async delete() {
        await this.app.deleteEvent(this.targetEvent);
        this.hide();
    }
}

// Utility functions
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Initialize calendar when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Check if calendar container exists
    const container = document.getElementById('calendar-app');
    if (container) {
        window.calendar = new CalendarApp('calendar-app', {
            locale: 'it-IT',
            firstDayOfWeek: 1,
            defaultView: 'month',
            dragEnabled: true,
            resizeEnabled: true,
            realtimeEnabled: false // Enable if WebSocket server is available
        });
    }
});