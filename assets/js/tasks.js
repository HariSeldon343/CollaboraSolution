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
            userRole: window.userRole || 'user',
            currentUserId: window.currentUserId || null,
            activeCompanyFilterId: (typeof window.activeCompanyFilterId === 'number') ? window.activeCompanyFilterId : null,
            activeCompanyFilterName: window.activeCompanyFilterName || ''
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

            // Super admin: disable create/edit if no company selected
            this.applyCompanySelectionRules();

            console.log('[TaskManager] Initialized successfully');
        } catch (error) {
            console.error('[TaskManager] Initialization failed:', error);
            this.showToast('Errore caricamento task', 'error');
        }
    }

    applyCompanySelectionRules() {
        if (this.config.userRole !== 'super_admin') return;
        const hasCompany = !!this.config.activeCompanyFilterId;
        const newBtn = document.getElementById('newTaskBtn');
        if (newBtn) {
            newBtn.disabled = !hasCompany;
            newBtn.style.opacity = hasCompany ? '1' : '0.6';
            newBtn.style.cursor = hasCompany ? 'pointer' : 'not-allowed';
        }
    }

    /**
     * Load all tasks from API
     */
    async loadTasks() {
        try {
            const response = await this.apiRequest('list', {
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
            // Super admin must select a company before assignment list can be loaded
            if (this.config.userRole === 'super_admin' && !this.config.activeCompanyFilterId) {
                this.state.users = [];
                this.populateUserDropdown();
                return;
            }

            // Scope users to the active tenant for assignment dropdown
            const response = await fetch('/CollaboraNexio/api/users/list.php?scope=tenant&limit=200', {
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

        if (!this.state.users.length) {
            // Super admin without company selection: show hint
            if (this.config.userRole === 'super_admin' && !this.config.activeCompanyFilterId) {
                select.innerHTML = '<option disabled selected>Seleziona prima un’azienda</option>';
                return;
            }
            select.innerHTML = '<option disabled selected>Nessun utente disponibile</option>';
            return;
        }

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
        const filtered = this.getFilteredTasks();

        statuses.forEach(status => {
            const container = document.getElementById(`tasks-${status}`);
            if (!container) return;

            const tasksForStatus = filtered.filter(task => task.status === status);

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

        const displayAssignee = this.getAssigneeName(task);
        const assigneeInfo = displayAssignee
            ? `<div class="task-assignee">
                 <span class="assignee-avatar">${displayAssignee.substring(0, 2).toUpperCase()}</span>
                 <span>${displayAssignee}</span>
               </div>`
            : `<div class="task-assignee">
                 <span class="text-muted">Non assegnato</span>
               </div>`;

        const canFullEdit = this.canEditTaskFull(task);
        const canDelete = canFullEdit; // creator or super_admin (with company) only

        // Show edit/delete buttons only when allowed
        const actions = (canFullEdit || canDelete)
            ? `
                <div class="task-card-actions">
                    ${canFullEdit ? `
                        <button class="task-card-btn btn-edit" onclick="taskManager.openEditTaskModal(${task.id})" title="Modifica">
                            ✏️
                        </button>
                    ` : ''}
                    ${canDelete ? `
                        <button class="task-card-btn btn-delete" onclick="taskManager.deleteTask(${task.id})" title="Elimina">
                            🗑️
                        </button>
                    ` : ''}
                </div>
              `
            : '';

        // Super admin: show tenant label when viewing all companies
        const tenantLabel = (this.config.userRole === 'super_admin' && !this.config.activeCompanyFilterId && task.tenant_name)
            ? `<div class="text-muted" style="font-size: 12px; margin-top: 4px;">Azienda: ${this.escapeHtml(task.tenant_name)}</div>`
            : '';

        const canDrag = this.canEditTaskFull(task);
        return `
            <div class="task-card" data-task-id="${task.id}" draggable="${canDrag ? 'true' : 'false'}" style="${canDrag ? '' : 'cursor: default;'}">
                ${actions}
                <div class="task-title">${this.escapeHtml(task.title)}</div>
                <div class="task-description">${this.escapeHtml(task.description || '')}</div>
                ${tenantLabel}
                <div class="task-meta">
                    <span class="task-priority ${priorityClass}">${priorityLabel}</span>
                    ${assigneeInfo}
                </div>
            </div>
        `;
    }

    /**
     * Filtering logic for top buttons
     */
    getFilteredTasks() {
        const filter = this.state.currentFilter || 'all';
        const tasks = this.state.tasks || [];
        const userId = this.config.currentUserId ? parseInt(this.config.currentUserId, 10) : null;

        const isMine = (t) => {
            if (!userId) return false;
            if (parseInt(t.assigned_to || 0, 10) === userId) return true;
            if (parseInt(t.created_by || 0, 10) === userId) return true;
            if (t.assignee_ids) {
                const ids = t.assignee_ids.split(',').map(x => parseInt(x.trim(), 10)).filter(n => !isNaN(n));
                return ids.includes(userId);
            }
            return false;
        };

        const isHighPriority = (t) => ['high', 'critical'].includes((t.priority || '').toLowerCase());
        const isCompleted = (t) => (t.status || '').toLowerCase() === 'done';
        const isDueToday = (t) => {
            if (!t.due_date) return false;
            const d = new Date(t.due_date);
            if (isNaN(d.getTime())) return false;
            const now = new Date();
            return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
        };

        switch (filter) {
            case 'mine': return tasks.filter(isMine);
            case 'high': return tasks.filter(isHighPriority);
            case 'today': return tasks.filter(isDueToday);
            case 'done': return tasks.filter(isCompleted);
            case 'all':
            default: return tasks;
        }
    }

    canEditTasks() {
        if (this.config.userRole !== 'super_admin') return false;
        // Super admin must choose a company before editing/creating
        return !!this.config.activeCompanyFilterId;
    }

    /**
     * Full edit permissions (title/status/priority/assignees + drag status):
     * - super_admin with selected company
     * - creator of the task
     */
    canEditTaskFull(task) {
        // Super admin gating (company required)
        if (this.config.userRole === 'super_admin') {
            return !!this.config.activeCompanyFilterId;
        }

        const userId = this.config.currentUserId ? parseInt(this.config.currentUserId, 10) : null;
        if (!userId) return false;
        return parseInt(task.created_by || 0, 10) === userId;
    }

    /**
     * Limited update (progress/comment/due request) for assignee who is NOT creator.
     */
    isLimitedAssignee(task) {
        if (this.canEditTaskFull(task)) return false;
        if (this.config.userRole === 'super_admin') return false;

        const userId = this.config.currentUserId ? parseInt(this.config.currentUserId, 10) : null;
        if (!userId) return false;

        if (parseInt(task.assigned_to || 0, 10) === userId) return true;
        if (task.assignee_ids) {
            const ids = task.assignee_ids.split(',').map(x => parseInt(x.trim(), 10)).filter(n => !isNaN(n));
            return ids.includes(userId);
        }
        return false;
    }

    /**
     * Permission: who can update a task?
     * - super_admin with selected company
     * - creator
     * - any assignee (assigned_to or task_assignments)
     */
    canEditTask(task) {
        // Super admin gating (company required)
        if (this.config.userRole === 'super_admin') {
            return !!this.config.activeCompanyFilterId;
        }

        const userId = this.config.currentUserId ? parseInt(this.config.currentUserId, 10) : null;
        if (!userId) return false;

        if (parseInt(task.created_by || 0, 10) === userId) return true;
        if (parseInt(task.assigned_to || 0, 10) === userId) return true;
        if (task.assignee_ids) {
            const ids = task.assignee_ids.split(',').map(x => parseInt(x.trim(), 10)).filter(n => !isNaN(n));
            if (ids.includes(userId)) return true;
        }
        return false;
    }

    /**
     * Resolve assignee display name with fallbacks
     * - API-provided assignee_name
     * - assigned_to id -> lookup in loaded users
     * - assignee_ids list -> first matching user
     */
    getAssigneeName(task) {
        if (task.assignee_name) return task.assignee_name;

        const candidates = [];
        if (task.assigned_to) candidates.push(parseInt(task.assigned_to, 10));
        if (task.assignee_ids) {
            const ids = task.assignee_ids
                .split(',')
                .map(id => parseInt(id.trim(), 10))
                .filter(id => !isNaN(id));
            candidates.push(...ids);
        }

        if (!candidates.length) return null;

        const userMap = this.state.users?.length
            ? Object.fromEntries(this.state.users.map(u => [parseInt(u.id, 10), u.name]))
            : {};

        for (const id of candidates) {
            if (userMap[id]) {
                return userMap[id];
            }
        }
        return null;
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
            const response = await this.apiRequest('orphaned', {
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
        if (this.config.userRole === 'super_admin' && !this.config.activeCompanyFilterId) {
            this.showToast('Seleziona prima un’azienda per creare un task', 'error');
            return;
        }
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
            if (this.config.userRole === 'super_admin' && !this.config.activeCompanyFilterId) {
                this.showToast('Seleziona prima un’azienda per modificare/assegnare task', 'error');
                return;
            }
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
            document.getElementById('taskProgress').value = (task.progress_percentage ?? task.progress ?? 0);

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

    openAssigneeUpdateModal(taskId) {
        const modal = document.getElementById('assigneeModal');
        if (!modal) {
            this.showToast('Modal assignee non trovata', 'error');
            return;
        }
        const task = this.state.tasks.find(t => parseInt(t.id, 10) === parseInt(taskId, 10));
        if (!task) {
            this.showToast('Task non trovato', 'error');
            return;
        }

        document.getElementById('assigneeTaskId').value = task.id;
        const current = parseInt(task.progress_percentage || 0, 10);
        this.state.assigneeOriginalProgress = current;
        this.setAssigneeProgress(current);
        document.getElementById('assigneeComment').value = '';
        document.getElementById('assigneeRequestedDueDate').value = '';
        document.getElementById('assigneeRequestReason').value = '';
        const rr = document.getElementById('assigneeReopenReason');
        if (rr) rr.value = '';
        this.updateReopenReasonVisibility();

        // Bind progress UI (one-time)
        if (!this.state._assigneeProgressBound) {
            this.state._assigneeProgressBound = true;

            document.querySelectorAll('[data-progress-step]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const v = parseInt(btn.getAttribute('data-progress-step') || '0', 10);
                    this.setAssigneeProgress(v);
                    this.updateReopenReasonVisibility();
                });
            });

            const bar = document.getElementById('assigneeProgressBar');
            if (bar) {
                bar.addEventListener('click', (e) => {
                    const rect = bar.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const pct = Math.max(0, Math.min(1, x / rect.width));
                    const raw = Math.round(pct * 100);
                    const steps = [25, 50, 75, 100];
                    let closest = steps[0];
                    let best = Infinity;
                    for (const s of steps) {
                        const d = Math.abs(raw - s);
                        if (d < best) {
                            best = d;
                            closest = s;
                        }
                    }
                    this.setAssigneeProgress(closest);
                    this.updateReopenReasonVisibility();
                });
            }
        }

        modal.style.display = 'flex';
    }

    setAssigneeProgress(value) {
        const v = Math.max(0, Math.min(100, parseInt(value || 0, 10)));
        const snapped = [0, 25, 50, 75, 100].includes(v) ? v : (Math.round(v / 25) * 25);
        const finalVal = Math.max(0, Math.min(100, snapped));

        const input = document.getElementById('assigneeProgress');
        const label = document.getElementById('assigneeProgressLabel');
        const fill = document.getElementById('assigneeProgressFill');

        if (input) input.value = String(finalVal);
        if (label) label.textContent = `${finalVal}%`;
        if (fill) fill.style.width = `${finalVal}%`;

        document.querySelectorAll('[data-progress-step]').forEach(btn => {
            const step = parseInt(btn.getAttribute('data-progress-step') || '0', 10);
            btn.classList.toggle('active', step === finalVal);
        });
    }

    updateReopenReasonVisibility() {
        const orig = parseInt(this.state.assigneeOriginalProgress || 0, 10);
        const cur = parseInt(document.getElementById('assigneeProgress')?.value || '0', 10);
        const group = document.getElementById('assigneeReopenReasonGroup');
        const input = document.getElementById('assigneeReopenReason');
        if (!group || !input) return;

        const needs = orig >= 100 && cur < 100;
        group.style.display = needs ? 'block' : 'none';
        input.required = needs;
        if (!needs) input.value = '';
    }

    closeAssigneeModal() {
        const modal = document.getElementById('assigneeModal');
        if (modal) {
            modal.style.display = 'none';
            document.getElementById('assigneeForm')?.reset?.();
        }
    }

    async submitAssigneeUpdate(event) {
        event.preventDefault();
        const taskId = document.getElementById('assigneeTaskId')?.value;
        if (!taskId) return;

        const progress = document.getElementById('assigneeProgress')?.value;
        const comment = document.getElementById('assigneeComment')?.value;
        const requestedDueDate = document.getElementById('assigneeRequestedDueDate')?.value;
        const reason = document.getElementById('assigneeRequestReason')?.value;
        const reopenReason = document.getElementById('assigneeReopenReason')?.value;

        const body = { id: taskId };
        if (progress !== null && progress !== undefined && String(progress).trim() !== '') body.progress = parseInt(progress, 10);
        if (comment && String(comment).trim()) body.comment = String(comment).trim();
        if (requestedDueDate && String(requestedDueDate).trim()) body.requested_due_date = String(requestedDueDate).trim();
        if (reason && String(reason).trim()) body.request_reason = String(reason).trim();

        // If reopening (100 -> <100), require reopen_reason
        const orig = parseInt(this.state.assigneeOriginalProgress || 0, 10);
        const cur = ('progress' in body) ? parseInt(body.progress, 10) : orig;
        if (orig >= 100 && cur < 100) {
            if (!reopenReason || !String(reopenReason).trim()) {
                this.showToast('Motivazione riapertura obbligatoria', 'error');
                return;
            }
            body.reopen_reason = String(reopenReason).trim();
        }

        // Require at least one actionable field
        const hasOp = ('progress' in body) || ('comment' in body) || ('requested_due_date' in body);
        if (!hasOp) {
            this.showToast('Inserisci almeno un aggiornamento (avanzamento/commento/richiesta scadenza)', 'error');
            return;
        }

        try {
            const response = await this.apiRequest('update', {
                method: 'POST',
                body: JSON.stringify(body)
            });

            if (response.success) {
                this.closeAssigneeModal();
                await this.loadTasks();
                this.showToast('Aggiornamento inviato', 'success');
            } else {
                this.showToast(response.error || response.message || 'Errore aggiornamento task', 'error');
            }
        } catch (error) {
            console.error('[TaskManager] Assignee update failed:', error);
            this.showToast('Errore aggiornamento task', 'error');
        }
    }

    /**
     * Submit task form (create or update)
     */
    async submitTask(event) {
        event.preventDefault();

        if (this.config.userRole === 'super_admin' && !this.config.activeCompanyFilterId) {
            this.showToast('Seleziona prima un’azienda per salvare task', 'error');
            return;
        }

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
            const endpoint = taskId ? 'update' : 'create';
            const response = await this.apiRequest(endpoint, {
                method: 'POST',
                body: JSON.stringify(data)
            });

            if (response.success) {
                this.showToast(taskId ? 'Task aggiornato' : 'Task creato', 'success');
                this.closeModal();
                await this.loadTasks();

                // BUG-145: Warn if assignment_state indicates no assignees were persisted
                const selectedAssignees = Array.isArray(data.assignees) ? data.assignees.filter(Boolean) : [];
                const persistedCount = response.data?.assignment_state?.assignees_count;
                if (selectedAssignees.length > 0 && (persistedCount === 0 || persistedCount === '0')) {
                    this.showToast('Attenzione: assegnazione non salvata (utente non valido per il tenant)', 'error');
                }
            } else {
                this.showToast(response.error || response.message || 'Errore salvataggio task', 'error');
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
            const response = await this.apiRequest('delete', {
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
            const response = await this.apiRequest('orphaned', {
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
                const taskId = parseInt(e.target.getAttribute('data-task-id') || '0', 10);
                const task = this.state.tasks.find(t => parseInt(t.id, 10) === taskId);
                if (!task || !this.canEditTaskFull(task)) {
                    e.preventDefault();
                    return;
                }
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
            const t = this.state.tasks.find(x => String(x.id) === String(taskId));
            if (t && !this.canEditTaskFull(t)) {
                this.showToast('Non autorizzato a modificare questo task', 'error');
                return;
            }
            const response = await this.apiRequest('update', {
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
                const label = (btn.textContent || '').trim().toLowerCase();
                const map = {
                    'tutti': 'all',
                    'i miei task': 'mine',
                    'alta priorità': 'high',
                    'scadenza oggi': 'today',
                    'completati': 'done'
                };
                this.state.currentFilter = map[label] || 'all';
                this.renderTasks();
                this.updateTaskCounts();
            });
        });

        // Default filter based on active button on load
        const activeBtn = document.querySelector('.filter-btn.active');
        if (activeBtn) {
            const label = (activeBtn.textContent || '').trim().toLowerCase();
            const map = {
                'tutti': 'all',
                'i miei task': 'mine',
                'alta priorità': 'high',
                'scadenza oggi': 'today',
                'completati': 'done'
            };
            this.state.currentFilter = map[label] || 'all';
        } else {
            this.state.currentFilter = 'all';
        }

        // Click on card opens edit modal (if permitted)
        document.addEventListener('click', (e) => {
            const card = e.target.closest?.('.task-card');
            if (!card) return;
            // ignore clicks on action buttons
            if (e.target.closest?.('.task-card-actions')) return;
            const taskId = parseInt(card.getAttribute('data-task-id') || '0', 10);
            const task = this.state.tasks.find(t => parseInt(t.id, 10) === taskId);
            if (!task) return;
            if (this.canEditTaskFull(task)) {
                this.openEditTaskModal(taskId);
                return;
            }
            if (this.isLimitedAssignee(task)) {
                this.openAssigneeUpdateModal(taskId);
                return;
            }
            this.showToast('Non autorizzato a modificare questo task', 'error');
        });

        // Close modals on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
                this.closeDeleteModal();
                this.closeAssigneeModal();
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
     * BUG-140 FIX: Use UNIFIED /api/tasks.php endpoint
     *
     * PROBLEM HISTORY:
     * - BUG-137/138/139: Various routing issues with router.php and direct file access
     * - curl worked (HEAD/GET) but browser POST returned 404
     * - Cloudflare/WAF may block POST to certain .php patterns
     * - CORS preflight may fail on direct file access
     *
     * SOLUTION:
     * - Single unified endpoint: /api/tasks.php
     * - Action passed via JSON body: { "action": "create", ... }
     * - Works identically on localhost:8888 and app.nexiosolution.it
     * - Bypasses all routing/CORS/WAF issues
     */
    async apiRequest(endpoint, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.config.csrfToken
            },
            credentials: 'same-origin'
        };

        // BUG-140: Merge options
        const mergedOptions = {
            ...defaultOptions,
            ...options,
            headers: {
                ...defaultOptions.headers,
                ...options.headers
            }
        };

        // BUG-140: UNIFIED ENDPOINT - single reliable URL
        const ts = Date.now();
        const unifiedUrl = `/CollaboraNexio/api/tasks.php?_ts=${ts}`;

        // BUG-140: Inject action into request body
        let requestBody = {};
        if (options.body) {
            try {
                requestBody = JSON.parse(options.body);
            } catch (e) {
                requestBody = {};
            }
        }
        requestBody.action = endpoint;

        // Update merged options with new body containing action
        mergedOptions.body = JSON.stringify(requestBody);

        // BUG-140: For GET requests (list, orphaned), use GET method with action in query
        if (options.method === 'GET' || (!options.method && (endpoint === 'list' || endpoint === 'orphaned'))) {
            const getUrl = `/CollaboraNexio/api/tasks.php?action=${endpoint}&_ts=${ts}`;
            delete mergedOptions.body;
            mergedOptions.method = 'GET';

            try {
                console.log(`[TaskManager] BUG-140: GET ${getUrl}`);
                const response = await fetch(getUrl, mergedOptions);

                if (!response.ok && response.status === 404) {
                    console.warn(`[TaskManager] 404 on unified endpoint, trying router fallback...`);
                    // Fallback to router.php
                    const routerUrl = `/CollaboraNexio/api/router.php?route=tasks/${endpoint}&_ts=${ts}`;
                    const fallbackResponse = await fetch(routerUrl, mergedOptions);
                    return await fallbackResponse.json();
                }

                return await response.json();
            } catch (error) {
                console.error(`[TaskManager] GET request failed:`, error);
                return { success: false, error: error.message };
            }
        }

        // BUG-140: For POST/PUT/DELETE, always use POST with action in body
        mergedOptions.method = 'POST';

        try {
            console.log(`[TaskManager] BUG-140: POST ${unifiedUrl} action=${endpoint}`);
            const response = await fetch(unifiedUrl, mergedOptions);

            if (!response.ok && response.status === 404) {
                console.warn(`[TaskManager] 404 on unified endpoint, trying router fallback...`);
                // Fallback to router.php
                const routerUrl = `/CollaboraNexio/api/router.php?route=tasks/${endpoint}&_ts=${ts}`;
                mergedOptions.body = options.body; // Use original body without action
                const fallbackResponse = await fetch(routerUrl, mergedOptions);
                return await fallbackResponse.json();
            }

            return await response.json();
        } catch (error) {
            console.error(`[TaskManager] POST request failed:`, error);
            return { success: false, error: error.message };
        }
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