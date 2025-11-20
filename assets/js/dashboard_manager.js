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
        this.init();
    }

    /**
     * Initialize dashboard
     */
    init() {
        console.log('[DashboardManager] Initializing dashboard');
        this.bindEvents();
        this.loadAllData();
        this.startAutoRefresh();
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
            const [stats, activities, projects, documents, events, tickets] = await Promise.all([
                this.loadStats(),
                this.loadActivities(),
                this.loadProjects(),
                this.loadDocuments(),  // NEW
                this.loadEvents(),     // NEW
                this.loadTickets()     // NEW
            ]);

            // Update state
            this.state.stats = stats;
            this.state.activities = activities;
            this.state.projects = projects;
            this.state.documents = documents;  // NEW
            this.state.events = events;        // NEW
            this.state.tickets = tickets;      // NEW
            this.state.lastUpdate = new Date();

            // Render all sections
            this.renderStats(stats);
            this.renderActivities(activities);
            this.renderProjects(projects);
            this.renderDocuments(documents);  // NEW
            this.renderEvents(events);        // NEW
            this.renderTickets(tickets);      // NEW

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
            const params = new URLSearchParams();
            const tenantId = this.getCurrentTenantId();
            if (tenantId) {
                params.append('tenant_id', tenantId);
            }
            params.append('limit', '5');

            const url = `${this.config.apiBase}active_projects.php${params.toString() ? '?' + params.toString() : ''}`;

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
            console.error('[DashboardManager] Error loading projects:', error);
            throw error;
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
            const params = new URLSearchParams();
            const tenantId = this.getCurrentTenantId();
            if (tenantId) {
                params.append('tenant_id', tenantId);
            }

            const url = `${this.config.apiBase}${this.config.eventsApi}${params.toString() ? '?' + params.toString() : ''}`;

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
                const events = result.data.events || [];
                console.log('[DashboardManager] Events loaded:', events.length);
                return events;
            }

            return [];
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

        const stats = data.stats;

        // Update stat cards
        const statCards = document.querySelectorAll('.stat-card');

        // Card 1: Active Projects
        if (statCards[0]) {
            const valueElem = statCards[0].querySelector('.stat-value');
            const changeElem = statCards[0].querySelector('.stat-change');
            if (valueElem) {
                valueElem.textContent = this.formatNumber(stats.active_projects);
            }
            // Calculate change percentage (mock for now, could be from API)
            if (changeElem) {
                const previousValue = this.state.stats?.stats?.active_projects || stats.active_projects;
                const change = ((stats.active_projects - previousValue) / (previousValue || 1)) * 100;
                changeElem.textContent = change >= 0 ? `+${change.toFixed(1)}% dal mese scorso` : `${change.toFixed(1)}% dal mese scorso`;
                changeElem.className = `stat-change ${change >= 0 ? 'positive' : 'negative'}`;
            }
        }

        // Card 2: Completed Tasks
        if (statCards[1]) {
            const valueElem = statCards[1].querySelector('.stat-value');
            const changeElem = statCards[1].querySelector('.stat-change');
            if (valueElem) {
                valueElem.textContent = this.formatNumber(stats.completed_tasks);
            }
            if (changeElem && data.metadata) {
                changeElem.textContent = `${data.metadata.period_label}`;
                changeElem.className = 'stat-change positive';
            }
        }

        // Card 3: Upcoming Deadlines
        if (statCards[2]) {
            const valueElem = statCards[2].querySelector('.stat-value');
            const changeElem = statCards[2].querySelector('.stat-change');
            if (valueElem) {
                valueElem.textContent = this.formatNumber(stats.upcoming_deadlines);
            }
            if (changeElem && stats.upcoming_deadlines_detail) {
                const urgent = stats.upcoming_deadlines_detail.overdue;
                changeElem.textContent = urgent > 0 ? `${urgent} urgenti` : 'Nessuna urgenza';
                changeElem.className = `stat-change ${urgent > 0 ? 'negative' : ''}`;
            }
        }

        // Card 4: Team Members
        if (statCards[3]) {
            const valueElem = statCards[3].querySelector('.stat-value');
            const changeElem = statCards[3].querySelector('.stat-change');
            if (valueElem) {
                valueElem.textContent = this.formatNumber(stats.active_members);
            }
            // Could show online count if available from API
            if (changeElem) {
                changeElem.textContent = 'Membri attivi';
                changeElem.className = 'stat-change';
            }
        }
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

            listItem.innerHTML = `
                <div class="list-icon ${iconClass}"></div>
                <div class="list-content">
                    <div class="list-title">${this.escapeHtml(title)}</div>
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
            console.warn('[DashboardManager] Projects container not found');
            return;
        }

        // Clear existing content
        projectsContainer.innerHTML = '';

        const projects = data.projects || [];

        if (projects.length === 0) {
            projectsContainer.innerHTML = '<div class="text-muted">Nessun progetto attivo</div>';
            return;
        }

        // Render each project
        projects.forEach(project => {
            const projectItem = document.createElement('div');
            projectItem.className = 'progress-item';

            const statusLabel = project.status_badge?.label || project.status_label || this.toTitleCase(project.status || 'Attivo');
            const badgeClass = this.normalizeBadgeClass(project.status_badge?.class);
            const progressValue = typeof project.progress_percentage === 'number'
                ? Math.min(100, Math.max(0, project.progress_percentage))
                : 0;
            const completedTasks = project.tasks?.completed ?? 0;
            const totalTasks = project.tasks?.total ?? 0;
            const tasksLabel = totalTasks > 0
                ? `${completedTasks}/${totalTasks} task`
                : 'Nessun task pianificato';

            projectItem.innerHTML = `
                <div class="progress-header">
                    <span class="progress-title">${this.escapeHtml(project.name)}</span>
                    <span class="badge ${badgeClass}">${this.escapeHtml(statusLabel)}</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: ${progressValue}%;"></div>
                </div>
                <div class="text-xs text-muted mt-2">${progressValue}% Completo - ${tasksLabel}</div>
            `;

            projectsContainer.appendChild(projectItem);
        });
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

            listItem.innerHTML = `
                <div class="list-icon bg-primary">${icon}</div>
                <div class="list-content">
                    <div class="list-title">${this.escapeHtml(doc.name)}</div>
                    <div class="list-description">${this.escapeHtml(doc.size_formatted)} - ${this.escapeHtml(doc.uploaded_by)}</div>
                </div>
                <div class="list-time text-xs text-muted">${this.escapeHtml(doc.uploaded_at)}</div>
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
        if (!listContainer) {
            console.warn('[DashboardManager] Events list container not found');
            return;
        }

        // Clear existing content
        listContainer.innerHTML = '';

        const uniqueEvents = this.deduplicateByKey(events || [], event =>
            `${event.title}|${event.date_label}|${event.days_until}`
        );

        if (uniqueEvents.length === 0) {
            listContainer.innerHTML = '<li class="list-item"><div class="text-muted">Nessun evento in programma</div></li>';
            return;
        }

        // Render each event
        uniqueEvents.forEach(event => {
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
                // Use icon from API if provided
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

            listContainer.appendChild(listItem);
        });
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