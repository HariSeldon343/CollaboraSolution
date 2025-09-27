/**
 * CollaboraNexio - Real-time Notification System
 * Handles desktop notifications, in-app toasts, and notification center
 */

class NotificationManager {
    constructor() {
        this.config = {
            apiBase: '/api/',
            pollInterval: 5000, // Start with 5 seconds
            maxPollInterval: 60000, // Max 60 seconds
            minPollInterval: 5000, // Min 5 seconds
            backoffMultiplier: 1.5,
            toastDuration: 5000,
            maxNotificationsDisplay: 50,
            soundEnabled: true,
            desktopEnabled: false,
            batchDelay: 500, // Batch notifications within 500ms
            notificationHistoryLimit: 100
        };

        this.state = {
            notifications: [],
            unreadCount: 0,
            isDropdownOpen: false,
            lastFetchTime: null,
            currentPollInterval: this.config.pollInterval,
            failedAttempts: 0,
            permissionStatus: 'default',
            activeToasts: new Set(),
            preferences: {},
            quietHours: { enabled: false, start: '22:00', end: '08:00' },
            categories: ['all', 'files', 'tasks', 'chat', 'system', 'approvals'],
            activeCategory: 'all',
            pendingBatch: [],
            batchTimer: null
        };

        this.notificationTypes = {
            file_shared: {
                icon: 'fa-share-nodes',
                color: '#4CAF50',
                sound: 'notification-1.mp3',
                priority: 'medium'
            },
            task_assigned: {
                icon: 'fa-tasks',
                color: '#2196F3',
                sound: 'notification-2.mp3',
                priority: 'high'
            },
            chat_mention: {
                icon: 'fa-at',
                color: '#FF9800',
                sound: 'notification-3.mp3',
                priority: 'high'
            },
            deadline_upcoming: {
                icon: 'fa-clock',
                color: '#F44336',
                sound: 'notification-urgent.mp3',
                priority: 'urgent'
            },
            approval_request: {
                icon: 'fa-check-circle',
                color: '#9C27B0',
                sound: 'notification-2.mp3',
                priority: 'high'
            },
            system_alert: {
                icon: 'fa-exclamation-triangle',
                color: '#FF5722',
                sound: 'notification-alert.mp3',
                priority: 'urgent'
            },
            comment_added: {
                icon: 'fa-comment',
                color: '#00BCD4',
                sound: 'notification-1.mp3',
                priority: 'medium'
            },
            download_complete: {
                icon: 'fa-download',
                color: '#8BC34A',
                sound: 'notification-success.mp3',
                priority: 'low'
            },
            storage_warning: {
                icon: 'fa-database',
                color: '#FFC107',
                sound: 'notification-alert.mp3',
                priority: 'high'
            },
            report_ready: {
                icon: 'fa-chart-bar',
                color: '#3F51B5',
                sound: 'notification-1.mp3',
                priority: 'medium'
            }
        };

        this.init();
    }

    init() {
        this.loadState();
        this.createNotificationElements();
        this.bindEvents();
        this.checkDesktopPermission();
        this.startPolling();
        this.loadInitialNotifications();
        this.setupVisibilityHandler();
        this.initializeServiceWorker();
    }

