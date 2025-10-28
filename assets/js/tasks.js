/**
 * Task Management System - Frontend Controller
 *
 * Handles all task CRUD operations, drag-and-drop, modals, and real-time updates
 *
 * @requires jQuery (optional - can work without)
 * @version 1.0.0
 */

class TaskManager {
    constructor() {
        // Configuration
        this.config = {
            apiBase: '/CollaboraNexio/api/tasks/',
            csrfToken: document.getElementById('csrfToken')?.value || '',
            refreshInterval: 30000, // 30 seconds
        };

        // State
        this.state = {
            tasks: [],
            users: [],
            currentFilter: 'all',
            draggedTask: null,
            deleteTaskId: null,
            orphanedCount: 0
        };

        // Initialize
        this.init();
    }

    /**
     * Initialize the task manager
     */
    async init() {
        console.log('[TaskManager] Initializing...');

        try {
            // Load initial data
            await this.loadUsers();
            await this.loadTasks();
            await this.checkOrphanedTasks();

            // Setup event listeners
            this.bindEvents();
            this.initDragAndDrop();

            // Setup auto-refresh
            this.setupAutoRefresh();

            console.log('[TaskManager] Initialized successfully');
        } catch (error) {
            console.error('[TaskManager] Initialization failed:', error);
            this.showToast('Errore caricamento task', 'error');
        }
    }

    /**
     * Load all tasks from API
     */
    async loadTasks() {
        try {
            const response = await this.apiRequest('list.php', {
                method: 'GET'
            });

            if (response.success) {
                // Extract tasks array from nested data structure
                this.state.tasks = response.data?.tasks || [];
                console.log('[TaskManager] Loaded tasks:', this.state.tasks.length);
                this.renderTasks();
                this.updateTaskCounts();
            } else {
                throw new Error(response.message || 'Errore caricamento task');
            }
        } catch (error) {
            console.error('[TaskManager] Load tasks failed:', error);
            throw error;
        }
    }

