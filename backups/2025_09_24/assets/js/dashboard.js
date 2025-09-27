/**
 * Advanced Dashboard System for CollaboraNexio
 * Complete interactive dashboard with drag & drop grid layout and widget management
 */

// Widget Base Class
class Widget {
    constructor(id, type, config = {}) {
        this.id = id;
        this.type = type;
        this.config = {
            title: config.title || 'Widget',
            refreshInterval: config.refreshInterval || 30000,
            gridPosition: config.gridPosition || { x: 0, y: 0, width: 2, height: 2 },
            settings: config.settings || {},
            ...config
        };
        this.element = null;
        this.refreshTimer = null;
        this.isFullscreen = false;
        this.isLoading = false;
    }

    render() {
        const widget = document.createElement('div');
        widget.className = 'dashboard-widget';
        widget.id = `widget-${this.id}`;
        widget.dataset.widgetId = this.id;
        widget.dataset.widgetType = this.type;
        widget.style.gridColumn = `span ${this.config.gridPosition.width}`;
        widget.style.gridRow = `span ${this.config.gridPosition.height}`;

        widget.innerHTML = `
            <div class="widget-header">
                <h3 class="widget-title">${this.config.title}</h3>
                <div class="widget-controls">
                    <button class="widget-control" data-action="refresh" title="Refresh">
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"/>
                        </svg>
                    </button>
                    <button class="widget-control" data-action="settings" title="Settings">
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z"/>
                        </svg>
                    </button>
                    <button class="widget-control" data-action="fullscreen" title="Fullscreen">
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8V4m0 0h4M3 4l4 4m14 0V4m0 0h-4m4 0l-4 4m-4 10v-4m0 4h4m-4 0l4-4m-8 4v-4m0 4H3m4 0l-4-4"/>
                        </svg>
                    </button>
                    <button class="widget-control widget-remove" data-action="remove" title="Remove">
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"/>
                        </svg>
                    </button>
                </div>
                <div class="widget-drag-handle">
                    <svg viewBox="0 0 20 20" fill="currentColor">
                        <path d="M7 2a2 2 0 11-4 0 2 2 0 014 0zM7 6a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0zM15 2a2 2 0 11-4 0 2 2 0 014 0zM15 6a2 2 0 11-4 0 2 2 0 014 0zM15 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
            </div>
            <div class="widget-body">
                <div class="widget-loading">
                    <div class="spinner"></div>
                    <span>Loading...</span>
                </div>
                <div class="widget-content"></div>
            </div>
            <div class="widget-resize-handle"></div>
        `;

        this.element = widget;
        this.bindControls();
        return widget;
    }

    bindControls() {
        if (!this.element) return;

        const controls = this.element.querySelectorAll('.widget-control');
        controls.forEach(control => {
            control.addEventListener('click', (e) => {
                e.stopPropagation();
                const action = control.dataset.action;
                this.handleControlAction(action);
            });
        });
    }

    handleControlAction(action) {
        switch(action) {
            case 'refresh':
                this.refresh();
                break;
            case 'settings':
                this.showSettings();
                break;
            case 'fullscreen':
                this.toggleFullscreen();
                break;
            case 'remove':
                this.remove();
                break;
        }
    }

    async refresh() {
        this.showLoading();
        try {
            await this.loadData();
            this.updateContent();
        } catch (error) {
            console.error('Widget refresh error:', error);
            this.showError('Failed to refresh widget');
        } finally {
            this.hideLoading();
        }
    }

    showLoading() {
        this.isLoading = true;
        if (this.element) {
            this.element.classList.add('loading');
        }
    }

    hideLoading() {
        this.isLoading = false;
        if (this.element) {
            this.element.classList.remove('loading');
        }
    }

    showError(message) {
        if (this.element) {
            const content = this.element.querySelector('.widget-content');
            content.innerHTML = `<div class="widget-error">${message}</div>`;
        }
    }

    toggleFullscreen() {
        this.isFullscreen = !this.isFullscreen;
        if (this.element) {
            this.element.classList.toggle('fullscreen', this.isFullscreen);
        }
    }

    showSettings() {
        // Override in subclasses
        window.dashboardManager.showWidgetSettings(this);
    }

    remove() {
        if (confirm('Remove this widget?')) {
            window.dashboardManager.removeWidget(this.id);
        }
    }