    createNotificationElements() {
        // Create notification center dropdown if it doesn't exist
        if (!document.getElementById('notification-center')) {
            const notificationHTML = `
                <div id="notification-center" class="notification-center">
                    <div class="notification-header">
                        <h3>Notifiche</h3>
                        <div class="notification-actions">
                            <button class="btn-icon" id="notification-settings" title="Impostazioni">
                                <i class="fas fa-cog"></i>
                            </button>
                            <button class="btn-icon" id="mark-all-read" title="Segna tutto come letto">
                                <i class="fas fa-check-double"></i>
                            </button>
                            <button class="btn-icon" id="clear-all-notifications" title="Cancella tutto">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="notification-categories">
                        <button class="category-btn active" data-category="all">Tutte</button>
                        <button class="category-btn" data-category="files">File</button>
                        <button class="category-btn" data-category="tasks">Attività</button>
                        <button class="category-btn" data-category="chat">Chat</button>
                        <button class="category-btn" data-category="system">Sistema</button>
                        <button class="category-btn" data-category="approvals">Approvazioni</button>
                    </div>
                    <div class="notification-list" id="notification-list">
                        <div class="notification-empty">
                            <i class="fas fa-bell-slash"></i>
                            <p>Nessuna notifica</p>
                        </div>
                    </div>
                    <div class="notification-footer">
                        <a href="#" id="view-all-notifications">Vedi tutte le notifiche</a>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', notificationHTML);
        }

        // Create toast container if it doesn't exist
        if (!document.getElementById('toast-container')) {
            const toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            toastContainer.className = 'toast-container';
            document.body.appendChild(toastContainer);
        }

        // Create notification settings modal
        if (!document.getElementById('notification-settings-modal')) {
            const settingsModal = `
                <div id="notification-settings-modal" class="modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2>Impostazioni Notifiche</h2>
                            <button class="modal-close" data-modal="notification-settings-modal">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="settings-section">
                                <h3>Generali</h3>
                                <label class="toggle-setting">
                                    <input type="checkbox" id="desktop-notifications" ${this.state.desktopEnabled ? 'checked' : ''}>
                                    <span>Notifiche Desktop</span>
                                </label>
                                <label class="toggle-setting">
                                    <input type="checkbox" id="sound-notifications" ${this.config.soundEnabled ? 'checked' : ''}>
                                    <span>Suoni Notifiche</span>
                                </label>
                            </div>
                            <div class="settings-section">
                                <h3>Ore Silenziose</h3>
                                <label class="toggle-setting">
                                    <input type="checkbox" id="quiet-hours-enabled" ${this.state.quietHours.enabled ? 'checked' : ''}>
                                    <span>Abilita Ore Silenziose</span>
                                </label>
                                <div class="time-range">
                                    <input type="time" id="quiet-start" value="${this.state.quietHours.start}">
                                    <span>a</span>
                                    <input type="time" id="quiet-end" value="${this.state.quietHours.end}">
                                </div>
                            </div>
                            <div class="settings-section">
                                <h3>Tipi di Notifiche</h3>
                                ${this.renderNotificationTypeSettings()}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" data-modal="notification-settings-modal">Annulla</button>
                            <button class="btn btn-primary" id="save-notification-settings">Salva</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', settingsModal);
        }
    }

    renderNotificationTypeSettings() {
        return Object.entries(this.notificationTypes).map(([type, config]) => {
            const typeLabel = type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            const isEnabled = this.state.preferences[type] !== false;
            return `
                <label class="toggle-setting">
                    <input type="checkbox" data-notification-type="${type}" ${isEnabled ? 'checked' : ''}>
                    <i class="fas ${config.icon}" style="color: ${config.color}"></i>
                    <span>${typeLabel}</span>
                </label>
            `;
        }).join('');
    }

