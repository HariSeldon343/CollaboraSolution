(function() {
    'use strict';

    /**
     * Main Application Module for CollaboraNexio
     * Core functionality and utilities for all pages
     */

    class App {
    constructor() {
        this.config = {
            apiBase: '/CollaboraNexio/api/',
            pollInterval: 30000,
            toastDuration: 3000,
            debounceDelay: 300
        };
        this.state = {
            currentPage: window.location.pathname.split('/').pop().replace('.php', ''),
            notifications: [],
            user: null,
            company: null
        };
        this.init();
    }

    init() {
        this.bindEvents();
        this.initializeComponents();
        this.setupAjaxDefaults();
        this.loadUserSession();
    }

    bindEvents() {
        // Sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => this.toggleSidebar());
        }

        // Company filter dropdown
        const companyDropdown = document.getElementById('companyFilter');
        if (companyDropdown) {
            companyDropdown.addEventListener('change', (e) => {
                this.handleCompanyChange(e.target.value);
            });
        }

        // Global keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            this.handleGlobalKeyboard(e);
        });

        // Handle all forms with AJAX
        document.querySelectorAll('form[data-ajax]').forEach(form => {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleAjaxForm(form);
            });
        });

        // Tooltips initialization
        this.initTooltips();

        // Dropdown menus
        this.initDropdowns();

        // Modal handlers
        this.initModals();

        // Table sorting
        this.initTableSorting();

        // Search inputs with debouncing
        this.initSearchInputs();

        // Notification system
        this.initNotifications();
    }

    initializeComponents() {
        // Initialize date pickers
        document.querySelectorAll('.datepicker').forEach(input => {
            this.initDatePicker(input);
        });

        // Initialize time pickers
        document.querySelectorAll('.timepicker').forEach(input => {
            this.initTimePicker(input);
        });

        // Initialize select2 dropdowns
        document.querySelectorAll('.select2').forEach(select => {
            this.initSelect2(select);
        });

        // Initialize charts if any
        document.querySelectorAll('.chart-container').forEach(container => {
            this.initChart(container);
        });

        // Initialize data tables
        document.querySelectorAll('.data-table').forEach(table => {
            this.initDataTable(table);
        });
    }

    toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');

        if (sidebar) {
            sidebar.classList.toggle('collapsed');

            // Save state to localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);

            // Trigger resize event for any charts or responsive elements
            window.dispatchEvent(new Event('resize'));
        }

        if (mainContent) {
            mainContent.classList.toggle('sidebar-collapsed');
        }
    }

    handleCompanyChange(companyId) {
        this.showToast('Switching company...', 'info');

        // Send AJAX request to update session
        this.apiCall('company/switch', {
            method: 'POST',
            body: JSON.stringify({ company_id: companyId })
        }).then(response => {
            if (response.success) {
                this.state.company = response.company;
                this.showToast('Company switched successfully', 'success');

                // Reload immediately without delay (BUG-060 fix - remove loading overlay)
                if (window.location.reload) {
                    window.location.reload();
                }
            }
        }).catch(error => {
            this.showToast('Failed to switch company', 'error');
            console.error('Company switch error:', error);
        });
    }

    handleGlobalKeyboard(e) {
        // Ctrl/Cmd + K - Quick search
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            this.openQuickSearch();
        }

        // Ctrl/Cmd + / - Toggle sidebar
        if ((e.ctrlKey || e.metaKey) && e.key === '/') {
            e.preventDefault();
            this.toggleSidebar();
        }

        // Escape - Close modals
        if (e.key === 'Escape') {
            this.closeActiveModal();
        }
    }

    async apiCall(endpoint, options = {}) {
        const defaultOptions = {
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        // Add CSRF token if available
        const csrfToken = document.getElementById('csrfToken');
        if (csrfToken) {
            defaultOptions.headers['X-CSRF-Token'] = csrfToken.value;
        }

        try {
            const response = await fetch(this.config.apiBase + endpoint, {
                ...defaultOptions,
                ...options,
                headers: {
                    ...defaultOptions.headers,
                    ...(options.headers || {})
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            return data;
        } catch (error) {
            console.error('API Error:', error);
            this.showToast('Errore di comunicazione con il server', 'error');
            throw error;
        }
    }

    async apiCallSilent(endpoint, options = {}) {
        // Silent version of apiCall that doesn't show error toasts
        const defaultOptions = {
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        // Add CSRF token if available
        const csrfToken = document.getElementById('csrfToken');
        if (csrfToken) {
            defaultOptions.headers['X-CSRF-Token'] = csrfToken.value;
        }

        try {
            const response = await fetch(this.config.apiBase + endpoint, {
                ...defaultOptions,
                ...options,
                headers: {
                    ...defaultOptions.headers,
                    ...(options.headers || {})
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();
            return data;
        } catch (error) {
            // Only log to console, don't show toast
            console.debug('Silent API call failed:', endpoint, error.message);
            throw error;
        }
    }

    handleAjaxForm(form) {
        const formData = new FormData(form);
        const submitBtn = form.querySelector('[type="submit"]');

        // Disable submit button
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('loading');
        }

        // Get form action and method
        const action = form.action || window.location.href;
        const method = form.method || 'POST';

        fetch(action, {
            method: method,
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showToast(data.message || 'Operation successful', 'success');

                // Handle redirect if specified
                if (data.redirect) {
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                }

                // Reset form if specified
                if (data.reset) {
                    form.reset();
                }

                // Trigger custom event
                form.dispatchEvent(new CustomEvent('ajax-success', { detail: data }));
            } else {
                this.showToast(data.message || 'Operation failed', 'error');

                // Show field errors
                if (data.errors) {
                    this.showFormErrors(form, data.errors);
                }
            }
        })
        .catch(error => {
            console.error('Form submission error:', error);
            this.showToast('Error submitting form', 'error');
        })
        .finally(() => {
            // Re-enable submit button
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('loading');
            }
        });
    }

    showFormErrors(form, errors) {
        // Clear previous errors
        form.querySelectorAll('.error-message').forEach(el => el.remove());
        form.querySelectorAll('.error').forEach(el => el.classList.remove('error'));

        // Show new errors
        Object.keys(errors).forEach(field => {
            const input = form.querySelector(`[name="${field}"]`);
            if (input) {
                input.classList.add('error');
                const errorMsg = document.createElement('span');
                errorMsg.className = 'error-message';
                errorMsg.textContent = errors[field];
                input.parentNode.insertBefore(errorMsg, input.nextSibling);
            }
        });
    }

    initTooltips() {
        document.querySelectorAll('[data-tooltip]').forEach(element => {
            element.addEventListener('mouseenter', (e) => {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = element.dataset.tooltip;
                document.body.appendChild(tooltip);

                const rect = element.getBoundingClientRect();
                tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';

                element._tooltip = tooltip;
            });

            element.addEventListener('mouseleave', () => {
                if (element._tooltip) {
                    element._tooltip.remove();
                    delete element._tooltip;
                }
            });
        });
    }

    initDropdowns() {
        document.querySelectorAll('.dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();

                const dropdown = toggle.nextElementSibling;
                if (dropdown && dropdown.classList.contains('dropdown-menu')) {
                    dropdown.classList.toggle('show');

                    // Close on outside click
                    const closeDropdown = (event) => {
                        if (!toggle.contains(event.target) && !dropdown.contains(event.target)) {
                            dropdown.classList.remove('show');
                            document.removeEventListener('click', closeDropdown);
                        }
                    };

                    if (dropdown.classList.contains('show')) {
                        setTimeout(() => {
                            document.addEventListener('click', closeDropdown);
                        }, 0);
                    }
                }
            });
        });
    }

    initModals() {
        // Open modal triggers
        document.querySelectorAll('[data-modal]').forEach(trigger => {
            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                const modalId = trigger.dataset.modal;
                this.openModal(modalId);
            });
        });

        // Close modal buttons
        document.querySelectorAll('.modal-close, [data-dismiss="modal"]').forEach(closeBtn => {
            closeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                const modal = closeBtn.closest('.modal');
                if (modal) {
                    this.closeModal(modal.id);
                }
            });
        });

        // Close on backdrop click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.closeModal(modal.id);
                }
            });
        });
    }

    openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('show');
            document.body.classList.add('modal-open');

            // Trigger opened event
            modal.dispatchEvent(new CustomEvent('modal-opened'));

            // Focus first input
            const firstInput = modal.querySelector('input, textarea, select');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 100);
            }
        }
    }

    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('show');
            document.body.classList.remove('modal-open');

            // Trigger closed event
            modal.dispatchEvent(new CustomEvent('modal-closed'));
        }
    }

    closeActiveModal() {
        const activeModal = document.querySelector('.modal.show');
        if (activeModal) {
            this.closeModal(activeModal.id);
        }
    }

    initTableSorting() {
        document.querySelectorAll('table.sortable').forEach(table => {
            const headers = table.querySelectorAll('th[data-sort]');

            headers.forEach(header => {
                header.style.cursor = 'pointer';
                header.addEventListener('click', () => {
                    const column = header.dataset.sort;
                    const order = header.dataset.order === 'asc' ? 'desc' : 'asc';

                    // Update header
                    headers.forEach(h => {
                        h.classList.remove('sort-asc', 'sort-desc');
                        delete h.dataset.order;
                    });
                    header.dataset.order = order;
                    header.classList.add(`sort-${order}`);

                    // Sort table
                    this.sortTable(table, column, order);
                });
            });
        });
    }

    sortTable(table, column, order) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort((a, b) => {
            const aValue = a.querySelector(`[data-value="${column}"]`)?.textContent ||
                          a.children[parseInt(column)]?.textContent || '';
            const bValue = b.querySelector(`[data-value="${column}"]`)?.textContent ||
                          b.children[parseInt(column)]?.textContent || '';

            // Try to parse as number
            const aNum = parseFloat(aValue);
            const bNum = parseFloat(bValue);

            if (!isNaN(aNum) && !isNaN(bNum)) {
                return order === 'asc' ? aNum - bNum : bNum - aNum;
            }

            // Sort as string
            return order === 'asc' ?
                aValue.localeCompare(bValue) :
                bValue.localeCompare(aValue);
        });

        // Re-append sorted rows
        rows.forEach(row => tbody.appendChild(row));
    }

    initSearchInputs() {
        document.querySelectorAll('[data-search]').forEach(input => {
            let timeout;

            input.addEventListener('input', (e) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    this.handleSearch(input);
                }, this.config.debounceDelay);
            });
        });
    }

    handleSearch(input) {
        const target = input.dataset.search;
        const query = input.value.toLowerCase();

        // Find target elements
        const elements = document.querySelectorAll(target);

        elements.forEach(element => {
            const text = element.textContent.toLowerCase();
            const matches = text.includes(query);

            element.style.display = matches ? '' : 'none';

            // Highlight matches
            if (matches && query) {
                this.highlightText(element, query);
            } else {
                this.removeHighlight(element);
            }
        });

        // Show empty state if no results
        const visibleCount = Array.from(elements).filter(el =>
            el.style.display !== 'none'
        ).length;

        this.toggleEmptyState(target, visibleCount === 0);
    }

    highlightText(element, query) {
        // Implementation for text highlighting
        // This is a simplified version
        const walker = document.createTreeWalker(
            element,
            NodeFilter.SHOW_TEXT,
            null,
            false
        );

        let node;
        while (node = walker.nextNode()) {
            if (node.parentElement.classList.contains('highlight')) continue;

            const text = node.textContent;
            const index = text.toLowerCase().indexOf(query);

            if (index >= 0) {
                const span = document.createElement('span');
                span.className = 'highlight';
                span.textContent = text.substring(index, index + query.length);

                const after = node.splitText(index);
                after.textContent = after.textContent.substring(query.length);
                node.parentElement.insertBefore(span, after);
            }
        }
    }

    removeHighlight(element) {
        element.querySelectorAll('.highlight').forEach(span => {
            const parent = span.parentNode;
            parent.replaceChild(document.createTextNode(span.textContent), span);
            parent.normalize();
        });
    }

    toggleEmptyState(selector, show) {
        const container = document.querySelector(selector)?.parentElement;
        if (container) {
            let emptyState = container.querySelector('.empty-state');

            if (show && !emptyState) {
                emptyState = document.createElement('div');
                emptyState.className = 'empty-state';
                emptyState.innerHTML = `
                    <p>No results found</p>
                `;
                container.appendChild(emptyState);
            } else if (!show && emptyState) {
                emptyState.remove();
            }
        }
    }

    initNotifications() {
        // Check for new notifications periodically
        if (this.config.pollInterval > 0) {
            setInterval(() => {
                this.checkNotifications();
            }, this.config.pollInterval);
        }

        // Initial check
        this.checkNotifications();
    }

    async checkNotifications() {
        try {
            // Use a special flag to suppress error toasts for notifications
            const response = await this.apiCallSilent('notifications/unread.php');
            if (response && response.notifications) {
                this.updateNotificationBadge(response.notifications.length);
                this.state.notifications = response.notifications;
            }
        } catch (error) {
            // Silent fail for notifications - this is expected if notifications system is not set up
            console.debug('Notifications check skipped:', error.message);
        }
    }

    updateNotificationBadge(count) {
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = count > 0 ? 'block' : 'none';
        }
    }

    loadUserSession() {
        // Load saved preferences
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (sidebarCollapsed) {
            document.querySelector('.sidebar')?.classList.add('collapsed');
            document.querySelector('.main-content')?.classList.add('sidebar-collapsed');
        }

        // Load theme preference
        const theme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', theme);
    }

    initDatePicker(input) {
        // Simple date picker initialization
        input.type = 'date';
        input.addEventListener('change', (e) => {
            input.dispatchEvent(new CustomEvent('date-changed', {
                detail: { date: e.target.value }
            }));
        });
    }

    initTimePicker(input) {
        // Simple time picker initialization
        input.type = 'time';
        input.addEventListener('change', (e) => {
            input.dispatchEvent(new CustomEvent('time-changed', {
                detail: { time: e.target.value }
            }));
        });
    }

    initSelect2(select) {
        // Basic enhanced select functionality
        select.classList.add('enhanced-select');

        // Add search functionality for selects with many options
        if (select.options.length > 10) {
            const wrapper = document.createElement('div');
            wrapper.className = 'select-wrapper';

            const search = document.createElement('input');
            search.type = 'text';
            search.className = 'select-search';
            search.placeholder = 'Search...';

            select.parentNode.insertBefore(wrapper, select);
            wrapper.appendChild(search);
            wrapper.appendChild(select);

            search.addEventListener('input', (e) => {
                const query = e.target.value.toLowerCase();
                Array.from(select.options).forEach(option => {
                    const matches = option.text.toLowerCase().includes(query);
                    option.style.display = matches ? '' : 'none';
                });
            });
        }
    }

    initChart(container) {
        // Placeholder for chart initialization
        // Would integrate with Chart.js or similar library
        console.log('Chart initialization for:', container);
    }

    initDataTable(table) {
        // Enhanced table functionality
        table.classList.add('enhanced-table');

        // Add pagination if many rows
        const tbody = table.querySelector('tbody');
        const rows = tbody ? tbody.querySelectorAll('tr') : [];

        if (rows.length > 20) {
            this.addPagination(table, rows);
        }
    }

    addPagination(table, rows) {
        const perPage = 20;
        let currentPage = 1;
        const totalPages = Math.ceil(rows.length / perPage);

        const pagination = document.createElement('div');
        pagination.className = 'table-pagination';

        const showPage = (page) => {
            rows.forEach((row, index) => {
                const start = (page - 1) * perPage;
                const end = start + perPage;
                row.style.display = (index >= start && index < end) ? '' : 'none';
            });

            // Update pagination buttons
            pagination.querySelector('.page-info').textContent =
                `Page ${page} of ${totalPages}`;
            pagination.querySelector('.prev-page').disabled = page === 1;
            pagination.querySelector('.next-page').disabled = page === totalPages;
        };

        pagination.innerHTML = `
            <button class="prev-page">Previous</button>
            <span class="page-info">Page 1 of ${totalPages}</span>
            <button class="next-page">Next</button>
        `;

        pagination.querySelector('.prev-page').addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                showPage(currentPage);
            }
        });

        pagination.querySelector('.next-page').addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage++;
                showPage(currentPage);
            }
        });

        table.parentNode.insertBefore(pagination, table.nextSibling);
        showPage(1);
    }

    openQuickSearch() {
        // Create quick search modal
        let quickSearch = document.getElementById('quickSearch');

        if (!quickSearch) {
            quickSearch = document.createElement('div');
            quickSearch.id = 'quickSearch';
            quickSearch.className = 'quick-search-modal';
            quickSearch.innerHTML = `
                <div class="quick-search-content">
                    <input type="text" class="quick-search-input" placeholder="Search everything...">
                    <div class="quick-search-results"></div>
                </div>
            `;
            document.body.appendChild(quickSearch);

            const input = quickSearch.querySelector('.quick-search-input');
            input.addEventListener('input', (e) => {
                this.performQuickSearch(e.target.value);
            });

            quickSearch.addEventListener('click', (e) => {
                if (e.target === quickSearch) {
                    quickSearch.classList.remove('show');
                }
            });
        }

        quickSearch.classList.add('show');
        quickSearch.querySelector('.quick-search-input').focus();
    }

    performQuickSearch(query) {
        const results = document.querySelector('.quick-search-results');
        if (!results) return;

        if (query.length < 2) {
            results.innerHTML = '';
            return;
        }

        // Simulate search results
        results.innerHTML = `
            <div class="search-result-item">
                <span class="result-icon">📄</span>
                <span class="result-text">Searching for: ${query}</span>
            </div>
        `;
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                ${this.getToastIcon(type)}
                <span class="toast-message">${message}</span>
            </div>
        `;

        // Style the toast
        toast.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            padding: 12px 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 10000;
            animation: slideInUp 0.3s ease-out;
            max-width: 400px;
        `;

        // Add type-specific styling
        if (type === 'success') {
            toast.style.borderColor = '#10B981';
            toast.style.backgroundColor = '#F0FDF4';
        } else if (type === 'error') {
            toast.style.borderColor = '#EF4444';
            toast.style.backgroundColor = '#FEF2F2';
        } else if (type === 'warning') {
            toast.style.borderColor = '#F59E0B';
            toast.style.backgroundColor = '#FFFBEB';
        }

        document.body.appendChild(toast);

        // Auto remove after duration
        setTimeout(() => {
            toast.style.animation = 'slideOutDown 0.3s ease-out';
            setTimeout(() => toast.remove(), 300);
        }, this.config.toastDuration);
    }

    getToastIcon(type) {
        const icons = {
            success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>',
            error: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
            warning: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            info: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
        };
        return icons[type] || icons.info;
    }

    setupAjaxDefaults() {
        // Set up global AJAX error handling
        window.addEventListener('unhandledrejection', event => {
            console.error('Unhandled promise rejection:', event.reason);
            this.showToast('An unexpected error occurred', 'error');
        });
    }

    // Utility methods
    debounce(func, wait) {
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

    throttle(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    formatDate(date, format = 'DD/MM/YYYY') {
        const d = new Date(date);
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();

        return format
            .replace('DD', day)
            .replace('MM', month)
            .replace('YYYY', year);
    }

    formatCurrency(amount, currency = 'EUR') {
        return new Intl.NumberFormat('it-IT', {
            style: 'currency',
            currency: currency
        }).format(amount);
    }
}

    // Initialize app when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        window.app = new App();
    });

    // Add animation keyframes
    const appAnimationStyles = document.createElement('style');
    appAnimationStyles.textContent = `
    @keyframes slideInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideOutDown {
        from {
            opacity: 1;
            transform: translateY(0);
        }
        to {
            opacity: 0;
            transform: translateY(20px);
        }
    }

    .toast-content {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .toast-message {
        flex: 1;
        font-size: 14px;
        line-height: 1.5;
    }

    .highlight {
        background-color: #FEF3C7;
        padding: 2px 4px;
        border-radius: 2px;
    }

    .enhanced-select {
        min-width: 200px;
    }

    .select-wrapper {
        position: relative;
    }

    .select-search {
        position: absolute;
        top: -30px;
        left: 0;
        right: 0;
        padding: 4px 8px;
        border: 1px solid #E5E7EB;
        border-radius: 4px;
        font-size: 12px;
    }

    .table-pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 16px;
        padding: 16px;
        border-top: 1px solid #E5E7EB;
    }

    .table-pagination button {
        padding: 6px 12px;
        border: 1px solid #E5E7EB;
        border-radius: 4px;
        background: white;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.2s;
    }

    .table-pagination button:hover:not(:disabled) {
        background: #F9FAFB;
    }

    .table-pagination button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .quick-search-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 10000;
        align-items: flex-start;
        justify-content: center;
        padding-top: 100px;
    }

    .quick-search-modal.show {
        display: flex;
    }

    .quick-search-content {
        background: white;
        border-radius: 12px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
        width: 90%;
        max-width: 600px;
        overflow: hidden;
    }

    .quick-search-input {
        width: 100%;
        padding: 20px;
        border: none;
        font-size: 18px;
        outline: none;
    }

    .quick-search-results {
        max-height: 400px;
        overflow-y: auto;
        border-top: 1px solid #E5E7EB;
    }

    .search-result-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 20px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .search-result-item:hover {
        background: #F9FAFB;
    }

    .result-icon {
        font-size: 20px;
    }

    .result-text {
        flex: 1;
        font-size: 14px;
    }

    .loading {
        position: relative;
        color: transparent;
    }

    .loading::after {
        content: '';
        position: absolute;
        width: 16px;
        height: 16px;
        top: 50%;
        left: 50%;
        margin-left: -8px;
        margin-top: -8px;
        border: 2px solid #E5E7EB;
        border-radius: 50%;
        border-top-color: #3B82F6;
        animation: spin 0.6s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .error {
        border-color: #EF4444 !important;
    }

    .error-message {
        color: #EF4444;
        font-size: 12px;
        margin-top: 4px;
        display: block;
    }

    .empty-state {
        text-align: center;
        padding: 40px;
        color: #6B7280;
    }

    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }

    .modal.show {
        display: flex;
    }

    .modal-open {
        overflow: hidden;
    }

    .sidebar.collapsed {
        width: 60px;
    }

    .sidebar.collapsed .nav-item span,
    .sidebar.collapsed .sidebar-subtitle,
    .sidebar.collapsed .logo-text,
    .sidebar.collapsed .user-details,
    .sidebar.collapsed .nav-section-title {
        display: none;
    }

    .main-content.sidebar-collapsed {
        margin-left: 60px;
    }

    [data-tooltip] {
        position: relative;
    }

    .tooltip {
        position: absolute;
        background: #1F2937;
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        white-space: nowrap;
        z-index: 10001;
        pointer-events: none;
    }

    .tooltip::after {
        content: '';
        position: absolute;
        bottom: -4px;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 0;
        border-left: 4px solid transparent;
        border-right: 4px solid transparent;
        border-top: 4px solid #1F2937;
        }
    `;
    document.head.appendChild(appAnimationStyles);
})();