    startAutoRefresh() {
        if (this.config.refreshInterval > 0) {
            this.stopAutoRefresh();
            this.refreshTimer = setInterval(() => {
                this.refresh();
            }, this.config.refreshInterval);
        }
    }

    stopAutoRefresh() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        }
    }

    async loadData() {
        // Override in subclasses
    }

    updateContent() {
        // Override in subclasses
    }

    destroy() {
        this.stopAutoRefresh();
        if (this.element) {
            this.element.remove();
        }
    }
}

// Metric Card Widget
class MetricCardWidget extends Widget {
    constructor(id, config) {
        super(id, 'metric-card', config);
        this.data = null;
    }

    async loadData() {
        const response = await fetch(`/api/dashboard.php?widget=metric&metric=${this.config.settings.metric}`);
        this.data = await response.json();
    }

    updateContent() {
        if (!this.data || !this.element) return;

        const content = this.element.querySelector('.widget-content');
        const changeClass = this.data.change > 0 ? 'positive' : this.data.change < 0 ? 'negative' : '';
        const changeIcon = this.data.change > 0 ? '↑' : this.data.change < 0 ? '↓' : '→';

        content.innerHTML = `
            <div class="metric-card">
                <div class="metric-value">${this.formatValue(this.data.value)}</div>
                <div class="metric-change ${changeClass}">
                    <span class="change-icon">${changeIcon}</span>
                    <span class="change-value">${Math.abs(this.data.change)}%</span>
                </div>
                <div class="metric-label">${this.data.label || this.config.title}</div>
            </div>
        `;
    }

    formatValue(value) {
        if (typeof value === 'number') {
            return value.toLocaleString();
        }
        return value;
    }
}

// Chart Widget
class ChartWidget extends Widget {
    constructor(id, config) {
        super(id, 'chart', config);
        this.chart = null;
        this.data = null;
    }

    async loadData() {
        const response = await fetch(`/api/dashboard.php?widget=chart&type=${this.config.settings.chartType}`);
        this.data = await response.json();
    }

    updateContent() {
        if (!this.data || !this.element) return;

        const content = this.element.querySelector('.widget-content');
        content.innerHTML = '<canvas class="chart-canvas"></canvas>';

        const canvas = content.querySelector('.chart-canvas');
        const ctx = canvas.getContext('2d');

        if (this.chart) {
            this.chart.destroy();
        }

        this.chart = new Chart(ctx, {
            type: this.config.settings.chartType || 'line',
            data: this.data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: this.config.settings.showLegend !== false,
                        position: 'bottom'
                    }
                },
                scales: this.getScalesConfig()
            }
        });
    }

    getScalesConfig() {
        if (this.config.settings.chartType === 'pie' || this.config.settings.chartType === 'doughnut') {
            return {};
        }

        return {
            y: {
                beginAtZero: true,
                grid: {
                    display: true,
                    color: 'rgba(0, 0, 0, 0.05)'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        };
    }

    destroy() {
        if (this.chart) {
            this.chart.destroy();
        }
        super.destroy();
    }
}

// Activity Feed Widget
class ActivityFeedWidget extends Widget {
    constructor(id, config) {
        super(id, 'activity-feed', config);
        this.activities = [];
    }

    async loadData() {
        const response = await fetch('/api/dashboard.php?widget=activities');
        const data = await response.json();
        this.activities = data.activities || [];
    }

    updateContent() {
        if (!this.element) return;

        const content = this.element.querySelector('.widget-content');

        if (!this.activities.length) {
            content.innerHTML = '<div class="empty-state">No recent activities</div>';
            return;
        }

        content.innerHTML = `
            <div class="activity-feed">
                ${this.activities.map(activity => `
                    <div class="activity-feed-item">
                        <div class="activity-icon ${activity.type}">
                            ${this.getActivityIcon(activity.type)}
                        </div>
                        <div class="activity-feed-content">
                            <div class="activity-feed-title">${activity.title}</div>
                            <div class="activity-feed-description">${activity.description}</div>
                            <div class="activity-feed-time">${this.formatTime(activity.timestamp)}</div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    getActivityIcon(type) {
        const icons = {
            success: '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>',
            warning: '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"/></svg>',
            info: '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"/></svg>',
            error: '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/></svg>'
        };
        return icons[type] || icons.info;
    }

    formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);

        if (diff < 60) return 'Just now';
        if (diff < 3600) return `${Math.floor(diff / 60)} minutes ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)} hours ago`;
        return date.toLocaleDateString();
    }
}