    bindEvents() {
        // Notification bell click
        const notificationBell = document.getElementById('notification-bell');
        if (notificationBell) {
            notificationBell.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleNotificationCenter();
            });
        }

        // Notification center events
        document.getElementById('notification-center')?.addEventListener('click', (e) => {
            // Settings button
            if (e.target.closest('#notification-settings')) {
                this.openSettingsModal();
            }

            // Mark all as read
            if (e.target.closest('#mark-all-read')) {
                this.markAllAsRead();
            }

            // Clear all notifications
            if (e.target.closest('#clear-all-notifications')) {
                this.clearAllNotifications();
            }

            // Category filter
            if (e.target.matches('.category-btn')) {
                this.filterByCategory(e.target.dataset.category);
            }

            // Notification actions
            if (e.target.matches('.notification-action')) {
                const notificationId = e.target.closest('.notification-item').dataset.id;
                const action = e.target.dataset.action;
                this.handleNotificationAction(notificationId, action);
            }

            // Notification click
            if (e.target.closest('.notification-item') && !e.target.matches('.notification-action')) {
                const notification = e.target.closest('.notification-item');
                this.handleNotificationClick(notification.dataset.id);
            }

            // View all notifications
            if (e.target.matches('#view-all-notifications')) {
                e.preventDefault();
                this.openNotificationHistory();
            }
        });

        // Settings modal events
        document.getElementById('notification-settings-modal')?.addEventListener('click', (e) => {
            // Save settings
            if (e.target.matches('#save-notification-settings')) {
                this.saveNotificationSettings();
            }

            // Close modal
            if (e.target.matches('[data-modal]') || e.target.closest('[data-modal]')) {
                this.closeSettingsModal();
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (this.state.isDropdownOpen &&
                !e.target.closest('#notification-center') &&
                !e.target.closest('#notification-bell')) {
                this.closeNotificationCenter();
            }
        });

        // Toast container events
        document.getElementById('toast-container')?.addEventListener('click', (e) => {
            if (e.target.matches('.toast-close') || e.target.closest('.toast-close')) {
                const toast = e.target.closest('.toast-notification');
                this.dismissToast(toast.dataset.id);
            }

            if (e.target.matches('.toast-action')) {
                const toast = e.target.closest('.toast-notification');
                const action = e.target.dataset.action;
                this.handleToastAction(toast.dataset.id, action);
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Alt + N to toggle notification center
            if (e.altKey && e.key === 'n') {
                e.preventDefault();
                this.toggleNotificationCenter();
            }

            // Escape to close notification center
            if (e.key === 'Escape' && this.state.isDropdownOpen) {
                this.closeNotificationCenter();
            }
        });
    }

    async checkDesktopPermission() {
        if ('Notification' in window) {
            this.state.permissionStatus = Notification.permission;

            if (Notification.permission === 'default') {
                // We'll request permission when user enables desktop notifications
                this.state.desktopEnabled = false;
            } else if (Notification.permission === 'granted') {
                this.state.desktopEnabled = true;
            }
        }
    }

    async requestDesktopPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            try {
                const permission = await Notification.requestPermission();
                this.state.permissionStatus = permission;
                this.state.desktopEnabled = permission === 'granted';
                return permission === 'granted';
            } catch (error) {
                console.error('Error requesting notification permission:', error);
                return false;
            }
        }
        return Notification.permission === 'granted';
    }

    startPolling() {
        // Clear any existing interval
        if (this.pollTimer) {
            clearTimeout(this.pollTimer);
        }

        // Check if we should poll (not in quiet hours, tab visible, etc.)
        if (!this.shouldPoll()) {
            // Retry after a delay
            this.pollTimer = setTimeout(() => this.startPolling(), this.config.maxPollInterval);
            return;
        }

        this.pollForNotifications();
    }

    shouldPoll() {
        // Check if in quiet hours
        if (this.state.quietHours.enabled) {
            const now = new Date();
            const currentTime = `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}`;
            const { start, end } = this.state.quietHours;

            if (start <= end) {
                if (currentTime >= start && currentTime <= end) return false;
            } else {
                if (currentTime >= start || currentTime <= end) return false;
            }
        }

        // Check if page is visible
        return !document.hidden;
    }

    async pollForNotifications() {
        try {
            const lastCheck = this.state.lastFetchTime || new Date(Date.now() - 60000).toISOString();
            const response = await this.apiCall(`notifications/check?since=${lastCheck}`);

            if (response.success && response.notifications) {
                this.processNewNotifications(response.notifications);
                this.state.lastFetchTime = new Date().toISOString();

                // Reset backoff on success
                this.state.failedAttempts = 0;
                this.state.currentPollInterval = this.config.pollInterval;
            }
        } catch (error) {
            // Implement exponential backoff
            this.state.failedAttempts++;
            this.state.currentPollInterval = Math.min(
                this.state.currentPollInterval * this.config.backoffMultiplier,
                this.config.maxPollInterval
            );
            console.error('Failed to poll notifications:', error);
        } finally {
            // Schedule next poll
            this.pollTimer = setTimeout(() => this.pollForNotifications(), this.state.currentPollInterval);
        }
    }

    processNewNotifications(notifications) {
        if (!notifications || notifications.length === 0) return;

        // Add to batch for processing
        this.state.pendingBatch.push(...notifications);

        // Clear existing batch timer
        if (this.batchTimer) {
            clearTimeout(this.batchTimer);
        }

        // Set new batch timer
        this.batchTimer = setTimeout(() => {
            this.processBatchedNotifications();
        }, this.config.batchDelay);
    }

    processBatchedNotifications() {
        const batch = [...this.state.pendingBatch];
        this.state.pendingBatch = [];

        if (batch.length === 0) return;

        // Group similar notifications
        const grouped = this.groupNotifications(batch);

        grouped.forEach(notification => {
            // Check if notification type is enabled
            if (this.state.preferences[notification.type] === false) return;

            // Add to state
            this.addNotification(notification);

            // Show toast
            if (!this.isInQuietHours()) {
                this.showToast(notification);
            }

            // Show desktop notification
            if (this.state.desktopEnabled && !this.isInQuietHours()) {
                this.showDesktopNotification(notification);
            }

            // Play sound
            if (this.config.soundEnabled && !this.isInQuietHours()) {
                this.playNotificationSound(notification.type);
            }
        });

        // Update UI
        this.updateNotificationBadge();
        this.renderNotificationList();
        this.saveState();
    }

    groupNotifications(notifications) {
        // Group similar notifications that arrive close together
        const groups = {};

        notifications.forEach(notification => {
            const key = `${notification.type}_${notification.source_id || 'general'}`;

            if (!groups[key]) {
                groups[key] = {
                    ...notification,
                    count: 1,
                    items: [notification]
                };
            } else {
                groups[key].count++;
                groups[key].items.push(notification);

                // Update message for grouped notifications
                if (groups[key].count > 1) {
                    const typeConfig = this.notificationTypes[notification.type];
                    groups[key].title = `${groups[key].count} nuove ${this.getNotificationTypeLabel(notification.type)}`;
                }
            }
        });

        return Object.values(groups);
    }

    addNotification(notification) {
        // Add unique ID if not present
        if (!notification.id) {
            notification.id = `notif_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
        }

        // Add timestamp if not present
        if (!notification.timestamp) {
            notification.timestamp = new Date().toISOString();
        }

        // Add to beginning of array
        this.state.notifications.unshift(notification);

        // Increment unread count if not read
        if (!notification.is_read) {
            this.state.unreadCount++;
        }

        // Limit stored notifications
        if (this.state.notifications.length > this.config.notificationHistoryLimit) {
            this.state.notifications = this.state.notifications.slice(0, this.config.notificationHistoryLimit);
        }
    }

    showToast(notification) {
        const toastId = `toast_${notification.id}`;

        // Check if toast already exists
        if (this.state.activeToasts.has(toastId)) return;

        const typeConfig = this.notificationTypes[notification.type] || this.notificationTypes.system_alert;

        const toastHTML = `
            <div class="toast-notification" data-id="${toastId}" data-type="${notification.type}">
                <div class="toast-icon" style="background-color: ${typeConfig.color}">
                    <i class="fas ${typeConfig.icon}"></i>
                </div>
                <div class="toast-content">
                    <div class="toast-header">
                        <strong>${notification.title || 'Notifica'}</strong>
                        <button class="toast-close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="toast-body">
                        ${notification.message || ''}
                    </div>
                    ${notification.actions ? this.renderToastActions(notification.actions) : ''}
                    <div class="toast-time">${this.getRelativeTime(notification.timestamp)}</div>
                </div>
            </div>
        `;

        const container = document.getElementById('toast-container');
        container.insertAdjacentHTML('afterbegin', toastHTML);

        const toastElement = container.querySelector(`[data-id="${toastId}"]`);
        this.state.activeToasts.add(toastId);

        // Animate in
        setTimeout(() => {
            toastElement.classList.add('show');
        }, 10);

        // Auto dismiss
        const dismissTimeout = notification.priority === 'urgent' ?
            this.config.toastDuration * 2 : this.config.toastDuration;

        setTimeout(() => {
            this.dismissToast(toastId);
        }, dismissTimeout);
    }

    renderToastActions(actions) {
        if (!actions || actions.length === 0) return '';

        return `
            <div class="toast-actions">
                ${actions.map(action => `
                    <button class="toast-action" data-action="${action.type}">
                        ${action.label}
                    </button>
                `).join('')}
            </div>
        `;
    }

    dismissToast(toastId) {
        const toast = document.querySelector(`[data-id="${toastId}"]`);
        if (!toast) return;

        toast.classList.remove('show');
        toast.classList.add('hide');

        setTimeout(() => {
            toast.remove();
            this.state.activeToasts.delete(toastId);
        }, 300);
    }

    async showDesktopNotification(notification) {
        if (!this.state.desktopEnabled || Notification.permission !== 'granted') return;

        const typeConfig = this.notificationTypes[notification.type] || this.notificationTypes.system_alert;

        try {
            const desktopNotif = new Notification(notification.title || 'CollaboraNexio', {
                body: notification.message,
                icon: '/assets/images/logo-icon.png',
                badge: '/assets/images/badge-icon.png',
                tag: notification.id,
                requireInteraction: notification.priority === 'urgent',
                silent: !this.config.soundEnabled,
                data: {
                    notificationId: notification.id,
                    url: notification.url,
                    type: notification.type
                }
            });

            desktopNotif.onclick = (event) => {
                event.preventDefault();
                window.focus();
                this.handleNotificationClick(notification.id);
                desktopNotif.close();
            };

            // Add action buttons if supported
            if (notification.actions && 'actions' in Notification.prototype) {
                desktopNotif.actions = notification.actions.slice(0, 2).map(action => ({
                    action: action.type,
                    title: action.label
                }));
            }
        } catch (error) {
            console.error('Failed to show desktop notification:', error);
        }
    }

    playNotificationSound(type) {
        if (!this.config.soundEnabled) return;

        const typeConfig = this.notificationTypes[type];
        if (!typeConfig || !typeConfig.sound) return;

        try {
            const audio = new Audio(`/assets/sounds/${typeConfig.sound}`);
            audio.volume = 0.5;
            audio.play().catch(e => console.log('Could not play notification sound:', e));
        } catch (error) {
            console.error('Error playing notification sound:', error);
        }
    }

    toggleNotificationCenter() {
        if (this.state.isDropdownOpen) {
            this.closeNotificationCenter();
        } else {
            this.openNotificationCenter();
        }
    }

    openNotificationCenter() {
        const center = document.getElementById('notification-center');
        if (!center) return;

        center.classList.add('show');
        this.state.isDropdownOpen = true;

        // Mark visible notifications as read after a delay
        setTimeout(() => {
            this.markVisibleAsRead();
        }, 1000);
    }

    closeNotificationCenter() {
        const center = document.getElementById('notification-center');
        if (!center) return;

        center.classList.remove('show');
        this.state.isDropdownOpen = false;
    }

    filterByCategory(category) {
        this.state.activeCategory = category;

        // Update active button
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.category === category);
        });

        this.renderNotificationList();
    }

    renderNotificationList() {
        const listContainer = document.getElementById('notification-list');
        if (!listContainer) return;

        // Filter notifications by category
        let filteredNotifications = this.state.notifications;
        if (this.state.activeCategory !== 'all') {
            filteredNotifications = this.state.notifications.filter(n =>
                this.getNotificationCategory(n.type) === this.state.activeCategory
            );
        }

        if (filteredNotifications.length === 0) {
            listContainer.innerHTML = `
                <div class="notification-empty">
                    <i class="fas fa-bell-slash"></i>
                    <p>Nessuna notifica</p>
                </div>
            `;
            return;
        }

        const notificationsHTML = filteredNotifications
            .slice(0, this.config.maxNotificationsDisplay)
            .map(notification => this.renderNotificationItem(notification))
            .join('');

        listContainer.innerHTML = notificationsHTML;
    }

    renderNotificationItem(notification) {
        const typeConfig = this.notificationTypes[notification.type] || this.notificationTypes.system_alert;
        const isRead = notification.is_read;

        return `
            <div class="notification-item ${isRead ? 'read' : 'unread'}"
                 data-id="${notification.id}"
                 data-type="${notification.type}">
                <div class="notification-icon" style="background-color: ${typeConfig.color}">
                    <i class="fas ${typeConfig.icon}"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">${notification.title || 'Notifica'}</div>
                    <div class="notification-message">${notification.message || ''}</div>
                    <div class="notification-meta">
                        <span class="notification-time">${this.getRelativeTime(notification.timestamp)}</span>
                        ${notification.source ? `<span class="notification-source">${notification.source}</span>` : ''}
                    </div>
                </div>
                <div class="notification-actions">
                    ${!isRead ? '<button class="notification-action" data-action="mark-read" title="Segna come letto"><i class="fas fa-check"></i></button>' : ''}
                    <button class="notification-action" data-action="delete" title="Elimina">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
    }

    getNotificationCategory(type) {
        const categoryMap = {
            file_shared: 'files',
            comment_added: 'files',
            download_complete: 'files',
            task_assigned: 'tasks',
            deadline_upcoming: 'tasks',
            chat_mention: 'chat',
            approval_request: 'approvals',
            system_alert: 'system',
            storage_warning: 'system',
            report_ready: 'system'
        };

        return categoryMap[type] || 'system';
    }

    getNotificationTypeLabel(type) {
        const labels = {
            file_shared: 'condivisioni file',
            task_assigned: 'attività assegnate',
            chat_mention: 'menzioni',
            deadline_upcoming: 'scadenze',
            approval_request: 'richieste di approvazione',
            system_alert: 'avvisi di sistema',
            comment_added: 'commenti',
            download_complete: 'download completati',
            storage_warning: 'avvisi spazio',
            report_ready: 'report pronti'
        };

        return labels[type] || 'notifiche';
    }

    getRelativeTime(timestamp) {
        const now = new Date();
        const date = new Date(timestamp);
        const seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'ora';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} min fa`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} ore fa`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)} giorni fa`;

        return date.toLocaleDateString('it-IT', {
            day: 'numeric',
            month: 'short'
        });
    }

    async handleNotificationClick(notificationId) {
        const notification = this.state.notifications.find(n => n.id === notificationId);
        if (!notification) return;

        // Mark as read
        if (!notification.is_read) {
            await this.markAsRead(notificationId);
        }

        // Navigate to related content
        if (notification.url) {
            window.location.href = notification.url;
        } else {
            // Handle based on type
            switch (notification.type) {
                case 'file_shared':
                    window.location.href = `/files/${notification.source_id}`;
                    break;
                case 'task_assigned':
                    window.location.href = `/tasks/${notification.source_id}`;
                    break;
                case 'chat_mention':
                    window.location.href = `/chat#message-${notification.source_id}`;
                    break;
                case 'approval_request':
                    window.location.href = `/approvals/${notification.source_id}`;
                    break;
                case 'report_ready':
                    window.location.href = `/reports/${notification.source_id}`;
                    break;
                default:
                    // Stay on current page
                    break;
            }
        }

        // Close notification center
        this.closeNotificationCenter();
    }

    async handleNotificationAction(notificationId, action) {
        const notification = this.state.notifications.find(n => n.id === notificationId);
        if (!notification) return;

        switch (action) {
            case 'mark-read':
                await this.markAsRead(notificationId);
                break;
            case 'delete':
                await this.deleteNotification(notificationId);
                break;
            default:
                // Custom action - send to backend
                await this.handleCustomAction(notificationId, action);
                break;
        }
    }

    async handleToastAction(toastId, action) {
        const notificationId = toastId.replace('toast_', '');
        await this.handleNotificationAction(notificationId, action);
        this.dismissToast(toastId);
    }

    async markAsRead(notificationId) {
        try {
            const response = await this.apiCall(`notifications/${notificationId}/read`, {
                method: 'POST'
            });

            if (response.success) {
                const notification = this.state.notifications.find(n => n.id === notificationId);
                if (notification && !notification.is_read) {
                    notification.is_read = true;
                    this.state.unreadCount = Math.max(0, this.state.unreadCount - 1);
                    this.updateNotificationBadge();
                    this.renderNotificationList();
                    this.saveState();
                }
            }
        } catch (error) {
            console.error('Failed to mark notification as read:', error);
        }
    }

    async markVisibleAsRead() {
        if (!this.state.isDropdownOpen) return;

        const unreadVisible = this.state.notifications
            .filter(n => !n.is_read)
            .slice(0, 10) // Mark first 10 visible as read
            .map(n => n.id);

        if (unreadVisible.length === 0) return;

        try {
            const response = await this.apiCall('notifications/mark-read', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ notification_ids: unreadVisible })
            });

            if (response.success) {
                unreadVisible.forEach(id => {
                    const notification = this.state.notifications.find(n => n.id === id);
                    if (notification) {
                        notification.is_read = true;
                    }
                });

                this.state.unreadCount = Math.max(0, this.state.unreadCount - unreadVisible.length);
                this.updateNotificationBadge();
                this.renderNotificationList();
                this.saveState();
            }
        } catch (error) {
            console.error('Failed to mark notifications as read:', error);
        }
    }

    async markAllAsRead() {
        if (this.state.unreadCount === 0) return;

        try {
            const response = await this.apiCall('notifications/mark-all-read', {
                method: 'POST'
            });

            if (response.success) {
                this.state.notifications.forEach(n => n.is_read = true);
                this.state.unreadCount = 0;
                this.updateNotificationBadge();
                this.renderNotificationList();
                this.saveState();
                this.showToastMessage('Tutte le notifiche sono state segnate come lette', 'success');
            }
        } catch (error) {
            console.error('Failed to mark all as read:', error);
            this.showToastMessage('Errore nel segnare le notifiche come lette', 'error');
        }
    }

    async deleteNotification(notificationId) {
        try {
            const response = await this.apiCall(`notifications/${notificationId}`, {
                method: 'DELETE'
            });

            if (response.success) {
                const index = this.state.notifications.findIndex(n => n.id === notificationId);
                if (index !== -1) {
                    const notification = this.state.notifications[index];
                    if (!notification.is_read) {
                        this.state.unreadCount = Math.max(0, this.state.unreadCount - 1);
                    }
                    this.state.notifications.splice(index, 1);
                    this.updateNotificationBadge();
                    this.renderNotificationList();
                    this.saveState();
                }
            }
        } catch (error) {
            console.error('Failed to delete notification:', error);
        }
    }

    async clearAllNotifications() {
        if (this.state.notifications.length === 0) return;

        if (!confirm('Sei sicuro di voler cancellare tutte le notifiche?')) return;

        try {
            const response = await this.apiCall('notifications/clear-all', {
                method: 'DELETE'
            });

            if (response.success) {
                this.state.notifications = [];
                this.state.unreadCount = 0;
                this.updateNotificationBadge();
                this.renderNotificationList();
                this.saveState();
                this.showToastMessage('Tutte le notifiche sono state cancellate', 'success');
            }
        } catch (error) {
            console.error('Failed to clear notifications:', error);
            this.showToastMessage('Errore nel cancellare le notifiche', 'error');
        }
    }

    async handleCustomAction(notificationId, action) {
        try {
            const response = await this.apiCall(`notifications/${notificationId}/action`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ action })
            });

            if (response.success) {
                // Handle based on response
                if (response.redirect) {
                    window.location.href = response.redirect;
                } else {
                    this.showToastMessage(response.message || 'Azione completata', 'success');
                }
            }
        } catch (error) {
            console.error('Failed to handle custom action:', error);
            this.showToastMessage('Errore nell\'esecuzione dell\'azione', 'error');
        }
    }

    updateNotificationBadge() {
        const badge = document.querySelector('#notification-bell .badge');
        if (!badge) {
            // Create badge if it doesn't exist
            const bell = document.getElementById('notification-bell');
            if (bell) {
                const newBadge = document.createElement('span');
                newBadge.className = 'badge';
                bell.appendChild(newBadge);
            }
        }

        const currentBadge = document.querySelector('#notification-bell .badge');
        if (currentBadge) {
            if (this.state.unreadCount > 0) {
                currentBadge.textContent = this.state.unreadCount > 99 ? '99+' : this.state.unreadCount;
                currentBadge.style.display = 'block';
            } else {
                currentBadge.style.display = 'none';
            }
        }

        // Update document title
        if (this.state.unreadCount > 0) {
            document.title = `(${this.state.unreadCount}) CollaboraNexio`;
        } else {
            document.title = 'CollaboraNexio';
        }
    }

    openSettingsModal() {
        const modal = document.getElementById('notification-settings-modal');
        if (modal) {
            modal.classList.add('show');
            this.closeNotificationCenter();
        }
    }

    closeSettingsModal() {
        const modal = document.getElementById('notification-settings-modal');
        if (modal) {
            modal.classList.remove('show');
        }
    }

    async saveNotificationSettings() {
        // Collect settings
        const settings = {
            desktop_enabled: document.getElementById('desktop-notifications').checked,
            sound_enabled: document.getElementById('sound-notifications').checked,
            quiet_hours: {
                enabled: document.getElementById('quiet-hours-enabled').checked,
                start: document.getElementById('quiet-start').value,
                end: document.getElementById('quiet-end').value
            },
            preferences: {}
        };

        // Collect notification type preferences
        document.querySelectorAll('[data-notification-type]').forEach(input => {
            settings.preferences[input.dataset.notificationType] = input.checked;
        });

        // Handle desktop permission
        if (settings.desktop_enabled && Notification.permission === 'default') {
            const granted = await this.requestDesktopPermission();
            if (!granted) {
                settings.desktop_enabled = false;
                document.getElementById('desktop-notifications').checked = false;
                this.showToastMessage('Permesso notifiche desktop negato', 'warning');
            }
        }

        // Update local state
        this.state.desktopEnabled = settings.desktop_enabled;
        this.config.soundEnabled = settings.sound_enabled;
        this.state.quietHours = settings.quiet_hours;
        this.state.preferences = settings.preferences;

        // Save to backend
        try {
            const response = await this.apiCall('notifications/settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(settings)
            });

            if (response.success) {
                this.showToastMessage('Impostazioni salvate con successo', 'success');
                this.closeSettingsModal();
                this.saveState();
            }
        } catch (error) {
            console.error('Failed to save settings:', error);
            this.showToastMessage('Errore nel salvare le impostazioni', 'error');
        }
    }

    openNotificationHistory() {
        // Navigate to full notification history page
        window.location.href = '/notifications';
    }

    isInQuietHours() {
        if (!this.state.quietHours.enabled) return false;

        const now = new Date();
        const currentTime = `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}`;
        const { start, end } = this.state.quietHours;

        if (start <= end) {
            return currentTime >= start && currentTime <= end;
        } else {
            return currentTime >= start || currentTime <= end;
        }
    }

    setupVisibilityHandler() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Pause polling when tab is hidden
                if (this.pollTimer) {
                    clearTimeout(this.pollTimer);
                }
            } else {
                // Resume polling when tab becomes visible
                this.startPolling();
                // Load any missed notifications
                this.loadInitialNotifications();
            }
        });
    }

    async initializeServiceWorker() {
        if ('serviceWorker' in navigator && 'PushManager' in window) {
            try {
                const registration = await navigator.serviceWorker.register('/service-worker.js');
                console.log('Service Worker registered:', registration);

                // Check for push notification support
                const subscription = await registration.pushManager.getSubscription();
                if (!subscription) {
                    // Subscribe to push notifications if needed
                    // This would require VAPID keys from the server
                }
            } catch (error) {
                console.log('Service Worker registration failed:', error);
            }
        }
    }

    async loadInitialNotifications() {
        try {
            const response = await this.apiCall('notifications/recent');

            if (response.success && response.notifications) {
                this.state.notifications = response.notifications;
                this.state.unreadCount = response.unread_count || 0;

                this.updateNotificationBadge();
                this.renderNotificationList();
                this.saveState();
            }
        } catch (error) {
            console.error('Failed to load initial notifications:', error);
        }
    }

    showToastMessage(message, type = 'info') {
        const notification = {
            id: `msg_${Date.now()}`,
            type: 'system_alert',
            title: type === 'error' ? 'Errore' : type === 'success' ? 'Successo' : 'Info',
            message: message,
            timestamp: new Date().toISOString(),
            priority: type === 'error' ? 'high' : 'medium'
        };

        this.showToast(notification);
    }

    saveState() {
        const stateToSave = {
            notifications: this.state.notifications.slice(0, 50), // Save last 50
            unreadCount: this.state.unreadCount,
            preferences: this.state.preferences,
            quietHours: this.state.quietHours,
            desktopEnabled: this.state.desktopEnabled,
            lastFetchTime: this.state.lastFetchTime
        };

        try {
            localStorage.setItem('notification_state', JSON.stringify(stateToSave));
        } catch (error) {
            console.error('Failed to save notification state:', error);
        }
    }

    loadState() {
        try {
            const saved = localStorage.getItem('notification_state');
            if (saved) {
                const state = JSON.parse(saved);
                Object.assign(this.state, state);
            }
        } catch (error) {
            console.error('Failed to load notification state:', error);
        }
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
            throw error;
        }
    }

    // Public methods for external use
    sendNotification(type, title, message, options = {}) {
        const notification = {
            type,
            title,
            message,
            timestamp: new Date().toISOString(),
            is_read: false,
            ...options
        };

        this.processNewNotifications([notification]);
    }

    getUnreadCount() {
        return this.state.unreadCount;
    }

    getNotifications(limit = null) {
        if (limit) {
            return this.state.notifications.slice(0, limit);
        }
        return this.state.notifications;
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.notificationManager = new NotificationManager();
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = NotificationManager;
}