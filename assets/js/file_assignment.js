/**
 * File Assignment System - Extension for EnhancedFileManager
 * Adds assignment capabilities to files and folders
 *
 * @requires EnhancedFileManager
 * @version 1.0.0
 */

class FileAssignmentManager {
    constructor(fileManager) {
        this.fileManager = fileManager;
        this.config = {
            assignApi: '/CollaboraNexio/api/files/assign.php',
            assignmentsApi: '/CollaboraNexio/api/files/assignments.php',
            checkAccessApi: '/CollaboraNexio/api/files/check-access.php',
            usersApi: '/CollaboraNexio/api/users/list.php'
        };

        this.state = {
            assignments: new Map(),
            users: [],
            userAccess: new Map(),
            currentFileId: null,
            currentFolderId: null
        };

        this.init();
    }

    /**
     * Get CSRF token from meta tag (BUG-043 pattern)
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Initialize the assignment system
     */
    async init() {
        console.log('[FileAssignment] Initializing assignment system...');

        // Load users for dropdown
        await this.loadUsers();

        // Create assignment modal
        this.createAssignmentModal();

        // Create assignments list modal
        this.createAssignmentsListModal();

        // Inject assignment UI into file manager
        this.injectAssignmentUI();

        // Check for existing assignments
        await this.loadAssignments();

        console.log('[FileAssignment] Assignment system initialized');
    }

    /**
     * Load tenant users for assignment dropdown
     */
    async loadUsers() {
        try {
            const response = await fetch(this.config.usersApi, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                // Extract users from wrapped response (BUG-040 pattern)
                this.state.users = data.data?.users || [];
                console.log('[FileAssignment] Loaded users:', this.state.users.length);
            }
        } catch (error) {
            console.error('[FileAssignment] Failed to load users:', error);
        }
    }

    /**
     * Load all assignments for current folder/file
     */
    async loadAssignments(fileId = null, folderId = null) {
        try {
            const params = new URLSearchParams();
            if (fileId) params.append('file_id', fileId);
            if (folderId) params.append('folder_id', folderId);

            const response = await fetch(`${this.config.assignmentsApi}?${params}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                const assignments = data.data?.assignments || [];

                // Store assignments by file/folder ID
                assignments.forEach(assignment => {
                    const key = assignment.file_id || `folder-${assignment.folder_id}`;
                    if (!this.state.assignments.has(key)) {
                        this.state.assignments.set(key, []);
                    }
                    this.state.assignments.get(key).push(assignment);
                });

                // Update UI indicators
                this.updateAssignmentIndicators();
            }
        } catch (error) {
            console.error('[FileAssignment] Failed to load assignments:', error);
        }
    }

    /**
     * Check if current user has access to file/folder
     */
    async checkAccess(fileId = null, folderId = null) {
        try {
            const params = new URLSearchParams();
            if (fileId) params.append('file_id', fileId);
            if (folderId) params.append('folder_id', folderId);

            const response = await fetch(`${this.config.checkAccessApi}?${params}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                const key = fileId || `folder-${folderId}`;
                this.state.userAccess.set(key, data.data?.access || false);
                return data.data?.access || false;
            }

            return false;
        } catch (error) {
            console.error('[FileAssignment] Failed to check access:', error);
            return false;
        }
    }