// Storage Gauge Widget
class StorageGaugeWidget extends Widget {
    constructor(id, config) {
        super(id, 'storage-gauge', config);
        this.storageData = null;
    }

    async loadData() {
        const response = await fetch('/api/dashboard.php?widget=storage');
        this.storageData = await response.json();
    }

    updateContent() {
        if (!this.storageData || !this.element) return;

        const content = this.element.querySelector('.widget-content');
        const percentage = (this.storageData.used / this.storageData.total) * 100;
        const color = percentage > 90 ? '#ef4444' : percentage > 70 ? '#f59e0b' : '#10b981';

        content.innerHTML = `
            <div class="storage-gauge">
                <div class="gauge-container">
                    <svg class="gauge-svg" viewBox="0 0 200 200">
                        <circle cx="100" cy="100" r="90" fill="none" stroke="#e5e7eb" stroke-width="10"/>
                        <circle cx="100" cy="100" r="90" fill="none" stroke="${color}" stroke-width="10"
                                stroke-dasharray="${percentage * 5.65} 565"
                                stroke-dashoffset="0"
                                transform="rotate(-90 100 100)"/>
                        <text x="100" y="90" text-anchor="middle" font-size="32" font-weight="bold" fill="${color}">
                            ${percentage.toFixed(0)}%
                        </text>
                        <text x="100" y="115" text-anchor="middle" font-size="14" fill="#6b7280">
                            ${this.formatBytes(this.storageData.used)} / ${this.formatBytes(this.storageData.total)}
                        </text>
                    </svg>
                </div>
                <div class="storage-details">
                    ${this.storageData.breakdown ? this.storageData.breakdown.map(item => `
                        <div class="storage-item">
                            <span class="storage-label">${item.label}</span>
                            <span class="storage-value">${this.formatBytes(item.size)}</span>
                        </div>
                    `).join('') : ''}
                </div>
            </div>
        `;
    }

    formatBytes(bytes) {
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        if (bytes === 0) return '0 B';
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
    }
}

// Task Burndown Widget
class TaskBurndownWidget extends Widget {
    constructor(id, config) {
        super(id, 'task-burndown', config);
        this.chart = null;
        this.data = null;
    }

    async loadData() {
        const response = await fetch('/api/dashboard.php?widget=burndown');
        this.data = await response.json();
    }

    updateContent() {
        if (!this.data || !this.element) return;

        const content = this.element.querySelector('.widget-content');
        content.innerHTML = '<canvas class="chart-canvas"></canvas>';

        const canvas = content.querySelector('.chart-canvas');
        const ctx = canvas.getContext('2d');

        if (this.chart) {
            this.chart.destroy();
        }

        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: this.data.labels,
                datasets: [
                    {
                        label: 'Ideal',
                        data: this.data.ideal,
                        borderColor: '#9ca3af',
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0
                    },
                    {
                        label: 'Actual',
                        data: this.data.actual,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        fill: true,
                        tension: 0.2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Tasks Remaining'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Days'
                        }
                    }
                }
            }
        });
    }

    destroy() {
        if (this.chart) {
            this.chart.destroy();
        }
        super.destroy();
    }
}

// Calendar Mini Widget
class CalendarMiniWidget extends Widget {
    constructor(id, config) {
        super(id, 'calendar-mini', config);
        this.events = [];
        this.currentDate = new Date();
    }

    async loadData() {
        const response = await fetch('/api/dashboard.php?widget=calendar');
        const data = await response.json();
        this.events = data.events || [];
    }

    updateContent() {
        if (!this.element) return;

        const content = this.element.querySelector('.widget-content');
        content.innerHTML = `
            <div class="calendar-mini">
                <div class="calendar-header">
                    <button class="calendar-nav" data-direction="prev">‹</button>
                    <span class="calendar-month">${this.getMonthName()} ${this.currentDate.getFullYear()}</span>
                    <button class="calendar-nav" data-direction="next">›</button>
                </div>
                <div class="calendar-grid">
                    ${this.generateCalendarGrid()}
                </div>
                <div class="calendar-events">
                    <h4>Upcoming Events</h4>
                    ${this.renderUpcomingEvents()}
                </div>
            </div>
        `;

        this.bindCalendarEvents();
    }

    getMonthName() {
        const months = ['January', 'February', 'March', 'April', 'May', 'June',
                       'July', 'August', 'September', 'October', 'November', 'December'];
        return months[this.currentDate.getMonth()];
    }

