/**
 * Calendar Application Module
 * Complete calendar interface with month, week, and day views
 * Supports drag & drop, recurring events, and real-time updates
 */

class CalendarApp {
    constructor(container, options = {}) {
        this.container = typeof container === 'string' ?
            document.getElementById(container) : container;

        this.config = {
            apiBase: '/api/',
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
            ...options
        };

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
            viewBounds: {}
        };

        this.components = {};
        this.init();
    }

    init() {
        this.setupComponents();
        this.renderLayout();
        this.bindEvents();
        this.loadInitialData();
        this.setupKeyboardShortcuts();

        // Setup WebSocket for real-time updates
        if (this.config.realtimeEnabled) {
            this.setupWebSocket();
        }
    }

    setupComponents() {
        this.components.view = new CalendarView(this);
        this.components.eventManager = new EventManager(this);
        this.components.eventModal = new EventModal(this);
        this.components.dragDropHandler = new DragDropHandler(this);
        this.components.sidebar = new CalendarSidebar(this);
        this.components.toolbar = new CalendarToolbar(this);
        this.components.contextMenu = new ContextMenu(this);
    }

    renderLayout() {
        this.container.innerHTML = `
            <div class="calendar-wrapper">
                <div class="calendar-header" id="calendar-toolbar"></div>
                <div class="calendar-body">
                    <div class="calendar-sidebar" id="calendar-sidebar"></div>
                    <div class="calendar-main">
                        <div class="calendar-view-container" id="calendar-view"></div>
                    </div>
                </div>
            </div>
            <div id="event-modal" class="modal"></div>
            <div id="context-menu" class="context-menu"></div>
            <div id="calendar-toast" class="toast-container"></div>
        `;

        this.components.toolbar.render();
        this.components.sidebar.render();
        this.components.view.render();
    }

    bindEvents() {
        // View container events
        const viewContainer = document.getElementById('calendar-view');

        // Click events with delegation
        viewContainer.addEventListener('click', (e) => {
            if (e.target.matches('.calendar-day')) {
                this.handleDayClick(e);
            } else if (e.target.matches('.calendar-event')) {
                this.handleEventClick(e);
            } else if (e.target.matches('.calendar-hour')) {
                this.handleTimeSlotClick(e);
            }
        });

        // Double click for quick event creation
        viewContainer.addEventListener('dblclick', (e) => {
            if (e.target.matches('.calendar-day, .calendar-hour')) {
                this.quickCreateEvent(e);
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
            const response = await this.apiCall('calendars');
            this.state.calendars = response.data || [];

            // Select all calendars by default
            this.state.calendars.forEach(cal => {
                this.state.selectedCalendars.add(cal.id);
            });

            this.components.sidebar.updateCalendarList();
        } catch (error) {
            console.error('Error loading calendars:', error);
        }
    }

    async loadEvents() {
        const bounds = this.getViewBounds();

        try {
            const params = new URLSearchParams({
                start: bounds.start.toISOString(),
                end: bounds.end.toISOString()
            });

            // Add selected calendars filter
            if (this.state.selectedCalendars.size > 0) {
                this.state.selectedCalendars.forEach(id => {
                    params.append('calendar_ids[]', id);
                });
            }

            // Add other filters
            Object.entries(this.state.filters).forEach(([key, value]) => {
                if (value) params.append(key, value);
            });

            const response = await this.apiCall(`events.php?${params}`);
            this.state.events = this.processEvents(response.data || []);

            // Update view with new events
            this.components.view.renderEvents();
        } catch (error) {
            console.error('Error loading events:', error);
            this.showToast('Errore nel caricamento degli eventi', 'error');
        }
    }

    processEvents(events) {
        return events.map(event => ({
            ...event,
            start: new Date(event.start_date),
            end: new Date(event.end_date),
            allDay: event.all_day === 1,
            color: event.color || this.getCalendarColor(event.calendar_id)
        }));
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
        this.loadEvents();
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

        this.loadEvents();
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
        e.stopPropagation();
        const eventId = parseInt(e.target.dataset.eventId);
        const event = this.state.events.find(ev => ev.id === eventId);

        if (event) {
            this.state.selectedEvent = event;
            this.components.eventModal.show(event);
        }
    }

    handleTimeSlotClick(e) {
        const time = e.target.dataset.time;
        const date = e.target.dataset.date ||
            this.state.currentDate.toISOString().split('T')[0];

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
            const response = await this.apiCall(`events.php?id=${eventData.id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(eventData)
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

        try {
            const response = await this.apiCall(`events.php?id=${event.id}`, {
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

    async apiCall(endpoint, options = {}) {
        try {
            const response = await fetch(this.config.apiBase + endpoint, {
                credentials: 'same-origin',
                ...options
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
    }

    render() {
        switch (this.app.state.currentView) {
            case 'month':
                this.renderMonth();
                break;
            case 'week':
                this.renderWeek();
                break;
            case 'day':
                this.renderDay();
                break;
        }
    }

    renderMonth() {
        const { currentDate, events } = this.app.state;
        const bounds = this.app.getViewBounds();

        let html = '<div class="calendar-month">';

        // Header with day names
        html += '<div class="calendar-month-header">';

        if (this.app.config.weekNumbers) {
            html += '<div class="calendar-week-number-header">W</div>';
        }

        const dayNames = this.getDayNames('short');
        for (let i = 0; i < 7; i++) {
            const dayIndex = (this.app.config.firstDayOfWeek + i) % 7;
            html += `<div class="calendar-day-name">${dayNames[dayIndex]}</div>`;
        }
        html += '</div>';

        // Calendar grid
        html += '<div class="calendar-month-grid">';

        const currentDay = new Date(bounds.start);
        let weekNumber = this.getWeekNumber(currentDay);

        while (currentDay <= bounds.end) {
            // Week number
            if (currentDay.getDay() === this.app.config.firstDayOfWeek && this.app.config.weekNumbers) {
                html += `<div class="calendar-week-number">${weekNumber}</div>`;
                weekNumber++;
            }

            // Day cell
            const isToday = this.isToday(currentDay);
            const isCurrentMonth = currentDay.getMonth() === currentDate.getMonth();
            const dateStr = currentDay.toISOString().split('T')[0];

            html += `
                <div class="calendar-day ${isToday ? 'today' : ''}
                            ${!isCurrentMonth ? 'other-month' : ''}"
                     data-date="${dateStr}"
                     draggable="false">
                    <div class="calendar-day-number">${currentDay.getDate()}</div>
                    <div class="calendar-day-events" data-date="${dateStr}">
                        ${this.renderDayEvents(currentDay, events)}
                    </div>
                </div>
            `;

            currentDay.setDate(currentDay.getDate() + 1);
        }

        html += '</div></div>';

        this.container.innerHTML = html;
        this.renderEvents();
    }

    renderWeek() {
        const { currentDate, events } = this.app.state;
        const bounds = this.app.getViewBounds();

        let html = '<div class="calendar-week">';

        // Time sidebar
        html += '<div class="calendar-time-sidebar">';
        html += '<div class="calendar-time-header"></div>';

        for (let hour = 0; hour < 24; hour++) {
            html += `
                <div class="calendar-time-label">
                    ${hour.toString().padStart(2, '0')}:00
                </div>
            `;
        }
        html += '</div>';

        // Days container
        html += '<div class="calendar-week-days">';

        // All-day events row
        html += '<div class="calendar-allday-row">';
        html += '<div class="calendar-allday-label">Tutto il giorno</div>';

        const currentDay = new Date(bounds.start);
        for (let i = 0; i < 7; i++) {
            const dateStr = currentDay.toISOString().split('T')[0];
            html += `
                <div class="calendar-allday-cell" data-date="${dateStr}">
                    ${this.renderAllDayEvents(currentDay, events)}
                </div>
            `;
            currentDay.setDate(currentDay.getDate() + 1);
        }
        html += '</div>';

        // Day columns with time grid
        html += '<div class="calendar-week-grid">';

        // Day headers
        html += '<div class="calendar-week-headers">';
        currentDay.setDate(currentDay.getDate() - 7); // Reset to start

        for (let i = 0; i < 7; i++) {
            const isToday = this.isToday(currentDay);
            const dateStr = currentDay.toISOString().split('T')[0];

            html += `
                <div class="calendar-week-header ${isToday ? 'today' : ''}">
                    <div class="calendar-week-day-name">
                        ${this.getDayName(currentDay.getDay(), 'short')}
                    </div>
                    <div class="calendar-week-day-number">
                        ${currentDay.getDate()}
                    </div>
                </div>
            `;
            currentDay.setDate(currentDay.getDate() + 1);
        }
        html += '</div>';

        // Time grid
        html += '<div class="calendar-time-grid-container">';

        // Current time indicator
        if (this.isCurrentWeek()) {
            html += this.renderCurrentTimeIndicator();
        }

        // Time slots for each day
        currentDay.setDate(currentDay.getDate() - 7); // Reset

        for (let day = 0; day < 7; day++) {
            const dateStr = currentDay.toISOString().split('T')[0];
            html += `<div class="calendar-day-column" data-date="${dateStr}">`;

            for (let hour = 0; hour < 24; hour++) {
                for (let minute = 0; minute < 60; minute += this.app.config.slotDuration) {
                    const time = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;
                    html += `
                        <div class="calendar-time-slot"
                             data-date="${dateStr}"
                             data-time="${time}">
                        </div>
                    `;
                }
            }

            html += '</div>';
            currentDay.setDate(currentDay.getDate() + 1);
        }

        html += '</div></div></div></div></div>';

        this.container.innerHTML = html;
        this.renderEvents();
        this.startTimeIndicatorUpdate();
    }

    renderDay() {
        const { currentDate, events } = this.app.state;
        const dateStr = currentDate.toISOString().split('T')[0];

        let html = '<div class="calendar-day-view">';

        // Header
        html += `
            <div class="calendar-day-header">
                <h2>${this.app.formatDate(currentDate)}</h2>
            </div>
        `;

        // Two-column layout
        html += '<div class="calendar-day-body">';

        // Time grid column
        html += '<div class="calendar-day-timeline">';

        // All-day events
        html += `
            <div class="calendar-day-allday">
                <div class="calendar-allday-label">Tutto il giorno</div>
                <div class="calendar-allday-events">
                    ${this.renderAllDayEvents(currentDate, events)}
                </div>
            </div>
        `;

        // Time slots
        html += '<div class="calendar-day-timegrid">';

        // Time labels and slots
        for (let hour = 0; hour < 24; hour++) {
            html += `
                <div class="calendar-hour-row">
                    <div class="calendar-hour-label">
                        ${hour.toString().padStart(2, '0')}:00
                    </div>
                    <div class="calendar-hour-slots">
            `;

            // Create slots based on interval
            const intervals = 60 / this.app.config.slotDuration;
            for (let i = 0; i < intervals; i++) {
                const minute = i * this.app.config.slotDuration;
                const time = `${hour.toString().padStart(2, '0')}:${minute.toString().padStart(2, '0')}`;

                html += `
                    <div class="calendar-time-slot calendar-hour"
                         data-date="${dateStr}"
                         data-time="${time}">
                    </div>
                `;
            }

            html += '</div></div>';
        }

        html += '</div></div>';

        // Agenda sidebar
        html += `
            <div class="calendar-day-agenda">
                <h3>Agenda</h3>
                <div class="agenda-list">
                    ${this.renderAgendaList(currentDate, events)}
                </div>
            </div>
        `;

        html += '</div></div>';

        this.container.innerHTML = html;
        this.renderEvents();

        // Add current time indicator if today
        if (this.isToday(currentDate)) {
            this.startTimeIndicatorUpdate();
        }
    }

    renderEvents() {
        const { events, currentView } = this.app.state;

        if (currentView === 'month') {
            this.renderMonthEvents(events);
        } else if (currentView === 'week') {
            this.renderWeekEvents(events);
        } else if (currentView === 'day') {
            this.renderDayViewEvents(events);
        }
    }

    renderMonthEvents(events) {
        events.forEach(event => {
            const startDate = event.start.toISOString().split('T')[0];
            const container = document.querySelector(`.calendar-day-events[data-date="${startDate}"]`);

            if (container) {
                const eventEl = this.createEventElement(event, 'month');
                container.appendChild(eventEl);
            }
        });
    }

    renderWeekEvents(events) {
        const allDayEvents = events.filter(e => e.allDay);
        const timedEvents = events.filter(e => !e.allDay);

        // Render all-day events
        allDayEvents.forEach(event => {
            const dateStr = event.start.toISOString().split('T')[0];
            const container = document.querySelector(`.calendar-allday-cell[data-date="${dateStr}"]`);

            if (container) {
                const eventEl = this.createEventElement(event, 'allday');
                container.appendChild(eventEl);
            }
        });

        // Render timed events with overlap handling
        this.renderTimedEvents(timedEvents, 'week');
    }

    renderDayViewEvents(events) {
        const allDayEvents = events.filter(e => e.allDay);
        const timedEvents = events.filter(e => !e.allDay);

        // Render all-day events
        const allDayContainer = document.querySelector('.calendar-allday-events');
        allDayEvents.forEach(event => {
            const eventEl = this.createEventElement(event, 'allday');
            allDayContainer.appendChild(eventEl);
        });

        // Render timed events
        this.renderTimedEvents(timedEvents, 'day');
    }

    renderTimedEvents(events, viewType) {
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
        div.style.backgroundColor = event.color;

        // Add priority indicator
        if (event.priority === 'high') {
            div.classList.add('high-priority');
        }

        // Add recurring indicator
        if (event.recurrence_rule) {
            div.innerHTML += '<span class="event-recurring-icon">🔁</span>';
        }

        // Add participant count
        if (event.participant_count > 1) {
            div.innerHTML += `<span class="event-participants">${event.participant_count}</span>`;
        }

        // Event content
        const time = event.allDay ? '' : this.app.formatTime(event.start);
        div.innerHTML += `
            <div class="event-time">${time}</div>
            <div class="event-title">${event.title}</div>
        `;

        // Make draggable if enabled
        if (this.app.config.dragEnabled) {
            div.draggable = true;
        }

        return div;
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

        const slotHeight = 60 / this.app.config.slotDuration * 30; // 30px per slot
        const top = startMinutes / this.app.config.slotDuration * 30;
        const height = duration / this.app.config.slotDuration * 30;

        element.style.top = `${top}px`;
        element.style.height = `${height}px`;

        // Find the correct column
        const dateStr = event.start.toISOString().split('T')[0];
        const column = document.querySelector(`.calendar-day-column[data-date="${dateStr}"]`) ||
                      document.querySelector('.calendar-hour-slots');

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

    renderDayEvents(date, events) {
        const dayEvents = events.filter(event => {
            const eventDate = event.start.toISOString().split('T')[0];
            const checkDate = date.toISOString().split('T')[0];
            return eventDate === checkDate;
        });

        return dayEvents.slice(0, 3).map(event => `
            <div class="calendar-event-preview"
                 style="background-color: ${event.color}"
                 data-event-id="${event.id}">
                ${event.allDay ? '' : this.app.formatTime(event.start) + ' '}
                ${event.title}
            </div>
        `).join('');
    }

    renderAllDayEvents(date, events) {
        const dayEvents = events.filter(event => {
            const eventDate = event.start.toISOString().split('T')[0];
            const checkDate = date.toISOString().split('T')[0];
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
            const eventDate = event.start.toISOString().split('T')[0];
            const checkDate = date.toISOString().split('T')[0];
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
        // Clear any existing interval
        if (this.timeIndicatorInterval) {
            clearInterval(this.timeIndicatorInterval);
        }

        // Update every minute
        this.timeIndicatorInterval = setInterval(() => {
            const indicator = document.querySelector('.current-time-indicator');
            if (indicator) {
                const now = new Date();
                const minutes = now.getHours() * 60 + now.getMinutes();
                const top = minutes / this.app.config.slotDuration * 30;
                indicator.style.top = `${top}px`;
            }
        }, 60000);
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
        this.modal.addEventListener('click', (e) => {
            if (e.target === this.modal) {
                this.hide();
            }
        });
    }

    show(event = null) {
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
        this.render();
        this.modal.classList.add('show');

        // Focus on title field
        setTimeout(() => {
            const titleInput = document.getElementById('event-title');
            if (titleInput) titleInput.focus();
        }, 100);
    }

    hide() {
        this.modal.classList.remove('show');
        this.event = null;
    }

    render() {
        const { calendars } = this.app.state;

        this.modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h2>${this.isNew ? 'Nuovo Evento' : 'Modifica Evento'}</h2>
                    <button class="modal-close" onclick="window.calendar.components.eventModal.hide()">×</button>
                </div>

                <div class="modal-body">
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
                                <input type="datetime-local"
                                       id="event-start"
                                       class="form-control"
                                       value="${this.formatDateTimeLocal(this.event.start_date)}"
                                       required>
                            </div>
                            <div class="form-group">
                                <label>Data e ora fine</label>
                                <input type="datetime-local"
                                       id="event-end"
                                       class="form-control"
                                       value="${this.formatDateTimeLocal(this.event.end_date)}"
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
                                ${calendars.map(cal => `
                                    <option value="${cal.id}"
                                            ${cal.id === this.event.calendar_id ? 'selected' : ''}>
                                        ${cal.name}
                                    </option>
                                `).join('')}
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

                        <!-- Participants -->
                        <div class="form-group">
                            <label>Partecipanti</label>
                            <div class="participants-selector">
                                <input type="text"
                                       id="participant-search"
                                       class="form-control"
                                       placeholder="Cerca e aggiungi partecipanti">
                                <div id="participants-list" class="participants-list">
                                    ${this.renderParticipants()}
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

                <div class="modal-footer">
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
    }

    bindFormEvents() {
        // All day toggle
        const allDayCheckbox = document.getElementById('event-allday');
        allDayCheckbox.addEventListener('change', (e) => {
            const startInput = document.getElementById('event-start');
            const endInput = document.getElementById('event-end');

            if (e.target.checked) {
                startInput.type = 'date';
                endInput.type = 'date';
            } else {
                startInput.type = 'datetime-local';
                endInput.type = 'datetime-local';
            }
        });

        // Recurrence selector
        const recurrenceSelect = document.getElementById('event-recurrence');
        recurrenceSelect.addEventListener('change', (e) => {
            const details = document.getElementById('recurrence-details');
            if (e.target.value === 'CUSTOM') {
                details.style.display = 'block';
            } else {
                details.style.display = 'none';
            }
        });

        // Participant search
        const participantSearch = document.getElementById('participant-search');
        participantSearch.addEventListener('input', debounce((e) => {
            this.searchParticipants(e.target.value);
        }, 300));

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
                await this.app.updateEvent({ ...formData, id: this.event.id });
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
                await this.app.updateEvent({ ...formData, id: this.event.id });
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
        const title = document.getElementById('event-title').value.trim();
        const start = document.getElementById('event-start').value;
        const end = document.getElementById('event-end').value;

        if (!title) {
            this.app.showToast('Il titolo è obbligatorio', 'error');
            return false;
        }

        if (!start || !end) {
            this.app.showToast('Le date sono obbligatorie', 'error');
            return false;
        }

        if (new Date(start) >= new Date(end)) {
            this.app.showToast('La data di fine deve essere dopo quella di inizio', 'error');
            return false;
        }

        return true;
    }

    getFormData() {
        return {
            title: document.getElementById('event-title').value.trim(),
            description: document.getElementById('event-description').value.trim(),
            start_date: document.getElementById('event-start').value,
            end_date: document.getElementById('event-end').value,
            all_day: document.getElementById('event-allday').checked,
            location: document.getElementById('event-location').value.trim(),
            calendar_id: parseInt(document.getElementById('event-calendar').value),
            category: document.getElementById('event-category').value,
            tags: document.getElementById('event-tags').value.split(',').map(t => t.trim()).filter(t => t),
            color: this.event.color,
            recurrence_rule: this.recurrenceBuilder.getRule(),
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
        // Implement participant collection
        return this.event.participants || [];
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

            const eventId = parseInt(e.target.dataset.eventId);
            this.draggedEvent = this.app.state.events.find(ev => ev.id === eventId);

            if (!this.draggedEvent) return;

            e.target.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', eventId);

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
                start_date: newDate.toISOString(),
                end_date: new Date(newDate.getTime() + duration).toISOString()
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
            const eventId = parseInt(eventEl.dataset.eventId);
            const event = this.app.state.events.find(ev => ev.id === eventId);

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
            element.dataset.tempEnd = newEnd.toISOString();
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

        const newDate = new Date(dateStr);

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
        this.container.innerHTML = `
            <div class="toolbar-section">
                <button class="btn btn-icon" onclick="window.calendar.components.sidebar.toggle()">
                    ☰
                </button>
                <button class="btn btn-primary" onclick="window.calendar.components.eventModal.show()">
                    + Nuovo
                </button>
            </div>

            <div class="toolbar-section">
                <div class="view-switcher">
                    <button class="btn ${this.app.state.currentView === 'month' ? 'active' : ''}"
                            onclick="window.calendar.changeView('month')">Mese</button>
                    <button class="btn ${this.app.state.currentView === 'week' ? 'active' : ''}"
                            onclick="window.calendar.changeView('week')">Settimana</button>
                    <button class="btn ${this.app.state.currentView === 'day' ? 'active' : ''}"
                            onclick="window.calendar.changeView('day')">Giorno</button>
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
                <input type="text"
                       class="search-box"
                       placeholder="Cerca eventi..."
                       onkeyup="window.calendar.components.toolbar.handleSearch(event)">
                <button class="btn btn-icon" onclick="window.calendar.components.toolbar.print()">
                    🖨
                </button>
                <button class="btn btn-icon" onclick="window.calendar.components.toolbar.export()">
                    ⬇
                </button>
            </div>
        `;
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
        const buttons = this.container.querySelectorAll('.view-switcher button');
        buttons.forEach(btn => {
            btn.classList.remove('active');
            if (btn.textContent.toLowerCase().includes(this.app.state.currentView)) {
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

            const response = await fetch(`/api/events.php?action=export&${params}`);
            const blob = await response.blob();

            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `calendar_${new Date().toISOString().split('T')[0]}.ics`;
            a.click();
        } catch (error) {
            console.error('Error exporting calendar:', error);
            this.app.showToast('Errore nell\'esportazione', 'error');
        }
    }
}

class CalendarSidebar {
    constructor(app) {
        this.app = app;
        this.container = document.getElementById('calendar-sidebar');
        this.isCollapsed = false;
    }

    render() {
        this.container.innerHTML = `
            <div class="sidebar-section">
                <h3>Mini Calendario</h3>
                <div class="mini-calendar" id="mini-calendar"></div>
            </div>

            <div class="sidebar-section">
                <h3>Calendari</h3>
                <div class="calendar-list" id="calendar-list"></div>
            </div>

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
                </div>
            </div>
        `;

        this.renderMiniCalendar();
        this.updateCalendarList();
        this.renderUpcomingEvents();
        this.bindFilterEvents();
    }

    renderMiniCalendar() {
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
            const dateStr = date.toISOString().split('T')[0];

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
    }

    updateCalendarList() {
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
    }

    renderUpcomingEvents() {
        const container = document.getElementById('upcoming-events');
        const now = new Date();
        const upcoming = this.app.state.events
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

        const eventId = parseInt(eventEl.dataset.eventId);
        this.targetEvent = this.app.state.events.find(ev => ev.id === eventId);

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