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
            usersApi: '/CollaboraNexio/api/users/list.php',
            tenantRolesApi: '/CollaboraNexio/api/tenant-roles/list.php'
        };

        this.state = {
            assignments: new Map(),
            users: [],
            tenantRoles: [],
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

        // Create assignment modal (only if page doesn't already provide one)
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
     * Load tenant business roles (tenant_roles) for group assignment
     */
    async loadTenantRoles(tenantId = null) {
        try {
            const getSelectedTenantIdFromCompanyFilter = () => {
                const form = document.getElementById('companyFilterForm');
                if (!form) return '';
                const checked = Array.from(form.querySelectorAll('input[name="company_filter[]"]:checked'))
                    .map(el => String(el.value || '').trim())
                    .filter(Boolean);

                // If "all" is checked (or nothing selected), we don't have a single tenant context.
                const tenantIds = checked.filter(v => v !== 'all');
                if (tenantIds.length !== 1) return '';
                return tenantIds[0];
            };

            // Resolve tenant id:
            // 1) explicit param
            // 2) Company filter (checkbox-driven; must be exactly 1 tenant selected)
            // 3) hidden currentTenantId (manager/user)
            const companyTenantId = getSelectedTenantIdFromCompanyFilter();

            const userRole = (window.userRole || document.getElementById('userRole')?.value || '').toString();
            const isPrivilegedMultiTenantUser = (userRole === 'super_admin' || userRole === 'admin');

            const resolvedTenantId = tenantId
                || companyTenantId
                // IMPORTANT: for super_admin/admin we require an explicit company filter selection,
                // otherwise we might use an unrelated fallback tenant_id (and show empty roles).
                || (isPrivilegedMultiTenantUser ? '' : (document.getElementById('currentTenantId')?.value || ''))
                || document.getElementById('currentTenant')?.value
                || '';

            if (!resolvedTenantId) {
                this.state.tenantRoles = [];
                return [];
            }

            const response = await fetch(`${this.config.tenantRolesApi}?tenant_id=${encodeURIComponent(resolvedTenantId)}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            const data = await response.json();
            if (data.success) {
                this.state.tenantRoles = data.data?.roles || [];
                return this.state.tenantRoles;
            }

            this.state.tenantRoles = [];
            return [];
        } catch (error) {
            console.warn('[FileAssignment] Failed to load tenant roles:', error);
            this.state.tenantRoles = [];
            return [];
        }
    }

    /**
     * Load all assignments for current folder/file
     */
    async loadAssignments(fileId = null, folderId = null) {
        try {
            const params = new URLSearchParams();
            // Folders are stored in `files` too; assignments API filters by file_id only.
            if (fileId) {
                params.append('file_id', fileId);
            } else if (folderId) {
                params.append('file_id', folderId);
            }

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
                    // Folders are stored in files table; assignment key is always file_id
                    const key = assignment.file_id;
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
     * Get a human-readable assignment summary for a file/folder (entityId).
     * NOTE: for regular users, the assignments API returns only their own rows, so this may be empty even if assigned to others.
     */
    async getActiveAssignmentSummary(fileId = null, folderId = null) {
        try {
            const entityId = fileId || folderId;
            if (!entityId) {
                return { hasAssignment: false, text: 'Non assegnato', assignment: null };
            }

            const params = new URLSearchParams();
            params.append('file_id', String(entityId)); // folders are stored in files table too
            params.append('per_page', '10');

            const response = await fetch(`${this.config.assignmentsApi}?${params}`, {
                method: 'GET',
                headers: { 'X-CSRF-Token': this.getCsrfToken() },
                credentials: 'same-origin'
            });

            const data = await response.json();
            if (!data || !data.success) {
                return { hasAssignment: false, text: 'Non assegnato', assignment: null };
            }

            const assignments = data.data?.assignments || [];
            if (!Array.isArray(assignments) || assignments.length === 0) {
                return { hasAssignment: false, text: 'Non assegnato', assignment: null };
            }

            // Prefer first row (API is sorted by expiring_soon/is_expired/created_at)
            const a = assignments[0];
            const type = a.assigned_to_type || (a.assigned_to && a.assigned_to.tenant_role_id ? 'tenant_role' : 'user');

            let label = '';
            if (type === 'tenant_role') {
                const roleName = a.assigned_to?.name || a.assigned_to_role_name || a.assigned_user_name || 'Ruolo Aziendale';
                label = `Ruolo: ${roleName}`;
            } else {
                const userName = a.assigned_to?.name || a.assigned_to_name || a.assigned_user_name || 'Utente';
                label = `Utente: ${userName}`;
            }

            const suffix = assignments.length > 1 ? ` (+${assignments.length - 1})` : '';
            return { hasAssignment: true, text: `${label}${suffix}`, assignment: a };
        } catch (error) {
            console.warn('[FileAssignment] Failed to get assignment summary:', error);
            return { hasAssignment: false, text: 'Non assegnato', assignment: null };
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
        // files.php already provides an assignment modal. If present, reuse it.
        if (document.getElementById('assignmentModal') && document.getElementById('assignToUser')) {
            return;
        }

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
                                <label for="assignmentTargetType">Tipo Assegnazione *</label>
                                <select id="assignmentTargetType" class="form-control">
                                    <option value="user" selected>Utente</option>
                                    <option value="tenant_role">Ruolo Aziendale (gruppo)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="assignUser">Utente *</label>
                                <select id="assignUser" class="form-control" required>
                                    <option value="">Seleziona utente...</option>
                                </select>
                                <small class="form-text text-muted">Seleziona l'utente a cui assegnare l'accesso</small>
                            </div>
                            <div class="form-group" id="assignToTenantRoleGroup" style="display:none;">
                                <label for="assignToTenantRole">Ruolo Aziendale *</label>
                                <select id="assignToTenantRole" class="form-control">
                                    <option value="">-- Seleziona ruolo aziendale --</option>
                                </select>
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

        // Populate dropdowns
        const userSelect = document.getElementById('assignToUser') || document.getElementById('assignUser');
        const targetTypeSelect = document.getElementById('assignmentTargetType');
        const roleGroup = document.getElementById('assignToTenantRoleGroup');
        const roleSelect = document.getElementById('assignToTenantRole');
        if (userSelect && this.state.users.length > 0) {
            // Keep consistent placeholder between the two modal versions
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

        // Populate tenant roles dropdown (if present)
        if (roleSelect) {
            // Best-effort: load roles lazily when opening modal (so tenant filter is already set)
            this.loadTenantRoles().then((roles) => {
                roleSelect.innerHTML = '<option value="">-- Seleziona ruolo aziendale --</option>';
                (roles || []).forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.id;
                    opt.textContent = `${r.name}${r.code ? ` (${r.code})` : ''}`;
                    if (r.color) {
                        opt.style.backgroundColor = `${r.color}20`;
                    }
                    roleSelect.appendChild(opt);
                });

                // If no roles loaded, give a more explicit UX
                if (!roles || roles.length === 0) {
                    const userRole = (window.userRole || document.getElementById('userRole')?.value || '').toString();
                    const isPrivilegedMultiTenantUser = (userRole === 'super_admin' || userRole === 'admin');
                    const hasTenantContext = !isPrivilegedMultiTenantUser && !!document.getElementById('currentTenantId')?.value;
                    roleSelect.innerHTML = hasTenantContext
                        ? '<option value="">(Nessun ruolo aziendale disponibile)</option>'
                        : '<option value="">(Seleziona prima un’azienda dal filtro in alto)</option>';
                }
            });
        }

        // Toggle UI between user vs tenant_role targets
        const applyTargetType = () => {
            const t = targetTypeSelect?.value || 'user';
            const userGroup = userSelect ? userSelect.closest('.form-group') : null;
            if (t === 'tenant_role') {
                if (userGroup) userGroup.style.display = 'none';
                if (roleGroup) roleGroup.style.display = 'block';
            } else {
                if (userGroup) userGroup.style.display = 'block';
                if (roleGroup) roleGroup.style.display = 'none';
            }
        };
        if (targetTypeSelect) {
            targetTypeSelect.onchange = applyTargetType;
            applyTargetType();
        }

        // Reset form
        document.getElementById('assignmentForm')?.reset();
        const legacyCharCount = document.getElementById('reasonCharCount');
        if (legacyCharCount) legacyCharCount.textContent = '0';
        // files.php modal fields
        const reasonEl = document.getElementById('assignmentReason');
        if (reasonEl) reasonEl.value = '';
        const expEl = document.getElementById('assignmentExpires');
        if (expEl) expEl.value = '';

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
        // Support both modal variants:
        // - files.php built-in modal: assignToUser / assignmentReason / assignmentExpires
        // - legacy injected modal: assignUser / assignReason / assignExpiration
        const targetType = document.getElementById('assignmentTargetType')?.value || 'user';
        const userId = (document.getElementById('assignToUser') || document.getElementById('assignUser'))?.value;
        const tenantRoleId = document.getElementById('assignToTenantRole')?.value;
        const reason = (document.getElementById('assignmentReason') || document.getElementById('assignReason'))?.value;
        const expiration = (document.getElementById('assignmentExpires') || document.getElementById('assignExpiration'))?.value;

        if (targetType === 'tenant_role') {
            if (!tenantRoleId) {
                this.showToast('Seleziona un ruolo aziendale', 'error');
                return;
            }
        } else {
            if (!userId) {
                this.showToast('Seleziona un utente', 'error');
                return;
            }
        }

        try {
            const body = {
                assignment_reason: reason || null,
                expires_at: expiration || null
            };

            // In this codebase folders are files with is_folder=1, so always use file_id
            const entityId = this.state.currentFileId || this.state.currentFolderId;
            body.file_id = entityId ? parseInt(entityId, 10) : null;

            if (targetType === 'tenant_role') {
                body.assigned_to_tenant_role_id = parseInt(tenantRoleId, 10);
            } else {
                body.assigned_to_user_id = parseInt(userId, 10);
            }

            const doRequest = async (payload) => {
                const res = await fetch(this.config.assignApi, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.getCsrfToken()
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                });
                const json = await res.json();
                return { res, json };
            };

            // First attempt
            let didForceReassign = false;
            let { res: response, json: data } = await doRequest(body);

            // Duplicate assignment => offer reassignment
            if (response.status === 409 && data && data.success === false && data.data && data.data.can_reassign && !body.force_reassign) {
                const existingId = data.data.existing_assignment?.id || null;
                const existingLabel = data.data.existing_assignment?.target_label || 'destinatario';
                const isOk = confirm(
                    `Questo elemento è già assegnato a ${existingLabel}${existingId ? ` (ID assegnazione: ${existingId})` : ''}.\n\n` +
                    `Vuoi riassegnarlo?\n` +
                    `Attenzione: la riassegnazione rimuove i privilegi di apertura a tutti gli altri utenti/ruoli aziendali.`
                );

                if (!isOk) {
                    this.showToast('Assegnazione già presente. Nessuna modifica effettuata.', 'info');
                    return;
                }

                // Retry with force_reassign
                const forcedBody = { ...body, force_reassign: true };
                didForceReassign = true;
                ({ res: response, json: data } = await doRequest(forcedBody));
            }

            if (data && data.success) {
                const msg = didForceReassign ? 'Riassegnazione completata con successo' : 'Assegnazione creata con successo';
                this.showToast(msg, 'success');
                this.closeAssignmentModal();

                // Reload assignments
                await this.loadAssignments(this.state.currentFileId, this.state.currentFolderId);

                // Update file manager UI
                this.updateAssignmentIndicators();
            } else {
                throw new Error(data?.error || 'Errore durante l\'assegnazione');
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
        if (fileId) {
            params.append('file_id', fileId);
        } else if (folderId) {
            params.append('file_id', folderId);
        }

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

            const badge = assignment.assigned_target_badge || '👤';
            const badgeColor = assignment.assigned_target_color || '#6b7280';
            return `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <span class="badge badge-user mr-2" style="background:${badgeColor}20;border:1px solid ${badgeColor}55;">
                                ${badge}
                            </span>
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

        // BUG-065 FIX: Correct parameter signature (x, y, fileElement) not (e, item)
        window.fileManager.showContextMenu = function(x, y, fileElement) {
            // Call original method with correct parameters
            originalShowContextMenu?.call(this, x, y, fileElement);

            // Add assignment options to context menu
            setTimeout(() => {
                const contextMenu = document.querySelector('.context-menu');
                if (!contextMenu || !fileElement) return; // Guard check

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
                    const fileId = fileElement.dataset.fileId || fileElement.dataset.id;
                    const folderId = fileElement.dataset.folderId;
                    const fileName = fileElement.querySelector('.file-name, .folder-name')?.textContent || '';
                    window.fileAssignmentManager?.showAssignmentModal(fileId, folderId, fileName);
                    window.fileManager?.hideContextMenu();
                };
                contextMenu.appendChild(assignOption);

                // Add view assignments option
                const viewOption = document.createElement('div');
                viewOption.className = 'context-menu-item';
                viewOption.innerHTML = '<span class="icon">📋</span> Visualizza Assegnazioni';
                viewOption.onclick = () => {
                    window.fileAssignmentManager?.showAssignmentsModal();
                    window.fileManager?.hideContextMenu();
                };
                contextMenu.appendChild(viewOption);
            }, 50);
        };

        console.log('[FileAssignment] UI injection complete');
    }

    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
        const allowedTypes = ['success', 'error', 'info', 'warning'];
        const toastType = allowedTypes.includes(type) ? type : 'info';
        const colors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6'
        };

        const toast = document.createElement('div');
        toast.className = `workflow-toast workflow-toast-${toastType}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            background: ${colors[toastType]};
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