    generateCalendarGrid() {
        const year = this.currentDate.getFullYear();
        const month = this.currentDate.getMonth();
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = new Date();

        let html = '<div class="calendar-weekdays">';
        ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach(day => {
            html += `<div class="calendar-weekday">${day}</div>`;
        });
        html += '</div><div class="calendar-days">';

        for (let i = 0; i < firstDay; i++) {
            html += '<div class="calendar-day empty"></div>';
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const date = new Date(year, month, day);
            const isToday = date.toDateString() === today.toDateString();
            const hasEvent = this.hasEventOnDate(date);

            html += `
                <div class="calendar-day ${isToday ? 'today' : ''} ${hasEvent ? 'has-event' : ''}">
                    ${day}
                </div>
            `;
        }

        html += '</div>';
        return html;
    }

    hasEventOnDate(date) {
        return this.events.some(event => {
            const eventDate = new Date(event.date);
            return eventDate.toDateString() === date.toDateString();
        });
    }

    renderUpcomingEvents() {
        const upcoming = this.events
            .filter(event => new Date(event.date) >= new Date())
            .slice(0, 3);

        if (!upcoming.length) {
            return '<div class="no-events">No upcoming events</div>';
        }

        return upcoming.map(event => `
            <div class="calendar-event">
                <div class="event-date">${this.formatEventDate(event.date)}</div>
                <div class="event-title">${event.title}</div>
            </div>
        `).join('');
    }

    formatEventDate(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    bindCalendarEvents() {
        const navButtons = this.element.querySelectorAll('.calendar-nav');
        navButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                const direction = e.target.dataset.direction;
                if (direction === 'prev') {
                    this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                } else {
                    this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                }
                this.updateContent();
            });
        });
    }
}

// Grid Layout Manager
class GridLayoutManager {
    constructor(container) {
        this.container = container;
        this.isDragging = false;
        this.isResizing = false;
        this.draggedWidget = null;
        this.placeholder = null;
        this.gridSize = 20; // Grid cell size in pixels
        this.init();
    }

    init() {
        this.setupGrid();
        this.bindEvents();
    }

    setupGrid() {
        this.container.style.display = 'grid';
        this.container.style.gridTemplateColumns = 'repeat(auto-fit, minmax(200px, 1fr))';
        this.container.style.gap = '1rem';
        this.container.classList.add('dashboard-grid-container');
    }

    bindEvents() {
        // Drag events
        this.container.addEventListener('mousedown', (e) => this.handleMouseDown(e));
        document.addEventListener('mousemove', (e) => this.handleMouseMove(e));
        document.addEventListener('mouseup', (e) => this.handleMouseUp(e));

        // Touch events for mobile
        this.container.addEventListener('touchstart', (e) => this.handleTouchStart(e));
        document.addEventListener('touchmove', (e) => this.handleTouchMove(e));
        document.addEventListener('touchend', (e) => this.handleTouchEnd(e));
    }

    handleMouseDown(e) {
        const dragHandle = e.target.closest('.widget-drag-handle');
        const resizeHandle = e.target.closest('.widget-resize-handle');

        if (dragHandle) {
            this.startDragging(e, dragHandle.closest('.dashboard-widget'));
        } else if (resizeHandle) {
            this.startResizing(e, resizeHandle.closest('.dashboard-widget'));
        }
    }

    handleMouseMove(e) {
        if (this.isDragging) {
            this.drag(e);
        } else if (this.isResizing) {
            this.resize(e);
        }
    }

    handleMouseUp(e) {
        if (this.isDragging) {
            this.endDragging();
        } else if (this.isResizing) {
            this.endResizing();
        }
    }

    handleTouchStart(e) {
        const touch = e.touches[0];
        const target = document.elementFromPoint(touch.clientX, touch.clientY);
        const dragHandle = target.closest('.widget-drag-handle');

        if (dragHandle) {
            e.preventDefault();
            this.startDragging(touch, dragHandle.closest('.dashboard-widget'));
        }
    }

    handleTouchMove(e) {
        if (this.isDragging) {
            e.preventDefault();
            this.drag(e.touches[0]);
        }
    }

    handleTouchEnd(e) {
        if (this.isDragging) {
            this.endDragging();
        }
    }