    /**
     * Create assignment modal
     */
    createAssignmentModal() {
        const modalHtml = `
            <div id="assignmentModal" class="modal" style="display: none;">
                <div class="modal-overlay"></div>
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Assegna File/Cartella</h3>
                        <button type="button" class="modal-close" onclick="fileAssignmentManager.closeAssignmentModal()">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <form id="assignmentForm">
                            <div class="form-group">
                                <label for="assignUser">Utente *</label>
                                <select id="assignUser" class="form-control" required>
                                    <option value="">Seleziona utente...</option>
                                </select>
                                <small class="form-text text-muted">Seleziona l'utente a cui assegnare l'accesso</small>
                            </div>

                            <div class="form-group">
                                <label for="assignReason">Motivo (opzionale)</label>
                                <textarea id="assignReason" class="form-control" rows="3" maxlength="500"
                                    placeholder="Inserisci il motivo dell'assegnazione..."></textarea>
                                <small class="form-text text-muted">
                                    <span id="reasonCharCount">0</span>/500 caratteri
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="assignExpiration">Data Scadenza (opzionale)</label>
                                <input type="datetime-local" id="assignExpiration" class="form-control">
                                <small class="form-text text-muted">Lascia vuoto per accesso permanente</small>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="fileAssignmentManager.closeAssignmentModal()">
                            Annulla
                        </button>
                        <button type="button" class="btn btn-primary" onclick="fileAssignmentManager.createAssignment()">
                            <span class="btn-icon">👤</span> Assegna
                        </button>
                    </div>
                </div>
            </div>
        `;

        // Add modal to body
        const modalContainer = document.createElement('div');
        modalContainer.innerHTML = modalHtml;
        document.body.appendChild(modalContainer.firstElementChild);

        // Setup character counter
        const reasonTextarea = document.getElementById('assignReason');
        const charCount = document.getElementById('reasonCharCount');

        reasonTextarea?.addEventListener('input', () => {
            charCount.textContent = reasonTextarea.value.length;
        });

        // Set min date for expiration (tomorrow)
        const expirationInput = document.getElementById('assignExpiration');
        if (expirationInput) {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setMinutes(0);
            expirationInput.min = tomorrow.toISOString().slice(0, 16);
        }
    }

