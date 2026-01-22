/**
 * Dashboard Manager
 *
 * Handles dynamic dashboard functionality with API integration
 * Implements CSRF protection pattern (BUG-011, BUG-072)
 *
 * @package CollaboraNexio
 * @version 1.0.0
 * @since 2025-11-16
 */

class DashboardManager {
    constructor() {
        this.config = {
            apiBase: '/CollaboraNexio/api/dashboard/',
            statsApi: 'stats.php',
            activityApi: 'recent_activity.php',
            projectsApi: 'active_projects.php',
            documentsApi: 'recent_documents.php',  // NEW
            eventsApi: 'upcoming_events.php',      // NEW
            ticketsApi: 'recent_tickets.php',      // NEW
            refreshInterval: 60000, // 60 seconds
            tenant_id: null,
            period: '30d'
        };

        this.state = {
            stats: null,
            activities: null,
            projects: null,
            documents: null,    // NEW
            events: null,       // NEW
            tickets: null,      // NEW
            loading: false,
            lastUpdate: null
        };

        this.refreshTimer = null;
        this.clockTimer = null;
        this.init();
    }

    /**
     * Initialize dashboard
     */
    init() {
        console.log('[DashboardManager] Initializing dashboard');
        this.bindEvents();
        this.startClock();
        this.loadAllData();
        this.startAutoRefresh();
    }

    startClock() {
        const timeEl = document.getElementById('dashboardClockTime');
        const dateEl = document.getElementById('dashboardClockDate');
        if (!timeEl || !dateEl) return;

        const pad2 = (n) => String(n).padStart(2, '0');
        const months = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
        const days = ['Domenica','Lunedì','Martedì','Mercoledì','Giovedì','Venerdì','Sabato'];

        const tick = () => {
            const now = new Date();
            timeEl.textContent = `${pad2(now.getHours())}:${pad2(now.getMinutes())}:${pad2(now.getSeconds())}`;
            dateEl.textContent = `${days[now.getDay()]} ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`;
        };

        tick();
        if (this.clockTimer) clearInterval(this.clockTimer);
        this.clockTimer = setInterval(tick, 1000);
    }