    /**
     * Load all users for assignment dropdown
     */
    async loadUsers() {
        try {
            const response = await fetch('/CollaboraNexio/api/users/list.php', {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.config.csrfToken
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                // Extract users array from nested data structure
                this.state.users = data.data?.users || [];
                console.log('[TaskManager] Loaded users:', this.state.users.length);
                this.populateUserDropdown();
            }
        } catch (error) {
            console.error('[TaskManager] Load users failed:', error);
        }
    }

    /**
     * Populate user dropdown in modal
     */
    populateUserDropdown() {
        const select = document.getElementById('taskAssignees');
        if (!select) return;

        select.innerHTML = this.state.users
            .filter(user => !user.deleted_at)
            .map(user => `
                <option value="${user.id}">
                    ${user.name} (${user.email})
                </option>
            `)
            .join('');
    }

    /**
     * Render all tasks in kanban board
     */
    renderTasks() {
        const statuses = ['todo', 'in_progress', 'review', 'done'];

        statuses.forEach(status => {
            const container = document.getElementById(`tasks-${status}`);
            if (!container) return;

            const tasksForStatus = this.state.tasks.filter(task => task.status === status);

            if (tasksForStatus.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <p>Nessun task in questa colonna</p>
                    </div>
                `;
            } else {
                container.innerHTML = tasksForStatus
                    .map(task => this.renderTaskCard(task))
                    .join('');
            }
        });
    }

    /**
     * Render single task card
     */
    renderTaskCard(task) {
        const priorityClass = {
            'low': 'priority-low',
            'medium': 'priority-medium',
            'high': 'priority-high',
            'critical': 'priority-high'
        }[task.priority] || 'priority-medium';

        const priorityLabel = {
            'low': 'Bassa',
            'medium': 'Media',
            'high': 'Alta',
            'critical': 'Critica'
        }[task.priority] || 'Media';

        const assigneeInfo = task.assignee_name
            ? `<div class="task-assignee">
                 <span class="assignee-avatar">${task.assignee_name.substring(0, 2).toUpperCase()}</span>
                 <span>${task.assignee_name}</span>
               </div>`
            : `<div class="task-assignee">
                 <span class="text-muted">Non assegnato</span>
               </div>`;

        // Show edit/delete buttons for super_admin
        const actions = `
            <div class="task-card-actions">
                <button class="task-card-btn btn-edit" onclick="taskManager.openEditTaskModal(${task.id})" title="Modifica">
                    ✏️
                </button>
                <button class="task-card-btn btn-delete" onclick="taskManager.deleteTask(${task.id})" title="Elimina">
                    🗑️
                </button>
            </div>
        `;

        return `
            <div class="task-card" data-task-id="${task.id}" draggable="true">
                ${actions}
                <div class="task-title">${this.escapeHtml(task.title)}</div>
                <div class="task-description">${this.escapeHtml(task.description || '')}</div>
                <div class="task-meta">
                    <span class="task-priority ${priorityClass}">${priorityLabel}</span>
                    ${assigneeInfo}
                </div>
            </div>
        `;
    }

    /**
     * Update task counts in column headers
     */
    updateTaskCounts() {
        const statuses = ['todo', 'in_progress', 'review', 'done'];

        statuses.forEach(status => {
            const count = this.state.tasks.filter(task => task.status === status).length;
            const countEl = document.getElementById(`count-${status}`);
            if (countEl) {
                countEl.textContent = count;
            }
        });
    }

    /**
     * Check for orphaned tasks
     */
    async checkOrphanedTasks() {
        try {
            const response = await this.apiRequest('orphaned.php', {
                method: 'GET'
            });

            if (response.success) {
                this.state.orphanedCount = response.data.count || 0;
                this.displayOrphanedWarning();
            }
        } catch (error) {
            console.error('[TaskManager] Check orphaned failed:', error);
        }
    }

    /**
     * Display orphaned tasks warning
     */
    displayOrphanedWarning() {
        const warning = document.getElementById('orphanedWarning');
        const countEl = document.getElementById('orphanedCount');

        if (warning && countEl) {
            if (this.state.orphanedCount > 0) {
                countEl.textContent = this.state.orphanedCount;
                warning.style.display = 'block';
            } else {
                warning.style.display = 'none';
            }
        }
    }

    /**
     * Open modal for new task
     */
    openNewTaskModal() {
        document.getElementById('modalTitle').textContent = 'Nuovo Task';
        document.getElementById('taskForm').reset();
        document.getElementById('taskId').value = '';
        document.getElementById('taskModal').style.display = 'flex';
    }

    /**
     * Open modal for edit task
     */
    async openEditTaskModal(taskId) {
        try {
            const task = this.state.tasks.find(t => t.id === taskId);
            if (!task) {
                this.showToast('Task non trovato', 'error');
                return;
            }

            document.getElementById('modalTitle').textContent = 'Modifica Task';
            document.getElementById('taskId').value = task.id;
            document.getElementById('taskTitle').value = task.title;
            document.getElementById('taskDescription').value = task.description || '';
            document.getElementById('taskStatus').value = task.status;
            document.getElementById('taskPriority').value = task.priority;
            document.getElementById('taskDueDate').value = task.due_date || '';
            document.getElementById('taskProgress').value = task.progress || 0;

            // Select assigned users
            const assigneesSelect = document.getElementById('taskAssignees');
            if (assigneesSelect && task.assignee_ids) {
                const ids = task.assignee_ids.split(',').map(id => id.trim());
                Array.from(assigneesSelect.options).forEach(option => {
                    option.selected = ids.includes(option.value);
                });
            }

            document.getElementById('taskModal').style.display = 'flex';
        } catch (error) {
            console.error('[TaskManager] Open edit modal failed:', error);
            this.showToast('Errore apertura modal', 'error');
        }
    }

    /**
     * Close task modal
     */
    closeModal() {
        document.getElementById('taskModal').style.display = 'none';
        document.getElementById('taskForm').reset();
    }

    /**
     * Submit task form (create or update)
     */
    async submitTask(event) {
        event.preventDefault();

        const form = event.target;
        const formData = new FormData(form);
        const taskId = formData.get('id');

        // Get selected assignees
        const assigneesSelect = document.getElementById('taskAssignees');
        const assignees = Array.from(assigneesSelect.selectedOptions).map(opt => opt.value);

        const data = {
            title: formData.get('title'),
            description: formData.get('description'),
            status: formData.get('status'),
            priority: formData.get('priority'),
            due_date: formData.get('due_date'),
            progress: formData.get('progress'),
            assignees: assignees
        };

        if (taskId) {
            data.id = taskId;
        }

        try {
            const endpoint = taskId ? 'update.php' : 'create.php';
            const response = await this.apiRequest(endpoint, {
                method: 'POST',
                body: JSON.stringify(data)
            });

            if (response.success) {
                this.showToast(taskId ? 'Task aggiornato' : 'Task creato', 'success');
                this.closeModal();
                await this.loadTasks();
            } else {
                this.showToast(response.message || 'Errore salvataggio task', 'error');
            }
        } catch (error) {
            console.error('[TaskManager] Submit task failed:', error);
            this.showToast('Errore salvataggio task', 'error');
        }
    }

    /**
     * Delete task (soft delete)
     */
    async deleteTask(taskId) {
        this.state.deleteTaskId = taskId;
        document.getElementById('deleteModal').style.display = 'flex';
    }

    /**
     * Confirm delete task
     */
    async confirmDelete() {
        const taskId = this.state.deleteTaskId;

        try {
            const response = await this.apiRequest('delete.php', {
                method: 'DELETE',
                body: JSON.stringify({ id: taskId })
            });

            if (response.success) {
                this.showToast('Task eliminato', 'success');
                this.closeDeleteModal();
                await this.loadTasks();
            } else {
                this.showToast(response.message || 'Errore eliminazione task', 'error');
            }
        } catch (error) {
            console.error('[TaskManager] Delete task failed:', error);
            this.showToast('Errore eliminazione task', 'error');
        }
    }

    /**
     * Close delete modal
     */
    closeDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
        this.state.deleteTaskId = null;
    }

    /**
     * Show orphaned tasks
     */
    async showOrphanedTasks(event) {
        if (event) event.preventDefault();

        try {
            const response = await this.apiRequest('orphaned.php', {
                method: 'GET'
            });

            if (response.success && response.data.tasks) {
                const tasks = response.data.tasks;

                // Build message
                let message = `Trovati ${tasks.length} task orfani:\n\n`;
                tasks.forEach(task => {
                    message += `- ${task.title} (ID: ${task.id})\n`;
                });
                message += '\nRiassegna questi task dalla modalità modifica.';

                alert(message);
            }
        } catch (error) {
            console.error('[TaskManager] Show orphaned failed:', error);
            this.showToast('Errore recupero task orfani', 'error');
        }
    }

    /**
     * Initialize drag and drop
     */
    initDragAndDrop() {
        document.addEventListener('dragstart', (e) => {
            if (e.target.classList.contains('task-card')) {
                e.target.classList.add('dragging');
                this.state.draggedTask = e.target;
            }
        });

        document.addEventListener('dragend', (e) => {
            if (e.target.classList.contains('task-card')) {
                e.target.classList.remove('dragging');
                this.state.draggedTask = null;
            }
        });

        document.querySelectorAll('.task-column').forEach(column => {
            column.addEventListener('dragover', (e) => {
                e.preventDefault();
                column.classList.add('drag-over');
            });

            column.addEventListener('dragleave', () => {
                column.classList.remove('drag-over');
            });

            column.addEventListener('drop', async (e) => {
                e.preventDefault();
                column.classList.remove('drag-over');

                if (this.state.draggedTask) {
                    const taskId = this.state.draggedTask.getAttribute('data-task-id');
                    const newStatus = column.getAttribute('data-status');

                    await this.updateTaskStatus(taskId, newStatus);
                }
            });
        });
    }

    /**
     * Update task status (from drag-and-drop)
     */
    async updateTaskStatus(taskId, newStatus) {
        try {
            const response = await this.apiRequest('update.php', {
                method: 'POST',
                body: JSON.stringify({
                    id: taskId,
                    status: newStatus
                })
            });

            if (response.success) {
                await this.loadTasks();
                this.showToast('Status aggiornato', 'success');
            } else {
                this.showToast(response.message || 'Errore aggiornamento status', 'error');
            }
        } catch (error) {
            console.error('[TaskManager] Update status failed:', error);
            this.showToast('Errore aggiornamento status', 'error');
        }
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        // Filter buttons
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                // TODO: Apply filter
            });
        });

        // Close modals on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
                this.closeDeleteModal();
            }
        });
    }

    /**
     * Setup auto-refresh
     */
    setupAutoRefresh() {
        setInterval(() => {
            this.loadTasks();
            this.checkOrphanedTasks();
        }, this.config.refreshInterval);
    }

    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-icon">
                ${type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ'}
            </div>
            <div class="toast-message">${message}</div>
        `;

        container.appendChild(toast);

        setTimeout(() => {
            toast.remove();
        }, 5000);
    }

    /**
     * Make API request
     */
    async apiRequest(endpoint, options = {}) {
        const url = this.config.apiBase + endpoint;

        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.config.csrfToken
            },
            credentials: 'same-origin'
        };

        const response = await fetch(url, {
            ...defaultOptions,
            ...options,
            headers: {
                ...defaultOptions.headers,
                ...options.headers
            }
        });

        return await response.json();
    }

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.taskManager = new TaskManager();
});