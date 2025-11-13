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
            perPage: 50,
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
            // Load data in parallel
            await Promise.all([
                this.loadStats(),
                this.loadLogs(),
                this.loadUsers()
            ]);

            // Attach event listeners
            this.attachEventListeners();

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
        const pagination = document.querySelector('.pagination');
        if (!pagination) return;

        const { currentPage, totalPages } = this.state;

        let html = `
            <button class="pagination-btn" ${currentPage === 1 ? 'disabled' : ''} onclick="auditLogManager.changePage(${currentPage - 1})">
                <i class="icon icon--chevron-left"></i>
            </button>
        `;

        // Page numbers (simple: show current +/- 2)
        const start = Math.max(1, currentPage - 2);
        const end = Math.min(totalPages, currentPage + 2);

        for (let i = start; i <= end; i++) {
            html += `
                <button class="pagination-btn ${i === currentPage ? 'active' : ''}"
                        onclick="auditLogManager.changePage(${i})">
                    ${i}
                </button>
            `;
        }

        if (end < totalPages) {
            html += `<span style="color: var(--color-gray-500)">...</span>`;
            html += `<button class="pagination-btn" onclick="auditLogManager.changePage(${totalPages})">${totalPages}</button>`;
        }

        html += `
            <button class="pagination-btn" ${currentPage === totalPages ? 'disabled' : ''} onclick="auditLogManager.changePage(${currentPage + 1})">
                <i class="icon icon--chevron-right"></i>
            </button>
        `;

        pagination.innerHTML = html;
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

        // Parse JSON fields
        let oldValues = 'N/A';
        let newValues = 'N/A';
        let metadata = 'N/A';

        try {
            if (log.old_values) {
                const parsed = typeof log.old_values === 'string' ? JSON.parse(log.old_values) : log.old_values;
                oldValues = `<pre>${JSON.stringify(parsed, null, 2)}</pre>`;
            }
        } catch (e) {
            oldValues = this.escapeHtml(log.old_values || 'N/A');
        }

        try {
            if (log.new_values) {
                const parsed = typeof log.new_values === 'string' ? JSON.parse(log.new_values) : log.new_values;
                newValues = `<pre>${JSON.stringify(parsed, null, 2)}</pre>`;
            }
        } catch (e) {
            newValues = this.escapeHtml(log.new_values || 'N/A');
        }

        try {
            if (log.metadata) {
                const parsed = typeof log.metadata === 'string' ? JSON.parse(log.metadata) : log.metadata;
                metadata = `<pre>${JSON.stringify(parsed, null, 2)}</pre>`;
            }
        } catch (e) {
            metadata = this.escapeHtml(log.metadata || 'N/A');
        }

        content.innerHTML = `
            <div style="display: grid; gap: 16px;">
                <div>
                    <strong>ID:</strong> ${log.id}
                </div>
                <div>
                    <strong>Data/Ora:</strong> ${this.formatTimestamp(log.created_at)}
                </div>
                <div>
                    <strong>Utente:</strong> ${this.escapeHtml(log.user_name || 'Sistema')}
                </div>
                <div>
                    <strong>Azione:</strong> ${this.renderActionBadge(log.action)}
                </div>
                <div>
                    <strong>Entità:</strong> ${this.escapeHtml(log.entity_type)} #${log.entity_id}
                </div>
                <div>
                    <strong>Descrizione:</strong> ${this.escapeHtml(log.description || 'N/A')}
                </div>
                <div>
                    <strong>IP Address:</strong> ${this.escapeHtml(log.ip_address || 'N/A')}
                </div>
                <div>
                    <strong>Severità:</strong> ${this.renderSeverityBadge(log.severity)}
                </div>
                <div>
                    <strong>Valori Precedenti:</strong>
                    <div style="background: #f9fafb; padding: 12px; border-radius: 4px; margin-top: 8px;">
                        ${oldValues}
                    </div>
                </div>
                <div>
                    <strong>Nuovi Valori:</strong>
                    <div style="background: #f9fafb; padding: 12px; border-radius: 4px; margin-top: 8px;">
                        ${newValues}
                    </div>
                </div>
                <div>
                    <strong>Metadata:</strong>
                    <div style="background: #f9fafb; padding: 12px; border-radius: 4px; margin-top: 8px;">
                        ${metadata}
                    </div>
                </div>
            </div>
        `;

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
        // Filter button
        const applyBtn = document.querySelector('.btn.btn--primary');
        if (applyBtn && applyBtn.textContent.includes('Applica')) {
            applyBtn.addEventListener('click', () => this.applyFilters());
        }

        // Reset button
        const resetBtn = document.querySelector('.btn.btn--secondary');
        if (resetBtn && resetBtn.textContent.includes('Reset')) {
            resetBtn.addEventListener('click', () => this.resetFilters());
        }
    }

    applyFilters() {
        // Get filter values
        const dateFrom = document.querySelector('input[type="datetime-local"]')?.value;
        const dateTo = document.querySelectorAll('input[type="datetime-local"]')[1]?.value;

        this.state.filters.date_from = dateFrom || null;
        this.state.filters.date_to = dateTo || null;

        // Reset to page 1
        this.state.currentPage = 1;

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

        // Clear filter inputs
        document.querySelectorAll('input[type="datetime-local"]').forEach(input => input.value = '');
        document.querySelectorAll('select.form-control').forEach(select => select.selectedIndex = 0);

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
});