    /**
     * Create assignments list modal
     */
    createAssignmentsListModal() {
        const modalHtml = `
            <div id="assignmentsListModal" class="modal" style="display: none;">
                <div class="modal-overlay"></div>
                <div class="modal-content modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">Gestione Assegnazioni</h3>
                        <button type="button" class="modal-close" onclick="fileAssignmentManager.closeAssignmentsListModal()">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="assignments-filter-bar">
                            <button class="btn btn-sm btn-outline-primary active" data-filter="all">
                                Tutte le assegnazioni
                            </button>
                            <button class="btn btn-sm btn-outline-primary" data-filter="mine">
                                Le mie assegnazioni
                            </button>
                        </div>

                        <div id="assignmentsTableContainer" class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Utente</th>
                                        <th>Assegnato da</th>
                                        <th>Data Assegnazione</th>
                                        <th>Scadenza</th>
                                        <th>Motivo</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody id="assignmentsTableBody">
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">
                                            Caricamento assegnazioni...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="fileAssignmentManager.closeAssignmentsListModal()">
                            Chiudi
                        </button>
                    </div>
                </div>
            </div>
        `;

        // Add modal to body
        const modalContainer = document.createElement('div');
        modalContainer.innerHTML = modalHtml;
        document.body.appendChild(modalContainer.firstElementChild);

        // Setup filter buttons
        const filterButtons = document.querySelectorAll('.assignments-filter-bar button');
        filterButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                filterButtons.forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                this.filterAssignments(e.target.dataset.filter);
            });
        });
    }

    /**
     * Show assignment modal for file/folder
     */
    showAssignmentModal(fileId = null, folderId = null, fileName = '') {
        this.state.currentFileId = fileId;
        this.state.currentFolderId = folderId;

        // Update modal title
        const modalTitle = document.querySelector('#assignmentModal .modal-title');
        if (modalTitle) {
            const type = fileId ? 'File' : 'Cartella';
            modalTitle.textContent = `Assegna ${type}: ${fileName}`;
        }

        // Populate users dropdown
        const userSelect = document.getElementById('assignToUser');
        if (userSelect && this.state.users.length > 0) {
            userSelect.innerHTML = '<option value="">-- Seleziona utente --</option>';

            // Get current user ID to exclude from list
            const currentUserId = document.getElementById('currentUserId')?.value;

            this.state.users.forEach(user => {
                // Skip current user
                if (user.id == currentUserId) return;

                const option = document.createElement('option');
                option.value = user.id;
                option.textContent = `${user.name} (${user.email}) - ${user.role}`;
                userSelect.appendChild(option);
            });
        }

        // Reset form
        document.getElementById('assignmentForm')?.reset();
        document.getElementById('reasonCharCount').textContent = '0';

        // Show modal
        document.getElementById('assignmentModal').style.display = 'block';
    }

    /**
     * Close assignment modal
     */
    closeAssignmentModal() {
        document.getElementById('assignmentModal').style.display = 'none';
    }

    /**
     * Create new assignment
     */
    async createAssignment() {
        const userId = document.getElementById('assignUser')?.value;
        const reason = document.getElementById('assignReason')?.value;
        const expiration = document.getElementById('assignExpiration')?.value;

        if (!userId) {
            this.showToast('Seleziona un utente', 'error');
            return;
        }

        try {
            const body = {
                action: 'create',
                user_id: parseInt(userId),
                reason: reason || null,
                expires_at: expiration || null
            };

            if (this.state.currentFileId) {
                body.file_id = this.state.currentFileId;
            } else if (this.state.currentFolderId) {
                body.folder_id = this.state.currentFolderId;
            }

            const response = await fetch(this.config.assignApi, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin',
                body: JSON.stringify(body)
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Assegnazione creata con successo', 'success');
                this.closeAssignmentModal();

                // Reload assignments
                await this.loadAssignments(this.state.currentFileId, this.state.currentFolderId);

                // Update file manager UI
                this.updateAssignmentIndicators();
            } else {
                throw new Error(data.error || 'Errore durante l\'assegnazione');
            }
        } catch (error) {
            console.error('[FileAssignment] Failed to create assignment:', error);
            this.showToast(error.message || 'Errore durante l\'assegnazione', 'error');
        }
    }

    /**
     * Show assignments list modal
     */
    async showAssignmentsListModal(fileId = null, folderId = null, fileName = '') {
        // Update modal title
        const modalTitle = document.querySelector('#assignmentsListModal .modal-title');
        if (modalTitle) {
            const type = fileId ? 'File' : 'Cartella';
            modalTitle.textContent = `Assegnazioni - ${type}: ${fileName}`;
        }

        // Load assignments for this file/folder
        const params = new URLSearchParams();
        if (fileId) params.append('file_id', fileId);
        if (folderId) params.append('folder_id', folderId);

        try {
            const response = await fetch(`${this.config.assignmentsApi}?${params}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                const assignments = data.data?.assignments || [];
                this.renderAssignmentsTable(assignments);
            }
        } catch (error) {
            console.error('[FileAssignment] Failed to load assignments:', error);
            this.showToast('Errore caricamento assegnazioni', 'error');
        }

        // Show modal
        document.getElementById('assignmentsListModal').style.display = 'block';
    }

    /**
     * Close assignments list modal
     */
    closeAssignmentsListModal() {
        document.getElementById('assignmentsListModal').style.display = 'none';
    }

    /**
     * Render assignments table
     */
    renderAssignmentsTable(assignments) {
        const tbody = document.getElementById('assignmentsTableBody');

        if (!tbody) return;

        if (assignments.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted">
                        Nessuna assegnazione trovata
                    </td>
                </tr>
            `;
            return;
        }

        // Get current user for permission check
        const currentUserId = document.getElementById('currentUserId')?.value;
        const currentUserRole = document.getElementById('userRole')?.value;
        const canRevoke = ['manager', 'admin', 'super_admin'].includes(currentUserRole);

        tbody.innerHTML = assignments.map(assignment => {
            const expirationDate = assignment.expires_at ?
                new Date(assignment.expires_at).toLocaleDateString('it-IT') :
                'Permanente';

            const isExpired = assignment.expires_at && new Date(assignment.expires_at) < new Date();
            const expirationClass = isExpired ? 'text-danger' : '';

            const canRevokeThis = canRevoke || assignment.created_by == currentUserId;

            return `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <span class="badge badge-user mr-2">👤</span>
                            <div>
                                <div>${assignment.assigned_user_name}</div>
                                <small class="text-muted">${assignment.assigned_user_email}</small>
                            </div>
                        </div>
                    </td>
                    <td>${assignment.created_by_name}</td>
                    <td>${new Date(assignment.created_at).toLocaleDateString('it-IT')}</td>
                    <td class="${expirationClass}">
                        ${expirationDate}
                        ${isExpired ? '<span class="badge badge-danger ml-1">Scaduto</span>' : ''}
                    </td>
                    <td>
                        ${assignment.reason ?
                            `<span class="text-truncate" title="${assignment.reason}">${assignment.reason}</span>` :
                            '<span class="text-muted">-</span>'}
                    </td>
                    <td>
                        ${canRevokeThis ? `
                            <button class="btn btn-sm btn-danger"
                                onclick="fileAssignmentManager.revokeAssignment(${assignment.id})">
                                Revoca
                            </button>
                        ` : '<span class="text-muted">-</span>'}
                    </td>
                </tr>
            `;
        }).join('');
    }

    /**
     * Revoke assignment
     */
    async revokeAssignment(assignmentId) {
        if (!confirm('Sei sicuro di voler revocare questa assegnazione?')) {
            return;
        }

        try {
            const response = await fetch(this.config.assignApi, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'revoke',
                    assignment_id: assignmentId
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Assegnazione revocata con successo', 'success');

                // Refresh assignments list
                this.showAssignmentsListModal(this.state.currentFileId, this.state.currentFolderId);

                // Reload assignments
                await this.loadAssignments();

                // Update UI indicators
                this.updateAssignmentIndicators();
            } else {
                throw new Error(data.error || 'Errore durante la revoca');
            }
        } catch (error) {
            console.error('[FileAssignment] Failed to revoke assignment:', error);
            this.showToast(error.message || 'Errore durante la revoca', 'error');
        }
    }

    /**
     * Filter assignments in list modal
     */
    filterAssignments(filter) {
        // TODO: Implement filtering logic based on current user
        console.log('[FileAssignment] Filtering assignments:', filter);
    }

    /**
     * Update assignment indicators on files/folders
     */
    updateAssignmentIndicators() {
        // Find all file and folder items
        const fileItems = document.querySelectorAll('.file-item');
        const folderItems = document.querySelectorAll('.folder-item');

        // Update file indicators
        fileItems.forEach(item => {
            const fileId = item.dataset.fileId;
            if (fileId && this.state.assignments.has(fileId)) {
                this.addAssignmentIndicator(item, 'file');
            }
        });

        // Update folder indicators
        folderItems.forEach(item => {
            const folderId = item.dataset.folderId;
            const key = `folder-${folderId}`;
            if (folderId && this.state.assignments.has(key)) {
                this.addAssignmentIndicator(item, 'folder');
            }
        });
    }

    /**
     * Add assignment indicator to file/folder element
     */
    addAssignmentIndicator(element, type) {
        // Check if indicator already exists
        if (element.querySelector('.assignment-indicator')) {
            return;
        }

        // Create indicator
        const indicator = document.createElement('span');
        indicator.className = 'assignment-indicator';
        indicator.innerHTML = '🔒 Assegnato';
        indicator.title = 'Questo elemento è assegnato a utenti specifici';
        indicator.style.cssText = `
            display: inline-block;
            padding: 2px 6px;
            background: #f59e0b;
            color: white;
            font-size: 10px;
            border-radius: 3px;
            margin-left: 8px;
            font-weight: 600;
            vertical-align: middle;
        `;

        // Find appropriate place to insert indicator
        const nameElement = element.querySelector('.file-name, .folder-name');
        if (nameElement) {
            nameElement.appendChild(indicator);
        }
    }

    /**
     * Inject assignment UI into file manager context menu
     */
    injectAssignmentUI() {
        // Wait for file manager to be ready
        if (!window.fileManager) {
            console.warn('[FileAssignment] File manager not ready, retrying...');
            setTimeout(() => this.injectAssignmentUI(), 100);
            return;
        }

        // Get user role for permission check
        const userRole = document.getElementById('userRole')?.value || 'user';
        const canAssign = ['manager', 'admin', 'super_admin'].includes(userRole);

        if (!canAssign) {
            console.log('[FileAssignment] User does not have permission to assign files');
            return;
        }

        // Hook into file manager's context menu
        const originalShowContextMenu = window.fileManager.showContextMenu;

        window.fileManager.showContextMenu = function(e, item) {
            // Call original method
            originalShowContextMenu?.call(this, e, item);

            // Add assignment options to context menu
            setTimeout(() => {
                const contextMenu = document.querySelector('.context-menu');
                if (contextMenu) {
                    // Check if assignment items already exist to prevent duplication (BUG-057 fix)
                    const existingAssignItem = Array.from(contextMenu.children).find(
                        el => el.textContent && el.textContent.includes('Assegna') && !el.textContent.includes('Visualizza')
                    );

                    if (existingAssignItem) {
                        console.log('[FileAssignment] Assignment menu items already present, skipping injection');
                        return;
                    }

                    // Add separator
                    const separator = document.createElement('div');
                    separator.className = 'context-menu-separator';
                    contextMenu.appendChild(separator);

                    // Add assign option
                    const assignOption = document.createElement('div');
                    assignOption.className = 'context-menu-item';
                    assignOption.innerHTML = '<span class="icon">👤</span> Assegna';
                    assignOption.onclick = () => {
                        const fileId = item.dataset.fileId;
                        const folderId = item.dataset.folderId;
                        const fileName = item.querySelector('.file-name, .folder-name')?.textContent || '';
                        fileAssignmentManager.showAssignmentModal(fileId, folderId, fileName);
                        this.hideContextMenu();
                    };
                    contextMenu.appendChild(assignOption);

                    // Add view assignments option
                    const viewOption = document.createElement('div');
                    viewOption.className = 'context-menu-item';
                    viewOption.innerHTML = '<span class="icon">📋</span> Visualizza Assegnazioni';
                    viewOption.onclick = () => {
                        const fileId = item.dataset.fileId;
                        const folderId = item.dataset.folderId;
                        const fileName = item.querySelector('.file-name, .folder-name')?.textContent || '';
                        fileAssignmentManager.showAssignmentsListModal(fileId, folderId, fileName);
                        this.hideContextMenu();
                    };
                    contextMenu.appendChild(viewOption);
                }
            }, 10);
        };

        console.log('[FileAssignment] UI injection complete');
    }

    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background: ${type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : '#3b82f6'};
            color: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 10000;
            animation: slideIn 0.3s ease;
        `;

        document.body.appendChild(toast);

        // Remove after 3 seconds
        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}

// Initialize when document is ready
document.addEventListener('DOMContentLoaded', () => {
    // Wait for file manager to be initialized
    const checkFileManager = setInterval(() => {
        if (window.fileManager) {
            clearInterval(checkFileManager);
            window.fileAssignmentManager = new FileAssignmentManager(window.fileManager);
        }
    }, 100);
});

// Add animations
const style = document.createElement('style');
style.textContent = `
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

    .assignment-indicator:hover {
        transform: scale(1.05);
        transition: transform 0.2s ease;
    }

    .context-menu-separator {
        height: 1px;
        background: rgba(0, 0, 0, 0.1);
        margin: 4px 0;
    }

    .assignments-filter-bar {
        margin-bottom: 16px;
        display: flex;
        gap: 8px;
    }

    .badge-user {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #f3f4f6;
        font-size: 16px;
    }
`;
document.head.appendChild(style);