    startDragging(e, widget) {
        this.isDragging = true;
        this.draggedWidget = widget;

        // Create placeholder
        this.placeholder = document.createElement('div');
        this.placeholder.className = 'widget-placeholder';
        this.placeholder.style.gridColumn = widget.style.gridColumn;
        this.placeholder.style.gridRow = widget.style.gridRow;

        // Store initial positions
        this.dragStart = {
            x: e.clientX,
            y: e.clientY,
            widgetX: widget.offsetLeft,
            widgetY: widget.offsetTop
        };

        // Make widget draggable
        widget.classList.add('dragging');
        widget.style.position = 'fixed';
        widget.style.zIndex = '1000';
        widget.style.left = widget.offsetLeft + 'px';
        widget.style.top = widget.offsetTop + 'px';
        widget.style.width = widget.offsetWidth + 'px';

        // Insert placeholder
        widget.parentNode.insertBefore(this.placeholder, widget.nextSibling);
        document.body.style.cursor = 'move';
    }

    drag(e) {
        if (!this.draggedWidget) return;

        const deltaX = e.clientX - this.dragStart.x;
        const deltaY = e.clientY - this.dragStart.y;

        this.draggedWidget.style.left = (this.dragStart.widgetX + deltaX) + 'px';
        this.draggedWidget.style.top = (this.dragStart.widgetY + deltaY) + 'px';

        // Find drop target
        const target = this.findDropTarget(e.clientX, e.clientY);
        if (target && target !== this.placeholder) {
            this.swapElements(this.placeholder, target);
        }
    }

    endDragging() {
        if (!this.draggedWidget) return;

        this.isDragging = false;

        // Reset widget styles
        this.draggedWidget.classList.remove('dragging');
        this.draggedWidget.style.position = '';
        this.draggedWidget.style.zIndex = '';
        this.draggedWidget.style.left = '';
        this.draggedWidget.style.top = '';
        this.draggedWidget.style.width = '';

        // Replace placeholder with widget
        if (this.placeholder && this.placeholder.parentNode) {
            this.placeholder.parentNode.replaceChild(this.draggedWidget, this.placeholder);
        }

        // Save layout
        this.saveLayout();

        // Cleanup
        this.draggedWidget = null;
        this.placeholder = null;
        document.body.style.cursor = '';
    }

    startResizing(e, widget) {
        this.isResizing = true;
        this.resizedWidget = widget;

        this.resizeStart = {
            x: e.clientX,
            y: e.clientY,
            width: widget.offsetWidth,
            height: widget.offsetHeight
        };

        widget.classList.add('resizing');
        document.body.style.cursor = 'se-resize';
    }

    resize(e) {
        if (!this.resizedWidget) return;

        const deltaX = e.clientX - this.resizeStart.x;
        const deltaY = e.clientY - this.resizeStart.y;

        const newWidth = Math.max(200, this.resizeStart.width + deltaX);
        const newHeight = Math.max(150, this.resizeStart.height + deltaY);

        // Snap to grid
        const gridWidth = Math.round(newWidth / 200);
        const gridHeight = Math.round(newHeight / 150);

        this.resizedWidget.style.gridColumn = `span ${gridWidth}`;
        this.resizedWidget.style.gridRow = `span ${gridHeight}`;
    }

    endResizing() {
        if (!this.resizedWidget) return;

        this.isResizing = false;
        this.resizedWidget.classList.remove('resizing');

        // Save layout
        this.saveLayout();

        // Cleanup
        this.resizedWidget = null;
        document.body.style.cursor = '';
    }

    findDropTarget(x, y) {
        const widgets = this.container.querySelectorAll('.dashboard-widget:not(.dragging)');

        for (const widget of widgets) {
            const rect = widget.getBoundingClientRect();
            if (x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom) {
                return widget;
            }
        }

        return null;
    }

    swapElements(el1, el2) {
        const temp = document.createElement('div');
        el1.parentNode.insertBefore(temp, el1);
        el2.parentNode.insertBefore(el1, el2);
        temp.parentNode.insertBefore(el2, temp);
        temp.parentNode.removeChild(temp);
    }

    saveLayout() {
        const widgets = this.container.querySelectorAll('.dashboard-widget');
        const layout = [];

        widgets.forEach(widget => {
            const widgetId = widget.dataset.widgetId;
            const gridColumn = widget.style.gridColumn;
            const gridRow = widget.style.gridRow;

            layout.push({
                id: widgetId,
                position: { gridColumn, gridRow }
            });
        });

        // Save to backend
        fetch('/api/dashboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'saveLayout', layout })
        });

