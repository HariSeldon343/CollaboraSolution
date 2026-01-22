/**
 * Audit Log Manager - CollaboraNexio
 * Manages audit log display, filtering, and deletion
 */

class AuditLogManager {
    constructor() {
        this.state = {
            logs: [],
            stats: {},
            users: [],
            currentPage: 1,
            perPage: 30,
            totalPages: 1,
            filters: {
                date_from: null,
                date_to: null,
                user_id: null,
                action: null,
                severity: null
            },
            loading: false
        };

        this.apiBase = '/CollaboraNexio/api/audit_log';
        this.init();
    }

    async init() {
        console.log('[AuditLog] Initializing...');

        try {
            // Default: show ALL logs (no date filter). Day pager can be used optionally.
            this.updateDayLabel();

            // Load data in parallel
            await Promise.all([
                this.loadStats(),
                this.loadLogs(),
                this.loadUsers()
            ]);

            // Attach event listeners
            this.attachEventListeners();
            this.attachStatCardDrilldowns();

            console.log('[AuditLog] Initialization complete');
        } catch (error) {
            console.error('[AuditLog] Initialization failed:', error);
            this.showError('Errore durante il caricamento dei dati');
        }
    }

    getCsrfToken() {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!token) console.warn('[AuditLog] CSRF token not found');
        return token || '';
    }

    async loadStats() {
        console.log('[AuditLog] Loading statistics...');

        try {
            const token = this.getCsrfToken();
            const response = await fetch(`${this.apiBase}/stats.php`, {
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': token
                }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();

            if (!data.success) throw new Error(data.message || 'API error');

            this.state.stats = data.data || {};
            this.renderStats();

            console.log('[AuditLog] Statistics loaded:', this.state.stats);
        } catch (error) {
            console.error('[AuditLog] Failed to load statistics:', error);
        }
    }

    renderStats() {
        const stats = this.state.stats;

        // Update stat cards using specific IDs
        const updateStatCard = (id, value) => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value || 0;
            }
        };

        // Map API response to stat card IDs
        updateStatCard('stat-events-today', stats.events_today || stats.today_count);
        updateStatCard('stat-active-users', stats.active_users);
        updateStatCard('stat-accesses', stats.accesses_today || stats.today_actions);
        updateStatCard('stat-modifications', stats.modifications_today || stats.modifications);
        updateStatCard('stat-critical-events', stats.critical_events || stats.critical_count);
    }

    attachStatCardDrilldowns() {
        // Make stat cards clickable: clicking applies filters and shows the underlying logs in the table.
        // This is intentionally lightweight (no extra modal): the table is the drilldown.
        const map = [
            { id: 'stat-events-today', preset: 'events_today' },
            { id: 'stat-active-users', preset: 'active_users_24h' },
            { id: 'stat-accesses', preset: 'accesses_today' },
            { id: 'stat-modifications', preset: 'modifications_today' },
            { id: 'stat-critical-events', preset: 'critical_all' },
        ];

        map.forEach(({ id, preset }) => {
            const valueEl = document.getElementById(id);
            const card = valueEl ? valueEl.closest('.stat-card') : null;
            if (!card) return;

            // Avoid double-binding.
            // NOTE: audit_log.php already uses data-audit-bound="1" for styling; do not reuse that flag.
            if (card.dataset.cnxAuditBound === '1') return;
            card.dataset.cnxAuditBound = '1';

            card.style.cursor = 'pointer';
            card.title = 'Clicca per vedere i log che compongono questa metrica';

            card.addEventListener('click', () => this.applyStatPreset(preset));
        });
    }

    applyStatPreset(preset) {
        // Reset UI inputs and state first
        const dateFromEl = document.getElementById('filter-date-from');
        const dateToEl = document.getElementById('filter-date-to');
        const userEl = document.getElementById('filter-user');
        const actionEl = document.getElementById('filter-action');
        const severityEl = document.getElementById('filter-severity');

        if (dateFromEl) dateFromEl.value = '';
        if (dateToEl) dateToEl.value = '';
        if (userEl) userEl.value = '';
        if (actionEl) actionEl.value = '';
        if (severityEl) severityEl.value = '';

        this.state.filters.user_id = null;
        this.state.filters.action = null;
        this.state.filters.severity = null;
        this.state.filters.date_from = null;
        this.state.filters.date_to = null;
        this.state.currentPage = 1;

        const today = () => {
            const d = new Date();
            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd}`;
        };

        if (preset === 'events_today') {
            const day = today();
            if (dateFromEl) dateFromEl.value = day;
            if (dateToEl) dateToEl.value = day;
            this.state.filters.date_from = `${day} 00:00:00`;
            this.state.filters.date_to = `${day} 23:59:59`;
        } else if (preset === 'accesses_today') {
            const day = today();
            if (dateFromEl) dateFromEl.value = day;
            if (dateToEl) dateToEl.value = day;
            this.state.filters.date_from = `${day} 00:00:00`;
            this.state.filters.date_to = `${day} 23:59:59`;
            // Match stats.php action list
            this.state.filters.action = 'access';
        } else if (preset === 'modifications_today') {
            const day = today();
            if (dateFromEl) dateFromEl.value = day;
            if (dateToEl) dateToEl.value = day;
            this.state.filters.date_from = `${day} 00:00:00`;
            this.state.filters.date_to = `${day} 23:59:59`;
            // Match stats.php action list
            this.state.filters.action = 'create,update,delete,file_uploaded,file_deleted,file_modified';
        } else if (preset === 'critical_all') {
            this.state.filters.severity = 'critical';
            if (severityEl) severityEl.value = 'critical';
        } else if (preset === 'active_users_24h') {
            const now = new Date();
            const from = new Date(now.getTime() - (24 * 60 * 60 * 1000));
            const pad = (n) => String(n).padStart(2, '0');
            const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
            this.state.filters.date_from = fmt(from);
            this.state.filters.date_to = fmt(now);
            // No day inputs: it's last 24h, can cross midnight.
            const label = document.getElementById('audit-day-label');
            if (label) label.textContent = 'Ultime 24h';
        }

        this.updateDayLabel();
        this.loadLogs().then(() => {
            // Scroll into view for immediate feedback
            const table = document.getElementById('audit-logs-tbody');
            if (table) {
                table.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    async loadUsers() {
        console.log('[AuditLog] Loading users...');

        try {
            // Add timestamp cache-buster to force fresh fetch (BUG-040/042)
            const token = this.getCsrfToken();
            const cacheBuster = `?_=${new Date().getTime()}`;
            const response = await fetch(`/CollaboraNexio/api/users/list_managers.php${cacheBuster}`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-CSRF-Token': token,
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0'
                }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();

            if (!data.success) throw new Error(data.message || 'API error');

            this.state.users = data.data?.users || [];

            // Populate user dropdown
            const userSelect = document.getElementById('filter-user');
            if (userSelect) {
                // Clear existing options except first
                userSelect.innerHTML = '<option value="">Tutti gli utenti</option>';

                // Add user options
                this.state.users.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = `${user.name} ${user.surname} (${user.email})`;
                    userSelect.appendChild(option);
                });
            }

            console.log('[AuditLog] Users loaded:', this.state.users.length, 'users');
        } catch (error) {
            console.error('[AuditLog] Failed to load users:', error);
            // Don't show error as this is not critical
        }
    }

    async loadLogs() {
        if (this.state.loading) return;

        this.state.loading = true;
        console.log('[AuditLog] Loading logs...', {
            page: this.state.currentPage,
            filters: this.state.filters
        });

        try {
            // Build query string
            const params = new URLSearchParams({
                page: this.state.currentPage,
                per_page: this.state.perPage
            });

            // Add filters
            Object.entries(this.state.filters).forEach(([key, value]) => {
                if (value) params.append(key, value);
            });

            const token = this.getCsrfToken();
            const response = await fetch(`${this.apiBase}/list.php?${params}`, {
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': token
                }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();

            if (!data.success) throw new Error(data.message || 'API error');

            // Safe extraction with optional chaining
            this.state.logs = data.data?.logs || [];

            const pagination = data.data?.pagination || {};
            this.state.currentPage = pagination.current_page || 1;
            this.state.totalPages = pagination.total_pages || 1;
            this.state.totalRecords = pagination.total_records || 0;
            this.state.perPage = pagination.per_page || this.state.perPage;

            this.renderTable();
            this.renderPagination();

            console.log('[AuditLog] Logs loaded:', this.state.logs.length, 'logs');
        } catch (error) {
            console.error('[AuditLog] Failed to load logs:', error);
            this.showError('Errore durante il caricamento dei log');
        } finally {
            this.state.loading = false;
        }
    }

    renderTable() {
        const tbody = document.getElementById('audit-logs-tbody');
        if (!tbody) {
            console.error('[AuditLog] Table body not found');
            return;
        }

        // Clear existing rows
        tbody.innerHTML = '';

        if (this.state.logs.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;">
                        Nessun log trovato
                    </td>
                </tr>
            `;
            return;
        }

        // Render rows
        this.state.logs.forEach(log => {
            const row = document.createElement('tr');

            // Format description with truncation
            const description = log.description || `${log.entity_type} #${log.entity_id}`;
            const truncatedDesc = this.truncateText(description, 50);

            row.innerHTML = `
                <td>${this.formatTimestamp(log.created_at)}</td>
                <td>${this.escapeHtml(log.user_name || 'Sistema')}</td>
                <td>${this.renderActionBadge(log.action)}</td>
                <td><span class="description-text" title="${this.escapeHtml(description)}">${this.escapeHtml(truncatedDesc)}</span></td>
                <td><span class="ip-address">${this.escapeHtml(log.ip_address || 'N/A')}</span></td>
                <td>${this.renderSeverityBadge(log.severity)}</td>
                <td><button class="details-btn" data-log-id="${log.id}">Dettagli</button></td>
            `;

            tbody.appendChild(row);
        });

        // Attach detail button listeners
        tbody.querySelectorAll('.details-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const logId = e.target.getAttribute('data-log-id');
                this.showDetailModal(logId);
            });
        });
    }

    /**
     * Lightweight toast (reuses audit_log.php showNotification if present).
     */
    showToast(message, type = 'info') {
        try {
            if (typeof window.showNotification === 'function') {
                window.showNotification(message, type);
                return;
            }
        } catch (_) {
            // ignore
        }
        console.log(`[AuditLog][${type}]`, message);
    }

    formatTimestamp(timestamp) {
        if (!timestamp) return '<span class="timestamp">N/A</span>';

        try {
            const date = new Date(timestamp);
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const year = date.getFullYear();
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');

            // Format with separate date and time for better mobile display
            return `
                <div class="timestamp">
                    <div class="timestamp-date">${day}/${month}/${year}</div>
                    <div class="timestamp-time">${hours}:${minutes}</div>
                </div>
            `;
        } catch (e) {
            return `<span class="timestamp">${timestamp}</span>`;
        }
    }

    renderActionBadge(action) {
        const actionMap = {
            'create': 'Create',
            'update': 'Update',
            'delete': 'Delete',
            'login': 'Login',
            'logout': 'Logout',
            'access': 'Access'
        };

        const label = actionMap[action] || action || 'Unknown';
        const className = `action-badge action-${action || 'unknown'}`;

        return `<span class="${className}">${this.escapeHtml(label)}</span>`;
    }

    renderSeverityBadge(severity) {
        const severityMap = {
            'info': 'Info',
            'warning': 'Warning',
            'error': 'Error',
            'critical': 'Critico'
        };

        const label = severityMap[severity] || severity || 'Info';
        const className = `severity-badge severity-${severity || 'info'}`;

        return `<span class="${className}">${this.escapeHtml(label)}</span>`;
    }

    renderPagination() {
        const container = document.getElementById('pagination-container');
        const buttons = document.getElementById('pagination-buttons');
        const info = document.getElementById('pagination-info');

        if (!container || !buttons || !info) return;

        const { currentPage, totalPages, perPage, totalRecords } = this.state;

        if (!totalPages || totalPages <= 1) {
            container.style.display = 'none';
            buttons.innerHTML = '';
            info.textContent = '';
            return;
        }

        container.style.display = 'flex';

        const startIdx = ((currentPage - 1) * perPage) + 1;
        const endIdx = Math.min(totalRecords, currentPage * perPage);
        info.textContent = `Mostrando ${startIdx}-${endIdx} di ${totalRecords} risultati`;

        let html = '';
        html += `<button class="pagination-btn" ${currentPage === 1 ? 'disabled' : ''} onclick="auditLogManager.changePage(${currentPage - 1})">←</button>`;

        // show current +/- 2
        const start = Math.max(1, currentPage - 2);
        const end = Math.min(totalPages, currentPage + 2);
        if (start > 1) {
            html += `<button class="pagination-btn" onclick="auditLogManager.changePage(1)">1</button>`;
            if (start > 2) html += `<span style="padding:0 6px; color:#6B7280;">...</span>`;
        }
        for (let i = start; i <= end; i++) {
            html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="auditLogManager.changePage(${i})">${i}</button>`;
        }
        if (end < totalPages) {
            if (end < totalPages - 1) html += `<span style="padding:0 6px; color:#6B7280;">...</span>`;
            html += `<button class="pagination-btn" onclick="auditLogManager.changePage(${totalPages})">${totalPages}</button>`;
        }

        html += `<button class="pagination-btn" ${currentPage === totalPages ? 'disabled' : ''} onclick="auditLogManager.changePage(${currentPage + 1})">→</button>`;

        buttons.innerHTML = html;
    }

    changePage(page) {
        if (page < 1 || page > this.state.totalPages || page === this.state.currentPage) return;

        this.state.currentPage = page;
        this.loadLogs();
    }

    async showDetailModal(logId) {
        console.log('[AuditLog] Opening detail modal for log:', logId);

        try {
            const token = this.getCsrfToken();
            const response = await fetch(`${this.apiBase}/detail.php?id=${logId}`, {
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': token
                }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();

            if (!data.success) throw new Error(data.message || 'API error');

            const log = data.data?.log;
            if (!log) throw new Error('Log not found');

            this.renderDetailModal(log);
        } catch (error) {
            console.error('[AuditLog] Failed to load log detail:', error);
            alert('Errore durante il caricamento del dettaglio');
        }
    }

    renderDetailModal(log) {
        const modal = document.getElementById('audit-detail-modal');
        const content = document.getElementById('audit-detail-content');

        if (!modal || !content) {
            console.error('[AuditLog] Detail modal elements not found');
            return;
        }

        const renderJsonBox = (value) => {
            const empty = `<div class="json-view">N/A</div>`;
            if (!value) return empty;
            try {
                const parsed = typeof value === 'string' ? JSON.parse(value) : value;
                const pretty = JSON.stringify(parsed, null, 2);
                return `<pre class="json-view">${this.escapeHtml(pretty)}</pre>`;
            } catch (_) {
                return `<div class="json-view">${this.escapeHtml(String(value))}</div>`;
            }
        };

        const mask = (s, start = 6, end = 4) => {
            const str = (s ?? '').toString();
            if (!str) return 'N/A';
            if (str.length <= start + end + 3) return str;
            return `${str.slice(0, start)}…${str.slice(-end)}`;
        };

        const entityType = this.escapeHtml(log.entity_type || 'N/A');
        const rawEntityId = (log.entity_id === null || log.entity_id === undefined) ? '' : String(log.entity_id);
        const hasEntityId = rawEntityId !== '' && rawEntityId.toLowerCase() !== 'null' && rawEntityId.toLowerCase() !== 'undefined';
        const entityText = hasEntityId ? `${entityType} #${this.escapeHtml(rawEntityId)}` : entityType;
        const entityCtx = log.entity_context ? renderJsonBox(log.entity_context) : `<div class="json-view">N/A</div>`;

        const integrity = log.integrity || {};
        const integrityVerification = integrity.verification || {};
        const integrityStatus = integrityVerification.status || 'key_unconfigured';
        let integrityBadge = `<span class="integrity-badge integrity-na">Chiave non configurata</span>`;
        if (integrityStatus === 'ok') {
            integrityBadge = `<span class="integrity-badge integrity-ok">OK</span>`;
        } else if (integrityStatus === 'fail') {
            integrityBadge = `<span class="integrity-badge integrity-fail">FAIL</span>`;
        } else if (integrityStatus === 'key_unknown') {
            integrityBadge = `<span class="integrity-badge integrity-na">Chiave non disponibile</span>`;
        } else if (integrityStatus === 'unsigned') {
            integrityBadge = `<span class="integrity-badge integrity-na">Non firmato</span>`;
        } else if (integrityStatus === 'key_unconfigured') {
            integrityBadge = `<span class="integrity-badge integrity-na">Chiave non configurata</span>`;
        }

        const requestDataMaskedBox = log.request_data ? `<div class="json-view">[Nascosto]</div>` : `<div class="json-view">N/A</div>`;
        const requestDataFullBox = renderJsonBox(log.request_data_raw || log.request_data);

        const payloadForExport = {
            log: log,
            exported_at: new Date().toISOString(),
            exported_by: 'audit_log.php'
        };

        content.innerHTML = `
            <div class="audit-detail-toolbar">
                <button type="button" class="btn btn-secondary btn-sm" data-audit-copy="json">Copia JSON</button>
                <button type="button" class="btn btn-secondary btn-sm" data-audit-copy="hash">Copia hash</button>
                <button type="button" class="btn btn-secondary btn-sm" data-audit-download="json">Scarica JSON</button>
            </div>

            <div class="audit-detail-grid">
                <div class="audit-section">
                    <div class="audit-section-title">Identificazione</div>
                    <dl class="audit-kv">
                        <dt>ID log</dt><dd>${this.escapeHtml(String(log.id || 'N/A'))}</dd>
                        <dt>Data/Ora</dt><dd>${this.formatTimestamp(log.created_at)}</dd>
                        <dt>Tenant</dt><dd>${this.escapeHtml(String(log.tenant_name || log.tenant_denominazione || log.tenant_id || 'N/A'))}</dd>
                        <dt>Tenant ID</dt><dd>${this.escapeHtml(String(log.tenant_id ?? 'N/A'))}</dd>
                    </dl>
                </div>

                <div class="audit-section">
                    <div class="audit-section-title">Attore</div>
                    <dl class="audit-kv">
                        <dt>Utente</dt><dd>${this.escapeHtml(log.user_name || 'Sistema')}</dd>
                        <dt>User ID</dt><dd>${this.escapeHtml(String(log.user_id ?? 'N/A'))}</dd>
                        <dt>Email</dt><dd>${this.escapeHtml(log.user_email || 'N/A')}</dd>
                        <dt>Ruolo</dt><dd>${this.escapeHtml(log.user_role || 'N/A')}</dd>
                    </dl>
                </div>

                <div class="audit-section">
                    <div class="audit-section-title">Evento</div>
                    <dl class="audit-kv">
                        <dt>Azione</dt><dd>${this.renderActionBadge(log.action)}</dd>
                        <dt>Severità</dt><dd>${this.renderSeverityBadge(log.severity)}</dd>
                        <dt>Esito</dt><dd><span class="audit-pill">${this.escapeHtml(String(log.status || 'N/A'))}</span></dd>
                        <dt>Descrizione</dt><dd><span class="audit-detail-wrap">${this.escapeHtml(log.description || 'N/A')}</span></dd>
                    </dl>
                </div>

                <div class="audit-section">
                    <div class="audit-section-title">Target</div>
                    <dl class="audit-kv">
                        <dt>Entità</dt><dd>${entityText}</dd>
                    </dl>
                    <div class="audit-subblock">
                        <div class="audit-subtitle">Contesto Entità</div>
                        ${entityCtx}
                    </div>
                </div>

                <div class="audit-section">
                    <div class="audit-section-title">Richiesta / Contesto</div>
                    <dl class="audit-kv">
                        <dt>IP</dt><dd>${this.escapeHtml(log.ip_address || 'N/A')}</dd>
                        <dt>User-Agent</dt>
                        <dd>
                            <span class="audit-masked is-masked" data-audit-masked data-masked="true"
                                  data-full="${this.escapeHtml(String(log.user_agent || ''))}"
                                  data-masked-value="${this.escapeHtml(mask(log.user_agent, 18, 10))}">${this.escapeHtml(mask(log.user_agent, 18, 10))}</span>
                            <button type="button" class="audit-toggle" data-audit-toggle>Mostra</button>
                        </dd>
                        <dt>Session ID</dt>
                        <dd>
                            <span class="audit-masked is-masked" data-audit-masked data-masked="true"
                                  data-full="${this.escapeHtml(String(log.session_id || ''))}"
                                  data-masked-value="${this.escapeHtml(mask(log.session_id, 10, 6))}">${this.escapeHtml(mask(log.session_id, 10, 6))}</span>
                            <button type="button" class="audit-toggle" data-audit-toggle>Mostra</button>
                        </dd>
                        <dt>Metodo</dt><dd>${this.escapeHtml(log.request_method || 'N/A')}</dd>
                        <dt>URL</dt><dd><span class="audit-detail-wrap">${this.escapeHtml(log.request_url || 'N/A')}</span></dd>
                        <dt>HTTP</dt><dd>${this.escapeHtml(String(log.response_code ?? 'N/A'))}</dd>
                    </dl>

                    <div class="audit-subblock">
                        <div class="audit-subtitle">
                            Request data
                            <button type="button" class="audit-toggle" data-audit-toggle-json>Mostra</button>
                        </div>
                        <div class="audit-json-toggle" data-audit-json-toggle>
                            <div class="audit-json-masked">${requestDataMaskedBox}</div>
                            <div class="audit-json-full" hidden>${requestDataFullBox}</div>
                        </div>
                    </div>
                </div>

                <div class="audit-section">
                    <div class="audit-section-title">Performance</div>
                    <dl class="audit-kv">
                        <dt>Execution time</dt><dd>${this.escapeHtml(String(log.execution_time_ms ?? 'N/A'))} ms</dd>
                        <dt>Memory</dt><dd>${this.escapeHtml(String(log.memory_usage_kb ?? 'N/A'))} KB</dd>
                    </dl>
                </div>

                <div class="audit-section">
                    <div class="audit-section-title">Integrità (anti-manomissione)</div>
                    <dl class="audit-kv">
                        <dt>Verifica</dt><dd>${integrityBadge}</dd>
                        <dt>Algoritmo</dt><dd>${this.escapeHtml(String(integrity.algo || 'N/A'))}</dd>
                        <dt>Key ID</dt><dd>${this.escapeHtml(String(integrity.key_id || 'N/A'))}</dd>
                        <dt>Hash</dt><dd><span class="audit-detail-wrap">${this.escapeHtml(String(integrity.hash || 'N/A'))}</span></dd>
                        <dt>Prev-hash</dt><dd><span class="audit-detail-wrap">${this.escapeHtml(String(integrity.prev_hash || 'N/A'))}</span></dd>
                        <dt>Firmato il</dt><dd>${this.escapeHtml(String(integrity.signed_at || 'N/A'))}</dd>
                    </dl>
                    ${Array.isArray(integrityVerification.errors) && integrityVerification.errors.length
                        ? (() => {
                            const map = {
                                integrity_key_unconfigured: 'Chiave HMAC non configurata',
                                integrity_key_unknown: 'Chiave di firma non disponibile (rotazione/ambiente diverso)',
                                integrity_hash_missing: 'Record non firmato',
                                integrity_hash_mismatch: 'Hash non corrisponde (possibile manomissione)',
                                integrity_prev_hash_mismatch: 'Catena non coerente (prev-hash)',
                                invalid_row_identity: 'Record incompleto (tenant/id)',
                                verify_exception: 'Errore interno di verifica'
                            };
                            const human = integrityVerification.errors.map(e => map[e] || e);
                            return `<div class="audit-warning">Dettagli: ${this.escapeHtml(human.join('; '))}</div>`;
                        })()
                        : ''}
                </div>

                <div class="audit-section">
                    <div class="audit-section-title">Dati (JSON)</div>
                    <div class="audit-subblock">
                        <div class="audit-subtitle">Valori precedenti</div>
                        ${renderJsonBox(log.old_values)}
                    </div>
                    <div class="audit-subblock">
                        <div class="audit-subtitle">Nuovi valori</div>
                        ${renderJsonBox(log.new_values)}
                    </div>
                    <div class="audit-subblock">
                        <div class="audit-subtitle">Metadata</div>
                        ${renderJsonBox(log.metadata)}
                    </div>
                </div>
            </div>
        `;

        // Interactions (toggle/copy/download)
        const safeCopy = async (text) => {
            try {
                await navigator.clipboard.writeText(text);
                this.showToast('Copiato negli appunti', 'success');
            } catch (_) {
                // Fallback
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                try { document.execCommand('copy'); } catch (__) {}
                document.body.removeChild(ta);
                this.showToast('Copiato negli appunti', 'success');
            }
        };

        content.querySelectorAll('[data-audit-toggle]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const span = btn.parentElement?.querySelector('[data-audit-masked]');
                if (!span) return;
                const isMasked = (span.getAttribute('data-masked') || 'true') === 'true';
                if (isMasked) {
                    span.textContent = span.getAttribute('data-full') || 'N/A';
                    span.setAttribute('data-masked', 'false');
                    span.classList.remove('is-masked');
                    btn.textContent = 'Nascondi';
                } else {
                    span.textContent = span.getAttribute('data-masked-value') || 'N/A';
                    span.setAttribute('data-masked', 'true');
                    span.classList.add('is-masked');
                    btn.textContent = 'Mostra';
                }
            });
        });

        const jsonToggleBtn = content.querySelector('[data-audit-toggle-json]');
        const jsonToggleWrap = content.querySelector('[data-audit-json-toggle]');
        if (jsonToggleBtn && jsonToggleWrap) {
            jsonToggleBtn.addEventListener('click', () => {
                const full = jsonToggleWrap.querySelector('.audit-json-full');
                const maskedEl = jsonToggleWrap.querySelector('.audit-json-masked');
                if (!full || !maskedEl) return;
                const nowHidden = !full.hasAttribute('hidden');
                if (nowHidden) {
                    full.setAttribute('hidden', '');
                    maskedEl.removeAttribute('hidden');
                    jsonToggleBtn.textContent = 'Mostra';
                } else {
                    maskedEl.setAttribute('hidden', '');
                    full.removeAttribute('hidden');
                    jsonToggleBtn.textContent = 'Nascondi';
                }
            });
        }

        const copyJsonBtn = content.querySelector('[data-audit-copy=\"json\"]');
        if (copyJsonBtn) {
            copyJsonBtn.addEventListener('click', () => safeCopy(JSON.stringify(payloadForExport, null, 2)));
        }

        const copyHashBtn = content.querySelector('[data-audit-copy=\"hash\"]');
        if (copyHashBtn) {
            copyHashBtn.addEventListener('click', () => safeCopy(String(integrity.hash || '')));
        }

        const downloadBtn = content.querySelector('[data-audit-download=\"json\"]');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => {
                const blob = new Blob([JSON.stringify(payloadForExport, null, 2)], { type: 'application/json' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `audit_log_${log.id || 'detail'}.json`;
                a.click();
                setTimeout(() => URL.revokeObjectURL(a.href), 2000);
            });
        }

        // BUG-048: Use .active class to trigger flexbox centering
        modal.classList.add('active');
    }

    closeDetailModal() {
        const modal = document.getElementById('audit-detail-modal');
        if (modal) modal.classList.remove('active');
    }

    showDeleteModal() {
        const userRole = document.body.getAttribute('data-user-role');
        if (userRole !== 'super_admin') {
            alert('Solo i super admin possono eliminare i log');
            return;
        }

        const modal = document.getElementById('audit-delete-modal');
        if (!modal) {
            console.error('[AuditLog] Delete modal not found');
            return;
        }

        // BUG-048: Use .active class to trigger flexbox centering
        modal.classList.add('active');

        // Setup mode toggle
        const modeSelect = document.getElementById('delete-mode');
        const periodDiv = document.getElementById('delete-period');

        if (modeSelect && periodDiv) {
            modeSelect.addEventListener('change', function() {
                periodDiv.style.display = this.value === 'range' ? 'block' : 'none';
            });
        }
    }

    closeDeleteModal() {
        const modal = document.getElementById('audit-delete-modal');
        if (modal) modal.classList.remove('active');
    }

    async confirmDelete() {
        const mode = document.getElementById('delete-mode')?.value;
        const reason = document.getElementById('delete-reason')?.value;
        const startDate = document.getElementById('delete-start')?.value;
        const endDate = document.getElementById('delete-end')?.value;

        if (!reason || reason.trim().length < 10) {
            alert('Inserire una motivazione di almeno 10 caratteri');
            return;
        }

        if (mode === 'range' && (!startDate || !endDate)) {
            alert('Inserire entrambe le date per eliminazione per periodo');
            return;
        }

        if (!confirm('Sei sicuro di voler eliminare questi log? Questa azione creerà un record immutabile di eliminazione.')) {
            return;
        }

        try {
            const body = {
                mode: mode,
                reason: reason,  // Fixed: backend expects 'reason' not 'deletion_reason'
                csrf_token: this.getCsrfToken()
            };

            if (mode === 'range') {
                body.date_from = startDate;  // Fixed: backend expects 'date_from' not 'period_start'
                body.date_to = endDate;      // Fixed: backend expects 'date_to' not 'period_end'
            }

            const response = await fetch(`${this.apiBase}/delete.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin',
                body: JSON.stringify(body)
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const data = await response.json();

            if (!data.success) throw new Error(data.message || 'API error');

            alert(`${data.data.deleted_count} log eliminati con successo.\nDeletion ID: ${data.data.deletion_id}`);

            this.closeDeleteModal();
            this.loadLogs();
            this.loadStats();
        } catch (error) {
            console.error('[AuditLog] Delete failed:', error);
            alert('Errore durante l\'eliminazione: ' + error.message);
        }
    }

    attachEventListeners() {
        // The page uses inline onclick handlers (auditManager.applyFilters/resetFilters),
        // so no need to bind buttons here. We keep this for future non-inline wiring.
    }

    applyFilters() {
        const dateFromEl = document.getElementById('filter-date-from');
        const dateToEl = document.getElementById('filter-date-to');
        const userEl = document.getElementById('filter-user');
        const actionEl = document.getElementById('filter-action');
        const severityEl = document.getElementById('filter-severity');

        const dayFrom = dateFromEl ? (dateFromEl.value || '').trim() : '';
        const dayTo = dateToEl ? (dateToEl.value || '').trim() : '';

        // Convert day range to datetime range (inclusive)
        this.state.filters.date_from = dayFrom ? `${dayFrom} 00:00:00` : null;
        this.state.filters.date_to = dayTo ? `${dayTo} 23:59:59` : null;

        this.state.filters.user_id = (userEl && userEl.value) ? userEl.value : null;
        this.state.filters.action = (actionEl && actionEl.value) ? actionEl.value : null;
        this.state.filters.severity = (severityEl && severityEl.value) ? severityEl.value : null;

        this.state.currentPage = 1;
        this.updateDayLabel();
        this.loadLogs();
    }

    resetFilters() {
        this.state.filters = {
            date_from: null,
            date_to: null,
            user_id: null,
            action: null,
            severity: null
        };

        this.state.currentPage = 1;

        // Clear filter inputs (audit_log.php ids)
        const dateFromEl = document.getElementById('filter-date-from');
        const dateToEl = document.getElementById('filter-date-to');
        const userEl = document.getElementById('filter-user');
        const actionEl = document.getElementById('filter-action');
        const severityEl = document.getElementById('filter-severity');

        if (dateFromEl) dateFromEl.value = '';
        if (dateToEl) dateToEl.value = '';
        if (userEl) userEl.value = '';
        if (actionEl) actionEl.value = '';
        if (severityEl) severityEl.value = '';

        // Back to all-time by default
        this.updateDayLabel();
        this.loadLogs();
    }

    // ===== Day paging helpers =====
    ensureDefaultDayRange() {
        const fromEl = document.getElementById('filter-date-from');
        const toEl = document.getElementById('filter-date-to');
        if (!fromEl || !toEl) return;
        if (fromEl.value && toEl.value) return;
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        const day = `${yyyy}-${mm}-${dd}`;
        fromEl.value = day;
        toEl.value = day;
        this.state.filters.date_from = `${day} 00:00:00`;
        this.state.filters.date_to = `${day} 23:59:59`;
    }

    updateDayLabel() {
        const label = document.getElementById('audit-day-label');
        const fromEl = document.getElementById('filter-date-from');
        const toEl = document.getElementById('filter-date-to');
        if (!label) return;
        const f = fromEl ? fromEl.value : '';
        const t = toEl ? toEl.value : '';
        label.textContent = (f && t && f === t) ? f : ((f || t) ? `${f || '...'} → ${t || '...'}` : '-');
    }

    shiftDay(deltaDays) {
        const fromEl = document.getElementById('filter-date-from');
        const toEl = document.getElementById('filter-date-to');
        if (!fromEl || !toEl) return;
        // If no day is selected, start from today and then apply delta.
        const day = (fromEl.value || toEl.value || '').trim();
        if (!day) {
            this.ensureDefaultDayRange();
        }
        const day2 = (fromEl.value || toEl.value || '').trim();
        const d = new Date(`${day2}T00:00:00`);
        if (Number.isNaN(d.getTime())) return;
        d.setDate(d.getDate() + (parseInt(deltaDays, 10) || 0));
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const next = `${yyyy}-${mm}-${dd}`;
        fromEl.value = next;
        toEl.value = next;
        this.state.filters.date_from = `${next} 00:00:00`;
        this.state.filters.date_to = `${next} 23:59:59`;
        this.state.currentPage = 1;
        this.updateDayLabel();
        this.loadLogs();
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    truncateText(text, maxLength) {
        if (!text) return '';
        if (text.length <= maxLength) return text;
        return text.substring(0, maxLength) + '...';
    }

    showError(message) {
        console.error('[AuditLog]', message);
        // Could add toast notification here
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    console.log('[AuditLog] DOM ready, initializing manager...');
    window.auditLogManager = new AuditLogManager();
    // Backward compatibility for inline onclick handlers in audit_log.php
    try { window.auditManager = window.auditLogManager; } catch (_) {}
});