    /**
     * Get CSRF token from meta tag (BUG-011 pattern)
     * @returns {string} CSRF token
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Get current tenant ID from company filter or null
     * @returns {number|null} Tenant ID
     */
    getCurrentTenantId() {
        // New Company Filter (checkbox dropdown) exposes tenant via hidden inputs (dashboard.php/calendar.php pattern)
        const hiddenTenantId = document.getElementById('currentTenantId')?.value;
        if (hiddenTenantId) {
            const t = parseInt(String(hiddenTenantId), 10);
            if (Number.isFinite(t) && t > 0) return t;
        }

        // Check if company filter dropdown exists
        const companyFilter = document.getElementById('companyFilter');
        if (companyFilter && companyFilter.value) {
            return parseInt(companyFilter.value);
        }
        return this.config.tenant_id;
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Company filter change event
        const companyFilter = document.getElementById('companyFilter');
        if (companyFilter) {
            companyFilter.addEventListener('change', (e) => {
                const tenantId = e.target.value ? parseInt(e.target.value) : null;
                console.log('[DashboardManager] Company filter changed:', tenantId);
                this.setTenantFilter(tenantId);
            });
        }

        // Period selector (if exists)
        const periodSelector = document.getElementById('periodSelector');
        if (periodSelector) {
            periodSelector.addEventListener('change', (e) => {
                this.config.period = e.target.value;
                this.loadAllData();
            });
        }

        // Refresh button (if exists)
        const refreshBtn = document.getElementById('refreshDashboard');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                this.loadAllData();
            });
        }
    }

    /**
     * Set tenant filter and reload data
     * @param {number|null} tenantId
     */
    setTenantFilter(tenantId) {
        console.log('[DashboardManager] Setting tenant filter:', tenantId);
        this.config.tenant_id = tenantId;
        this.loadAllData();
    }

    /**
     * Load all dashboard data
     */
    async loadAllData() {
        if (this.state.loading) {
            console.log('[DashboardManager] Already loading, skipping');
            return;
        }

        this.state.loading = true;
        this.showLoadingState();

        try {
            // Load all data in parallel for better performance
            const [stats, activities, documents, events, tickets, myShifts, myTasks] = await Promise.all([
                this.loadStats(),
                this.loadActivities(),
                this.loadDocuments(),
                this.loadEvents(),
                this.loadTickets(),
                this.loadMyShiftsPreview(),
                this.loadMyTasksProgress()
            ]);

            // Update state
            this.state.stats = stats;
            this.state.activities = activities;
            this.state.documents = documents;  // NEW
            this.state.events = events;        // NEW
            this.state.tickets = tickets;      // NEW
            this.state.myShifts = myShifts;
            this.state.myTasks = myTasks;
            this.state.lastUpdate = new Date();

            // Render all sections
            this.renderStats(stats);
            this.renderActivities(activities);
            this.renderDocuments(documents);  // NEW
            this.renderEvents(events);        // NEW
            this.renderTickets(tickets);      // NEW
            this.renderMyShiftsPreview(myShifts);
            this.renderMyTasksProgress(myTasks);

            console.log('[DashboardManager] All data loaded successfully');
        } catch (error) {
            console.error('[DashboardManager] Error loading dashboard data:', error);
            this.showToast('Errore durante il caricamento dei dati', 'error');
        } finally {
            this.state.loading = false;
            this.hideLoadingState();
        }
    }

    /**
     * Load next shifts for current user (preview)
     */
    async loadMyShiftsPreview() {
        try {
            const now = new Date();
            const end = new Date();
            end.setDate(end.getDate() + 30);

            const fmt = (d) => {
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                return `${y}-${m}-${dd}`;
            };

            const params = new URLSearchParams();
            params.append('start_date', fmt(now));
            params.append('end_date', fmt(end));
            // Force "only my shifts" (server-side enforced)
            params.append('mine', '1');

            // When company filter is set to a single tenant, pass it along.
            const tenantId = this.getCurrentTenantId();
            if (tenantId) params.append('tenant_id', String(tenantId));

            const url = `/CollaboraNexio/api/shifts/list.php?${params.toString()}`;
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': this.getCsrfToken() }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Errore sconosciuto');
            }

            const shifts = (data.data && Array.isArray(data.data.shifts)) ? data.data.shifts : [];
            return shifts.slice(0, 5);
        } catch (e) {
            console.warn('[DashboardManager] loadMyShiftsPreview failed:', e?.message || e);
            return [];
        }
    }

    renderMyShiftsPreview(shifts) {
        const countEl = document.getElementById('dashboardMyShiftsCount');
        const listEl = document.getElementById('dashboardMyShiftsList');
        if (!countEl || !listEl) return;

        const items = Array.isArray(shifts) ? shifts : [];
        countEl.textContent = String(items.length);

        if (items.length === 0) {
            listEl.innerHTML = `<li class="dash-mini-item"><div class="text-muted">Nessun turno nei prossimi 30 giorni</div></li>`;
            return;
        }

        const fmtDate = (iso) => {
            try {
                const d = new Date(iso + 'T00:00:00');
                return d.toLocaleDateString('it-IT', { weekday: 'short', day: '2-digit', month: 'short' });
            } catch (_) {
                return iso;
            }
        };

        const fmtTime = (t) => (t || '').toString().slice(0, 5);

        listEl.innerHTML = items.map(s => {
            const title = `${this.escapeHtml(s.shift_name || s.shift_code || 'Turno')}`;
            const sub = `${this.escapeHtml(fmtDate(s.shift_date))} · ${this.escapeHtml(fmtTime(s.start_time))}-${this.escapeHtml(fmtTime(s.end_time))}`;
            const right = this.escapeHtml((s.status_label || s.status || '').toString());
            return `
                <li class="dash-mini-item">
                    <div class="dash-mini-left">
                        <div class="dash-mini-title">${title}</div>
                        <div class="dash-mini-sub">${sub}</div>
                    </div>
                    <div class="dash-mini-right">${right}</div>
                </li>
            `;
        }).join('');
    }

    /**
     * Load task progress for tasks assigned to current user
     */
    async loadMyTasksProgress() {
        try {
            const myId = parseInt(document.getElementById('currentUserId')?.value || '0', 10);
            if (!myId) return { total: 0, done: 0, overdue: 0 };

            const params = new URLSearchParams();
            params.append('assigned_to', String(myId));
            params.append('limit', '200');
            params.append('page', '1');
            params.append('sort_by', 'due_date');
            params.append('sort_order', 'ASC');

            const tenantId = this.getCurrentTenantId();
            if (tenantId) params.append('tenant_id', String(tenantId));

            const url = `/CollaboraNexio/api/tasks/list.php?${params.toString()}`;
            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': this.getCsrfToken() }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'Errore sconosciuto');
            }

            const tasks = (data.data && Array.isArray(data.data.tasks)) ? data.data.tasks : [];
            const active = tasks.filter(t => !['cancelled'].includes((t.status || '').toString()));
            const done = active.filter(t => (t.status || '') === 'done').length;
            const overdue = active.filter(t => (t.is_overdue === 1 || t.is_overdue === true)).length;
            return { total: active.length, done, overdue };
        } catch (e) {
            console.warn('[DashboardManager] loadMyTasksProgress failed:', e?.message || e);
            return { total: 0, done: 0, overdue: 0, error: true };
        }
    }

    renderMyTasksProgress(summary) {
        const countEl = document.getElementById('dashboardTasksAssignedCount');
        const fillEl = document.getElementById('dashboardTasksProgressFill');
        const textEl = document.getElementById('dashboardTasksProgressText');
        const overduePill = document.getElementById('dashboardTasksOverduePill');
        if (!countEl || !fillEl || !textEl || !overduePill) return;

        const total = parseInt(summary?.total || 0, 10) || 0;
        const done = parseInt(summary?.done || 0, 10) || 0;
        const overdue = parseInt(summary?.overdue || 0, 10) || 0;

        countEl.textContent = String(total);

        if (total <= 0) {
            fillEl.style.width = '0%';
            textEl.textContent = 'Nessun task assegnato';
            overduePill.style.display = 'none';
            return;
        }

        const pct = Math.max(0, Math.min(100, Math.round((done / total) * 100)));
        fillEl.style.width = `${pct}%`;
        textEl.textContent = `${done}/${total} completati (${pct}%)`;

        overduePill.textContent = `⏰ ${overdue} scaduti`;
        overduePill.className = `dash-pill ${overdue > 0 ? 'danger' : 'warn'}`;
        overduePill.style.display = 'inline-flex';
    }

    /**
     * Load dashboard statistics
     * @returns {Promise<Object>}
     */
    async loadStats() {
        try {
            const params = new URLSearchParams();
            const tenantId = this.getCurrentTenantId();
            if (tenantId) {
                params.append('tenant_id', tenantId);
            }
            if (this.config.period) {
                params.append('period', this.config.period);
            }

            const url = `${this.config.apiBase}stats.php${params.toString() ? '?' + params.toString() : ''}`;

            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken() // CRITICAL: Include CSRF token
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Errore sconosciuto');
            }

            return data.data;
        } catch (error) {
            console.error('[DashboardManager] Error loading stats:', error);
            throw error;
        }
    }

    /**
     * Load recent activities
     * @returns {Promise<Object>}
     */
    async loadActivities() {
        try {
            const params = new URLSearchParams();
            const tenantId = this.getCurrentTenantId();
            if (tenantId) {
                params.append('tenant_id', tenantId);
            }
            params.append('limit', '10');

            const url = `${this.config.apiBase}recent_activity.php${params.toString() ? '?' + params.toString() : ''}`;

            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken() // CRITICAL: Include CSRF token
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Errore sconosciuto');
            }

            return data.data;
        } catch (error) {
            console.error('[DashboardManager] Error loading activities:', error);
            throw error;
        }
    }

    /**
     * Load active projects
     * @returns {Promise<Object>}
     */
    async loadProjects() {
        try {
            return { projects: [] }; // Projects disabled
        } catch (error) {
            console.error('[DashboardManager] Error loading projects:', error);
            return { projects: [] };
        }
    }

    /**
     * Load recent documents from API
     * @returns {Promise<Array>}
     */
    async loadDocuments() {
        try {
            const params = new URLSearchParams();
            const tenantId = this.getCurrentTenantId();
            if (tenantId) {
                params.append('tenant_id', tenantId);
            }

            const url = `${this.config.apiBase}${this.config.documentsApi}${params.toString() ? '?' + params.toString() : ''}`;

            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken() // CRITICAL: Include CSRF token
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.data) {
                const documents = result.data.documents || [];
                console.log('[DashboardManager] Documents loaded:', documents.length);
                return documents;
            }

            return [];
        } catch (error) {
            console.error('[DashboardManager] Error loading documents:', error);
            return [];
        }
    }

    /**
     * Load upcoming events from API
     * @returns {Promise<Array>}
     */
    async loadEvents() {
        try {
            // IMPORTANT: Dashboard mini calendar must match calendar.php events 1:1.
            // calendar.php loads via api/events.php with month view bounds + tenant filtering (including multi-tenant selection).

            const normalizeDateTime = (value) => {
                if (!value) return '';
                let s = String(value);
                if (s.includes(' ') && !s.includes('T')) s = s.replace(' ', 'T');
                return s;
            };

            const localDateKey = (value) => {
                const d = value instanceof Date ? value : new Date(value);
                if (Number.isNaN(d.getTime())) return '';
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                return `${y}-${m}-${dd}`;
            };

            const daysUntil = (dateValue) => {
                const d = dateValue instanceof Date ? dateValue : new Date(dateValue);
                if (Number.isNaN(d.getTime())) return 0;
                const eventDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
                const today = new Date();
                const todayDay = new Date(today.getFullYear(), today.getMonth(), today.getDate());
                return Math.floor((eventDay.getTime() - todayDay.getTime()) / 86400000);
            };

            const badgeClassFromDays = (du) => {
                if (du < 0) return 'badge-secondary';
                if (du === 0) return 'badge-danger';
                if (du <= 2) return 'badge-warning';
                return 'badge-info';
            };

            const iconFromTitle = (title) => {
                const t = String(title || '').toLowerCase();
                if (t.includes('riunione') || t.includes('meeting')) return 'meeting';
                if (t.includes('deadline') || t.includes('scadenza')) return 'deadline';
                if (t.includes('workshop') || t.includes('formazione')) return 'workshop';
                if (t.includes('chiamata') || t.includes('call')) return 'call';
                return 'calendar';
            };

            const fmtDateLabel = (start, allDay) => {
                try {
                    const d = start instanceof Date ? start : new Date(start);
                    if (Number.isNaN(d.getTime())) return '';
                    const datePart = d.toLocaleDateString('it-IT', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
                    if (allDay) return datePart;
                    const timePart = d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
                    return `${datePart} • ${timePart}`;
                } catch (_) {
                    return '';
                }
            };

            const toDashboardEvent = (ev) => {
                const title = String(ev?.title ?? ev?.name ?? '');
                const startStr = normalizeDateTime(ev?.start_datetime ?? ev?.start_date ?? ev?.start ?? ev?.startDate ?? '');
                if (!startStr) return null;
                const endStr = normalizeDateTime(ev?.end_datetime ?? ev?.end_date ?? ev?.end ?? ev?.endDate ?? startStr);
                const allDay = (ev?.all_day === 1 || ev?.all_day === true || ev?.allDay === true);

                const start = new Date(startStr);
                if (Number.isNaN(start.getTime())) return null;

                const du = daysUntil(start);
                return {
                    title,
                    start_date: startStr,
                    end_date: endStr || null,
                    all_day: allDay,
                    days_until: du,
                    date_label: fmtDateLabel(start, allDay),
                    urgency_badge: badgeClassFromDays(du),
                    icon: iconFromTitle(title),
                    _date_key: localDateKey(start)
                };
            };

            // Same month view bounds logic as CalendarApp.getViewBounds() (calendar.js)
            const now = new Date();
            const start = new Date(now);
            const end = new Date(now);
            const firstDayOfWeek = 1; // calendar.js default
            start.setDate(1);
            start.setDate(start.getDate() - start.getDay() + firstDayOfWeek);
            end.setDate(1);
            end.setMonth(end.getMonth() + 1);
            end.setDate(end.getDate() + (6 - end.getDay() + firstDayOfWeek));
            start.setHours(0, 0, 0, 0);
            end.setHours(23, 59, 59, 999);

            // Selected tenant IDs (same as calendar.php hidden inputs)
            const currentTenantIdsRaw = document.getElementById('currentTenantIds')?.value || '';
            let tenantIds = [];
            try {
                const parsed = JSON.parse(currentTenantIdsRaw || '[]');
                if (Array.isArray(parsed)) tenantIds = parsed.map(x => parseInt(String(x), 10)).filter(n => n > 0);
            } catch (_) {
                tenantIds = [];
            }
            if (tenantIds.length === 0) {
                const tRaw = document.getElementById('currentTenantId')?.value || '';
                const t = parseInt(String(tRaw || '0'), 10) || 0;
                if (t > 0) tenantIds = [t];
            }

            const currentUserRole = (document.getElementById('currentUserRole')?.value || window.userRole || 'user').toString();
            const isPrivileged = (currentUserRole === 'super_admin' || currentUserRole === 'admin');

            const fetchForTenant = async (tenantId, { planningClientTenantId = null, asTenantId = null } = {}) => {
                const p = new URLSearchParams({
                    start: start.toISOString(),
                    end: end.toISOString(),
                    tenant_id: String(tenantId)
                });
                if (planningClientTenantId && Number.isFinite(planningClientTenantId) && planningClientTenantId > 0) {
                    p.set('planning_client_tenant_id', String(planningClientTenantId));
                }

                const url = `/CollaboraNexio/api/events.php?${p.toString()}`;
                const response = await fetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': this.getCsrfToken() }
                });
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const result = await response.json();
                const payload = result?.data?.events ?? result?.data ?? [];
                if (!Array.isArray(payload)) return [];

                const forcedTenantId = (asTenantId && Number.isFinite(asTenantId) && asTenantId > 0) ? asTenantId : null;
                return payload.map(ev => {
                    const sourceTenantId = parseInt(String(ev?.tenant_id ?? tenantId ?? 0), 10) || 0;
                    return {
                        ...ev,
                        source_tenant_id: sourceTenantId,
                        tenant_id: forcedTenantId ?? ev?.tenant_id ?? tenantId
                    };
                });
            };

            let mergedRaw = [];
            if (tenantIds.length <= 1) {
                const mainTid = tenantIds[0];
                mergedRaw = await fetchForTenant(mainTid);

                // Planning cross-tenant view (match calendar.js)
                const vendorTenantId = 28;
                if (isPrivileged && mainTid && mainTid > 0 && mainTid !== vendorTenantId) {
                    const extra = await fetchForTenant(vendorTenantId, { planningClientTenantId: mainTid, asTenantId: mainTid }).catch(() => []);
                    mergedRaw = mergedRaw.concat(extra);
                }
            } else {
                const limit = 5;
                const results = [];
                for (let i = 0; i < tenantIds.length; i += limit) {
                    const chunk = tenantIds.slice(i, i + limit);
                    // eslint-disable-next-line no-await-in-loop
                    const chunkRes = await Promise.all(chunk.map(tid => fetchForTenant(tid).catch(() => [])));
                    results.push(...chunkRes);
                }
                mergedRaw = results.flat();

                // Planning cross-tenant view for multi-company selection (match calendar.js)
                const vendorTenantId = 28;
                if (isPrivileged && !tenantIds.includes(vendorTenantId)) {
                    const extraLimit = 5;
                    const extraAll = [];
                    for (let i = 0; i < tenantIds.length; i += extraLimit) {
                        const chunk = tenantIds.slice(i, i + extraLimit);
                        // eslint-disable-next-line no-await-in-loop
                        const chunkRes = await Promise.all(
                            chunk.map(ctid => fetchForTenant(vendorTenantId, { planningClientTenantId: ctid, asTenantId: ctid }).catch(() => []))
                        );
                        extraAll.push(...chunkRes);
                    }
                    mergedRaw = mergedRaw.concat(extraAll.flat());
                }
            }

            const normalized = mergedRaw
                .map(toDashboardEvent)
                .filter(Boolean);

            // Stable ordering for rendering/dedup
            normalized.sort((a, b) => {
                const ad = new Date(a.start_date);
                const bd = new Date(b.start_date);
                return ad.getTime() - bd.getTime();
            });

            console.log('[DashboardManager] Calendar-synced events loaded:', normalized.length);
            return normalized;
        } catch (error) {
            console.error('[DashboardManager] Error loading events:', error);
            return [];
        }
    }

    /**
     * Load recent tickets from API
     * @returns {Promise<Array>}
     */
    async loadTickets() {
        try {
            const params = new URLSearchParams();
            const tenantId = this.getCurrentTenantId();
            if (tenantId) {
                params.append('tenant_id', tenantId);
            }

            const url = `${this.config.apiBase}${this.config.ticketsApi}${params.toString() ? '?' + params.toString() : ''}`;

            const response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken() // CRITICAL: Include CSRF token
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.data) {
                const tickets = result.data.tickets || [];
                console.log('[DashboardManager] Tickets loaded:', tickets.length);
                return tickets;
            }

            return [];
        } catch (error) {
            console.error('[DashboardManager] Error loading tickets:', error);
            return [];
        }
    }

    /**
     * Render statistics cards
     * @param {Object} data Stats data from API
     */
    renderStats(data) {
        if (!data || !data.stats) {
            console.warn('[DashboardManager] No stats data to render');
            return;
        }
        // Dashboard stat cards were replaced with widgets (clock/shifts/tasks).
        // Keep this function for backward compatibility with other dashboard sections.
    }

    /**
     * Render recent activities
     * @param {Object} data Activities data from API
     */
    renderActivities(data) {
        const listContainer = document.getElementById('activity-list');
        if (!listContainer) {
            console.warn('[DashboardManager] Activities list container not found');
            return;
        }

        // Clear existing content
        listContainer.innerHTML = '';

        const activities = data.activities || [];
        const groupedActivities = this.groupActivities(activities);

        if (groupedActivities.length === 0) {
            listContainer.innerHTML = '<li class="list-item"><div class="text-muted">Nessuna attività recente</div></li>';
            return;
        }

        const isSuperAdmin = (window.userRole || '').toLowerCase() === 'super_admin';

        // Render each activity
        groupedActivities.forEach(activity => {
            const listItem = document.createElement('li');
            listItem.className = 'list-item';

            const iconClass = this.mapBadgeToDot(activity.action_badge_class);
            const title = `${activity.user_name || 'Utente'} ${activity.action_label || ''}`;
            const description = activity.entity_name || activity.description || '';
            const time = activity.time_ago || '';
            const duplicateLabel = activity._count > 1
                ? `<span class="activity-dup">+${activity._count - 1} eventi simili</span>`
                : '';

            const tenantLabel = isSuperAdmin && activity.tenant_name
                ? `<span class="badge badge-blue" style="margin-left:6px;">${this.escapeHtml(activity.tenant_name)}</span>`
                : '';

            listItem.innerHTML = `
                <div class="list-icon ${iconClass}"></div>
                <div class="list-content">
                    <div class="list-title">${this.escapeHtml(title)}${tenantLabel}</div>
                    <div class="list-description">
                        ${this.escapeHtml(description)}
                        ${duplicateLabel}
                    </div>
                </div>
                <div class="list-time text-xs text-muted">${this.escapeHtml(time)}</div>
            `;

            listContainer.appendChild(listItem);
        });
    }

    /**
     * Render active projects
     * @param {Object} data Projects data from API
     */
    renderProjects(data) {
        const projectsContainer = document.querySelector('.projects-list');
        if (!projectsContainer) {
            return;
        }
        projectsContainer.innerHTML = '<div class="text-muted">Progetti disabilitati</div>';
    }

    /**
     * Render recent documents list
     * @param {Array} documents Documents data from API
     */
    renderDocuments(documents) {
        const listContainer = document.getElementById('documents-list');
        if (!listContainer) {
            console.warn('[DashboardManager] Documents list container not found');
            return;
        }

        // Clear existing content
        listContainer.innerHTML = '';

        if (!documents || documents.length === 0) {
            listContainer.innerHTML = `
                <li class="list-item" style="flex-direction: column; text-align: center;">
                    <div class="text-muted">Nessun documento recente per il tenant selezionato.</div>
                    <a class="empty-link" href="files.php">
                        Vai al File Manager
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="m12 5 7 7-7 7"></path>
                        </svg>
                    </a>
                </li>`;
            return;
        }

        const isSuperAdmin = (window.userRole || '').toLowerCase() === 'super_admin';

        // Render each document
        documents.forEach(doc => {
            const listItem = document.createElement('li');
            listItem.className = 'list-item';

            // File icon based on MIME type or file extension
            let icon = '📄'; // Default document icon
            if (doc.file_icon) {
                // Use icon from API if provided
                if (doc.file_icon.includes('pdf')) icon = '📕';
                else if (doc.file_icon.includes('word')) icon = '📘';
                else if (doc.file_icon.includes('excel')) icon = '📗';
                else if (doc.file_icon.includes('powerpoint')) icon = '📙';
                else if (doc.file_icon.includes('image')) icon = '🖼️';
                else if (doc.file_icon.includes('archive')) icon = '📦';
            }

            const tenantLabel = isSuperAdmin && doc.tenant_name
                ? `<span class="badge badge-blue" style="margin-left:6px;">${this.escapeHtml(doc.tenant_name)}</span>`
                : '';

            const uploader = this.escapeHtml(doc.uploaded_by || '');
            const sizeLabel = this.escapeHtml(doc.size_formatted || '');
            const dateLabel = this.escapeHtml(doc.uploaded_at || '');

            listItem.innerHTML = `
                <div class="list-icon bg-primary">${icon}</div>
                <div class="list-content">
                    <div class="list-title">${this.escapeHtml(doc.name)}${tenantLabel}</div>
                    <div class="list-description">${sizeLabel}${uploader ? ' - ' + uploader : ''}</div>
                </div>
                <div class="list-time text-xs text-muted">${dateLabel}</div>
            `;

            listContainer.appendChild(listItem);
        });
    }

    /**
     * Render upcoming events list
     * @param {Array} events Events data from API
     */
    renderEvents(events) {
        const listContainer = document.getElementById('events-list');
        const miniContainer = document.getElementById('events-list-mini');
        const miniCalendarEl = document.getElementById('miniCalendar');

        const allEvents = Array.isArray(events) ? events : [];
        const upcomingEvents = allEvents
            .filter(e => typeof e.days_until === 'number' ? e.days_until >= 0 : true)
            .sort((a, b) => {
                const ad = new Date(a.start_date);
                const bd = new Date(b.start_date);
                return ad.getTime() - bd.getTime();
            });

        // Helper to render into a target UL
        const renderInto = (container) => {
            if (!container) return;
            container.innerHTML = '';

            const uniqueEvents = this.deduplicateByKey(upcomingEvents || [], event =>
                `${event.title}|${event.date_label}|${event.days_until}`
            );

            if (uniqueEvents.length === 0) {
                container.innerHTML = '<li class="list-item"><div class="text-muted">Nessun evento in programma</div></li>';
                return;
            }

            uniqueEvents.slice(0, container === miniContainer ? 3 : uniqueEvents.length).forEach(event => {
                const listItem = document.createElement('li');
                listItem.className = 'list-item';

                // Urgency color based on badge class
                let iconClass = 'bg-primary';
                if (event.urgency_badge === 'badge-danger') {
                    iconClass = 'bg-error';
                } else if (event.urgency_badge === 'badge-warning') {
                    iconClass = 'bg-warning';
                }

                // Event icon based on type
                let icon = '📅'; // Default calendar icon
                if (event.icon) {
                    if (event.icon.includes('meeting')) icon = '🤝';
                    else if (event.icon.includes('deadline')) icon = '⏰';
                    else if (event.icon.includes('workshop')) icon = '🎯';
                    else if (event.icon.includes('call')) icon = '📞';
                }

                // Days until label
                let daysLabel = `${event.days_until} gg`;
                if (event.days_until === 0) {
                    daysLabel = 'Oggi';
                } else if (event.days_until === 1) {
                    daysLabel = 'Domani';
                }

                listItem.innerHTML = `
                    <div class="list-icon ${iconClass}">${icon}</div>
                    <div class="list-content">
                        <div class="list-title">${this.escapeHtml(event.title)}</div>
                        <div class="list-description">${this.escapeHtml(event.date_label)}</div>
                    </div>
                    <div class="list-time text-xs text-muted">${daysLabel}</div>
                `;

                container.appendChild(listItem);
            });
        };

        renderInto(listContainer);
        renderInto(miniContainer);
        // Mini calendar must reflect the full month grid (not just "upcoming" subset)
        this.renderMiniCalendar(allEvents, miniCalendarEl);
    }

    /**
     * Render mini calendar with dots on days having events
     * @param {Array} events
     * @param {HTMLElement} container
     */
    renderMiniCalendar(events, container) {
        if (!container) return;

        // Clear
        container.innerHTML = '';

        const today = new Date();
        const year = today.getFullYear();
        const month = today.getMonth(); // 0-based

        const normalizeDateTime = (value) => {
            if (!value) return '';
            let s = String(value);
            if (s.includes(' ') && !s.includes('T')) s = s.replace(' ', 'T');
            return s;
        };

        const localDateKey = (value) => {
            const d = value instanceof Date ? value : new Date(value);
            if (Number.isNaN(d.getTime())) return '';
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${dd}`;
        };

        // Build a set of date keys (YYYY-MM-DD) that have at least one event.
        // Mark all days spanned by multi-day events (clamped to a safe max).
        const dotKeys = new Set();
        (Array.isArray(events) ? events : []).forEach((ev) => {
            const startStr = normalizeDateTime(ev?.start_date ?? ev?.start_datetime ?? ev?.start ?? '');
            if (!startStr) return;
            const endStr = normalizeDateTime(ev?.end_date ?? ev?.end_datetime ?? ev?.end ?? startStr);
            const start = new Date(startStr);
            const end = new Date(endStr || startStr);
            if (Number.isNaN(start.getTime())) return;

            const s = new Date(start.getFullYear(), start.getMonth(), start.getDate());
            const e = (Number.isNaN(end.getTime()) ? s : new Date(end.getFullYear(), end.getMonth(), end.getDate()));
            const endSafe = (e.getTime() >= s.getTime()) ? e : s;

            const maxDays = 120;
            let cur = s;
            for (let i = 0; i < maxDays; i++) {
                dotKeys.add(localDateKey(cur));
                if (cur.getTime() >= endSafe.getTime()) break;
                cur = new Date(cur.getFullYear(), cur.getMonth(), cur.getDate() + 1);
            }
        });

        // Build calendar grid
        const firstDay = new Date(year, month, 1);
        const startWeekday = firstDay.getDay(); // 0=Sun
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const daysInPrevMonth = new Date(year, month, 0).getDate();

        const weekdays = ['DOM', 'LUN', 'MAR', 'MER', 'GIO', 'VEN', 'SAB'];

        let html = '<table>';
        html += '<thead><tr>';
        weekdays.forEach(w => { html += `<th>${w}</th>`; });
        html += '</tr></thead><tbody>';

        let dayCounter = 1;
        let nextMonthDay = 1;
        let started = false;
        const todayKey = localDateKey(today);

        for (let week = 0; week < 6; week++) {
            html += '<tr>';
            for (let dow = 0; dow < 7; dow++) {
                let content = '';
                let cls = '';
                let cellDate = null;
                if (!started && dow < startWeekday) {
                    const day = daysInPrevMonth - (startWeekday - dow - 1);
                    content = `<span class="day-number">${day}</span>`;
                    cls = 'is-other-month';
                    cellDate = new Date(year, month - 1, day);
                } else if (dayCounter > daysInMonth) {
                    content = `<span class="day-number">${nextMonthDay}</span>`;
                    cls = 'is-other-month';
                    cellDate = new Date(year, month + 1, nextMonthDay);
                    nextMonthDay++;
                } else {
                    started = true;
                    cellDate = new Date(year, month, dayCounter);
                    const isToday = (localDateKey(cellDate) === todayKey);
                    cls = isToday ? 'is-today' : '';
                    content = `<span class="day-number">${dayCounter}</span>`;
                    if (dotKeys.has(localDateKey(cellDate))) {
                        content += '<div class="dot"></div>';
                    }
                    dayCounter++;
                }
                html += `<td class="${cls}">${content}</td>`;
            }
            html += '</tr>';
            if (dayCounter > daysInMonth && nextMonthDay > 7) break;
        }
        html += '</tbody></table>';

        container.innerHTML = html;
    }

    /**
     * Render recent tickets list
     * @param {Array} tickets Tickets data from API
     */
    renderTickets(tickets) {
        const listContainer = document.getElementById('tickets-list');
        if (!listContainer) {
            console.warn('[DashboardManager] Tickets list container not found');
            return;
        }

        // Clear existing content
        listContainer.innerHTML = '';

        if (!tickets || tickets.length === 0) {
            listContainer.innerHTML = '<li class="list-item"><div class="text-muted">Nessun ticket recente</div></li>';
            return;
        }

        // Render each ticket
        tickets.forEach(ticket => {
            const listItem = document.createElement('li');
            listItem.className = 'list-item';

            // Priority color based on urgency
            let iconClass = 'bg-primary';
            if (ticket.urgency_badge === 'badge-danger') {
                iconClass = 'bg-error';
            } else if (ticket.urgency_badge === 'badge-warning') {
                iconClass = 'bg-warning';
            }

            // Ticket icon based on category
            let icon = '🎫'; // Default ticket icon
            if (ticket.icon) {
                // Use icon from API if provided
                if (ticket.icon.includes('bug')) icon = '🐛';
                else if (ticket.icon.includes('feature')) icon = '✨';
                else if (ticket.icon.includes('issue')) icon = '⚠️';
                else if (ticket.icon.includes('support')) icon = '💬';
            }

            // Build status badge HTML
            let statusBadgeClass = ticket.status_badge || 'badge-gray';
            let statusLabel = ticket.status_label || ticket.status || 'Aperto';
            const statusBadge = `<span class="badge ${statusBadgeClass}">${this.escapeHtml(statusLabel)}</span>`;

            // Build assigned info
            const assignedInfo = ticket.assigned_to ? this.escapeHtml(ticket.assigned_to) : 'Non assegnato';

            listItem.innerHTML = `
                <div class="list-icon ${iconClass}">${icon}</div>
                <div class="list-content">
                    <div class="list-title">${this.escapeHtml(ticket.subject)}</div>
                    <div class="list-description">${statusBadge} - ${assignedInfo}</div>
                </div>
                <div class="list-time text-xs text-muted">${this.escapeHtml(ticket.created_at_relative)}</div>
            `;

            listContainer.appendChild(listItem);
        });
    }

    /**
     * Aggregate repeated activities so we avoid 10 identical logout rows
     * @param {Array} activities
     * @returns {Array}
     */
    groupActivities(activities) {
        const grouped = [];
        const index = new Map();

        activities.forEach(activity => {
            const key = `${activity.action_key || activity.action_label || activity.action || ''}|${activity.entity_id || activity.entity_name || ''}`;
            if (index.has(key)) {
                const existing = index.get(key);
                existing._count += 1;
                if (!existing.time_ago && activity.time_ago) {
                    existing.time_ago = activity.time_ago;
                }
            } else {
                const clone = { ...activity, _count: 1 };
                index.set(key, clone);
                grouped.push(clone);
            }
        });

        return grouped.slice(0, 10);
    }

    /**
     * Convert API badge classes to dashboard palette
     * @param {string} apiClass
     * @returns {string}
     */
    normalizeBadgeClass(apiClass) {
        const map = {
            'badge-success': 'badge-green',
            'badge-warning': 'badge-yellow',
            'badge-danger': 'badge-red',
            'badge-secondary': 'badge-blue',
            'badge-primary': 'badge-blue',
            'badge-info': 'badge-blue',
            'badge-light': 'badge-blue'
        };
        return map[apiClass] || 'badge-blue';
    }

    /**
     * Map API badge classes to tiny dot colors used in activity/events
     * @param {string} apiClass
     * @returns {string}
     */
    mapBadgeToDot(apiClass) {
        const map = {
            'badge-success': 'bg-success',
            'badge-warning': 'bg-warning',
            'badge-danger': 'bg-error',
            'badge-primary': 'bg-primary',
            'badge-info': 'bg-primary',
            'badge-secondary': 'bg-primary'
        };
        return map[apiClass] || 'bg-primary';
    }

    /**
     * Remove duplicate objects based on callback key
     * @param {Array} items
     * @param {Function} keyFn
     * @returns {Array}
     */
    deduplicateByKey(items, keyFn) {
        const seen = new Set();
        const output = [];
        items.forEach(item => {
            const key = keyFn(item);
            if (seen.has(key)) {
                return;
            }
            seen.add(key);
            output.push(item);
        });
        return output;
    }

    /**
     * Show loading state with skeleton animation
     */
    showLoadingState() {
        // Add loading class to stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.style.opacity = '0.5';
            card.style.animation = 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite';
        });

        // Add loading state to lists
        document.querySelectorAll('.card').forEach(card => {
            card.style.opacity = '0.7';
        });
    }

    /**
     * Hide loading state
     */
    hideLoadingState() {
        // Remove loading class from stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.style.opacity = '1';
            card.style.animation = '';
        });

        // Remove loading state from lists
        document.querySelectorAll('.card').forEach(card => {
            card.style.opacity = '1';
        });
    }

    /**
     * Start auto refresh timer
     */
    startAutoRefresh() {
        // Clear existing timer if any
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
        }

        // Set new interval
        this.refreshTimer = setInterval(() => {
            console.log('[DashboardManager] Auto-refreshing dashboard data');
            this.loadAllData();
        }, this.config.refreshInterval);

        console.log('[DashboardManager] Auto-refresh started (interval: ' + this.config.refreshInterval + 'ms)');
    }

    /**
     * Stop auto refresh timer
     */
    stopAutoRefresh() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
            this.refreshTimer = null;
            console.log('[DashboardManager] Auto-refresh stopped');
        }
    }

    /**
     * Format number with thousand separator (Italian format)
     * @param {number} num Number to format
     * @returns {string} Formatted number
     */
    formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text Text to escape
     * @returns {string} Escaped text
     */
    escapeHtml(text) {
        // Handle null, undefined, or non-string values
        if (text === null || text === undefined) {
            return '';
        }
        // Convert to string if not already
        text = String(text);

        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    toTitleCase(text = '') {
        if (!text) return '';
        return text.charAt(0).toUpperCase() + text.slice(1);
    }

    /**
     * Show toast notification
     * @param {string} message Message to display
     * @param {string} type Type of toast (success, error, info, warning)
     */
    showToast(message, type = 'info') {
        // Check if toast container exists, create if not
        let toastContainer = document.getElementById('toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            toastContainer.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                display: flex;
                flex-direction: column;
                gap: 10px;
            `;
            document.body.appendChild(toastContainer);
        }

        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.style.cssText = `
            background: ${type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            font-size: 14px;
            min-width: 250px;
            animation: slideIn 0.3s ease;
        `;
        toast.textContent = message;

        // Add to container
        toastContainer.appendChild(toast);

        // Auto remove after 5 seconds
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => {
                toast.remove();
                // Remove container if empty
                if (toastContainer.children.length === 0) {
                    toastContainer.remove();
                }
            }, 300);
        }, 5000);
    }

    /**
     * Destroy dashboard manager (cleanup)
     */
    destroy() {
        console.log('[DashboardManager] Destroying dashboard manager');
        this.stopAutoRefresh();
        this.state = {
            stats: null,
            activities: null,
            projects: null,
            loading: false,
            lastUpdate: null
        };
    }
}

// Add CSS animations if not already present
if (!document.getElementById('dashboard-animations')) {
    const style = document.createElement('style');
    style.id = 'dashboard-animations';
    style.innerHTML = `
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: .5;
            }
        }
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
}

// Export for global use
window.DashboardManager = DashboardManager;