        // Save to localStorage as backup
        localStorage.setItem('dashboardLayout', JSON.stringify(layout));
    }

    loadLayout() {
        const savedLayout = localStorage.getItem('dashboardLayout');
        if (savedLayout) {
            return JSON.parse(savedLayout);
        }
        return null;
    }
}

// Dashboard Manager
class DashboardManager {
    constructor() {
        this.config = {
            apiBase: '/api/',
            refreshInterval: 30000,
            dateFormat: 'YYYY-MM-DD'
        };

        this.state = {
            widgets: new Map(),
            theme: localStorage.getItem('dashboardTheme') || 'light',
            dateRange: {
                start: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000),
                end: new Date()
            }
        };

        this.widgetTypes = {
            'metric-card': MetricCardWidget,
            'chart': ChartWidget,
            'activity-feed': ActivityFeedWidget,
            'storage-gauge': StorageGaugeWidget,
            'task-burndown': TaskBurndownWidget,
            'calendar-mini': CalendarMiniWidget
        };

        this.init();
    }

    init() {
        this.loadChartJS();
        this.cacheElements();
        this.bindEvents();
        this.applyTheme();
        this.loadDashboard();
        this.initializeGridLayout();
        this.startAutoRefresh();
    }

    loadChartJS() {
        if (typeof Chart === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
            script.onload = () => {
                console.log('Chart.js loaded');
            };
            document.head.appendChild(script);
        }
    }

    cacheElements() {
        this.dashboardContainer = document.getElementById('dashboardWidgets');
        this.addWidgetBtn = document.getElementById('addWidgetBtn');
        this.exportBtn = document.getElementById('exportDashboardBtn');
        this.themeToggle = document.getElementById('themeToggle');
        this.dateRangePicker = document.getElementById('dateRangePicker');
        this.refreshAllBtn = document.getElementById('refreshAllBtn');
    }

    bindEvents() {
        // Add widget button
        if (this.addWidgetBtn) {
            this.addWidgetBtn.addEventListener('click', () => this.showAddWidgetModal());
        }

        // Export button
        if (this.exportBtn) {
            this.exportBtn.addEventListener('click', () => this.exportDashboard());
        }

        // Theme toggle
        if (this.themeToggle) {
            this.themeToggle.addEventListener('click', () => this.toggleTheme());
        }

        // Date range picker
        if (this.dateRangePicker) {
            this.dateRangePicker.addEventListener('change', (e) => this.handleDateRangeChange(e));
        }

        // Refresh all button
        if (this.refreshAllBtn) {
            this.refreshAllBtn.addEventListener('click', () => this.refreshAllWidgets());
        }

        // Window resize
        window.addEventListener('resize', () => this.handleResize());

        // Visibility change
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopAutoRefresh();
            } else {
                this.startAutoRefresh();
            }
        });
    }

    initializeGridLayout() {
        if (this.dashboardContainer) {
            this.gridLayout = new GridLayoutManager(this.dashboardContainer);
        }
    }

    async loadDashboard() {
        try {
            const response = await this.apiCall('dashboard.php?action=load');

            if (response.widgets) {
                response.widgets.forEach(widgetConfig => {
                    this.addWidget(widgetConfig.type, widgetConfig);
                });
            }

            // Load saved layout
            const savedLayout = this.gridLayout?.loadLayout();
            if (savedLayout) {
                this.applyLayout(savedLayout);
            }
        } catch (error) {
            console.error('Failed to load dashboard:', error);
            this.showToast('Failed to load dashboard', 'error');
        }
    }

    addWidget(type, config = {}) {
        const WidgetClass = this.widgetTypes[type];
        if (!WidgetClass) {
            console.error(`Unknown widget type: ${type}`);
            return;
        }

        const id = config.id || `widget-${Date.now()}`;
        const widget = new WidgetClass(id, config);

        this.state.widgets.set(id, widget);

        if (this.dashboardContainer) {
            const element = widget.render();
            this.dashboardContainer.appendChild(element);
            widget.refresh();
            widget.startAutoRefresh();
        }

        return widget;
    }

    removeWidget(widgetId) {
        const widget = this.state.widgets.get(widgetId);
        if (widget) {
            widget.destroy();
            this.state.widgets.delete(widgetId);
            this.saveDashboard();
        }
    }

    showAddWidgetModal() {
        const modal = this.createModal('Add Widget', `
            <div class="widget-selector">
                <div class="widget-type" data-type="metric-card">
                    <div class="widget-type-icon">📊</div>
                    <div class="widget-type-name">Metric Card</div>
                </div>
                <div class="widget-type" data-type="chart">
                    <div class="widget-type-icon">📈</div>
                    <div class="widget-type-name">Chart</div>
                </div>
                <div class="widget-type" data-type="activity-feed">
                    <div class="widget-type-icon">📋</div>
                    <div class="widget-type-name">Activity Feed</div>
                </div>
                <div class="widget-type" data-type="storage-gauge">
                    <div class="widget-type-icon">💾</div>
                    <div class="widget-type-name">Storage Gauge</div>
                </div>
                <div class="widget-type" data-type="task-burndown">
                    <div class="widget-type-icon">📉</div>
                    <div class="widget-type-name">Task Burndown</div>
                </div>
                <div class="widget-type" data-type="calendar-mini">
                    <div class="widget-type-icon">📅</div>
                    <div class="widget-type-name">Calendar</div>
                </div>
            </div>
        `);

        modal.querySelectorAll('.widget-type').forEach(type => {
            type.addEventListener('click', () => {
                const widgetType = type.dataset.type;
                this.addWidget(widgetType, {
                    title: type.querySelector('.widget-type-name').textContent,
                    settings: this.getDefaultSettings(widgetType)
                });
                this.closeModal(modal);
                this.saveDashboard();
            });
        });
    }

    showWidgetSettings(widget) {
        const modal = this.createModal('Widget Settings', `
            <form class="widget-settings-form">
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" name="title" value="${widget.config.title}" />
                </div>
                <div class="form-group">
                    <label>Refresh Interval (seconds)</label>
                    <input type="number" name="refreshInterval" value="${widget.config.refreshInterval / 1000}" min="0" />
                </div>
                ${this.getTypeSpecificSettings(widget)}
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-secondary cancel-btn">Cancel</button>
                </div>
            </form>
        `);

        const form = modal.querySelector('.widget-settings-form');
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const formData = new FormData(form);

            widget.config.title = formData.get('title');
            widget.config.refreshInterval = parseInt(formData.get('refreshInterval')) * 1000;

            // Update type-specific settings
            this.updateWidgetSettings(widget, formData);

            // Re-render widget
            widget.element.querySelector('.widget-title').textContent = widget.config.title;
            widget.stopAutoRefresh();
            widget.startAutoRefresh();
            widget.refresh();

            this.closeModal(modal);
            this.saveDashboard();
        });

        modal.querySelector('.cancel-btn').addEventListener('click', () => {
            this.closeModal(modal);
        });
    }

    getTypeSpecificSettings(widget) {
        switch (widget.type) {
            case 'chart':
                return `
                    <div class="form-group">
                        <label>Chart Type</label>
                        <select name="chartType">
                            <option value="line" ${widget.config.settings.chartType === 'line' ? 'selected' : ''}>Line</option>
                            <option value="bar" ${widget.config.settings.chartType === 'bar' ? 'selected' : ''}>Bar</option>
                            <option value="pie" ${widget.config.settings.chartType === 'pie' ? 'selected' : ''}>Pie</option>
                            <option value="doughnut" ${widget.config.settings.chartType === 'doughnut' ? 'selected' : ''}>Doughnut</option>
                        </select>
                    </div>
                `;
            case 'metric-card':
                return `
                    <div class="form-group">
                        <label>Metric Type</label>
                        <select name="metric">
                            <option value="users">Users</option>
                            <option value="revenue">Revenue</option>
                            <option value="orders">Orders</option>
                            <option value="visitors">Visitors</option>
                        </select>
                    </div>
                `;
            default:
                return '';
        }
    }

    updateWidgetSettings(widget, formData) {
        switch (widget.type) {
            case 'chart':
                widget.config.settings.chartType = formData.get('chartType');
                break;
            case 'metric-card':
                widget.config.settings.metric = formData.get('metric');
                break;
        }
    }

    getDefaultSettings(type) {
        switch (type) {
            case 'chart':
                return { chartType: 'line', showLegend: true };
            case 'metric-card':
                return { metric: 'users' };
            default:
                return {};
        }
    }

    createModal(title, content) {
        const modal = document.createElement('div');
        modal.className = 'dashboard-modal';
        modal.innerHTML = `
            <div class="modal-backdrop"></div>
            <div class="modal-dialog">
                <div class="modal-header">
                    <h3 class="modal-title">${title}</h3>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    ${content}
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Bind close events
        modal.querySelector('.modal-close').addEventListener('click', () => {
            this.closeModal(modal);
        });

        modal.querySelector('.modal-backdrop').addEventListener('click', () => {
            this.closeModal(modal);
        });

        // Animate in
        requestAnimationFrame(() => {
            modal.classList.add('show');
        });

        return modal;
    }

    closeModal(modal) {
        modal.classList.remove('show');
        setTimeout(() => {
            modal.remove();
        }, 300);
    }

    async exportDashboard() {
        this.showToast('Generating PDF report...', 'info');

        try {
            const response = await this.apiCall('dashboard.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'export',
                    widgets: Array.from(this.state.widgets.values()).map(w => ({
                        type: w.type,
                        title: w.config.title,
                        data: w.data
                    })),
                    dateRange: this.state.dateRange
                })
            });

            if (response.url) {
                window.open(response.url, '_blank');
                this.showToast('PDF report generated successfully', 'success');
            }
        } catch (error) {
            console.error('Export failed:', error);
            this.showToast('Failed to generate PDF report', 'error');
        }
    }

    toggleTheme() {
        this.state.theme = this.state.theme === 'light' ? 'dark' : 'light';
        this.applyTheme();
        localStorage.setItem('dashboardTheme', this.state.theme);
    }

    applyTheme() {
        document.documentElement.setAttribute('data-theme', this.state.theme);
    }

    handleDateRangeChange(e) {
        // Parse date range from input
        const value = e.target.value;
        // Update state and refresh widgets
        this.refreshAllWidgets();
    }

    refreshAllWidgets() {
        this.state.widgets.forEach(widget => {
            widget.refresh();
        });
        this.showToast('All widgets refreshed', 'success');
    }

    saveDashboard() {
        const dashboardData = {
            widgets: Array.from(this.state.widgets.values()).map(w => ({
                id: w.id,
                type: w.type,
                config: w.config
            })),
            layout: this.gridLayout?.loadLayout()
        };

        this.apiCall('dashboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save',
                data: dashboardData
            })
        });

        localStorage.setItem('dashboard', JSON.stringify(dashboardData));
    }

    applyLayout(layout) {
        layout.forEach(item => {
            const widget = document.querySelector(`[data-widget-id="${item.id}"]`);
            if (widget) {
                widget.style.gridColumn = item.position.gridColumn;
                widget.style.gridRow = item.position.gridRow;
            }
        });
    }

    startAutoRefresh() {
        this.stopAutoRefresh();
        this.refreshInterval = setInterval(() => {
            this.state.widgets.forEach(widget => {
                if (!document.hidden) {
                    widget.refresh();
                }
            });
        }, this.config.refreshInterval);
    }

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    }

    handleResize() {
        // Handle responsive layout changes
        const width = window.innerWidth;

        if (width < 768) {
            // Mobile layout
            this.dashboardContainer?.classList.add('mobile-layout');
        } else if (width < 1024) {
            // Tablet layout
            this.dashboardContainer?.classList.add('tablet-layout');
            this.dashboardContainer?.classList.remove('mobile-layout');
        } else {
            // Desktop layout
            this.dashboardContainer?.classList.remove('mobile-layout', 'tablet-layout');
        }
    }

    async apiCall(endpoint, options = {}) {
        try {
            const response = await fetch(this.config.apiBase + endpoint, {
                credentials: 'same-origin',
                ...options
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            this.showToast('Communication error', 'error');
            throw error;
        }
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `dashboard-toast toast--${type}`;
        toast.innerHTML = `
            <div class="toast-icon">${this.getToastIcon(type)}</div>
            <div class="toast-message">${message}</div>
            <button class="toast-close">&times;</button>
        `;

        const container = document.getElementById('toastContainer') || this.createToastContainer();
        container.appendChild(toast);

        toast.querySelector('.toast-close').addEventListener('click', () => {
            toast.remove();
        });

        setTimeout(() => {
            toast.classList.add('hiding');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }

    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'dashboard-toast-container';
        document.body.appendChild(container);
        return container;
    }

    getToastIcon(type) {
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };
        return icons[type] || icons.info;
    }
}

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.dashboardManager = new DashboardManager();
});