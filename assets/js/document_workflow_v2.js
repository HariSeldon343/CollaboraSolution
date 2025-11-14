/**
 * Document Workflow Management System
 * Handles document approval workflow with multi-stage validation
 *
 * @version 1.0.0
 */

class DocumentWorkflowManager {
    constructor() {
        this.config = {
            workflowApi: '/CollaboraNexio/api/documents/workflow/',
            rolesApi: '/CollaboraNexio/api/workflow/roles/',
            documentsApi: '/CollaboraNexio/api/files/list.php'
        };

        this.state = {
            workflows: new Map(),
            workflowHistory: new Map(),
            validators: [],
            approvers: [],
            currentFileId: null,
            currentWorkflow: null,
            dashboardStats: {
                pendingValidation: 0,
                pendingApproval: 0,
                myDocuments: 0
            }
        };

        // Workflow states with Italian labels and colors
        this.workflowStates = {
            bozza: { label: 'Bozza', color: '#3b82f6', icon: '📝' },
            in_validazione: { label: 'In Validazione', color: '#eab308', icon: '🔍' },
            validato: { label: 'Validato', color: '#22c55e', icon: '✓' },
            in_approvazione: { label: 'In Approvazione', color: '#f97316', icon: '⏳' },
            approvato: { label: 'Approvato', color: '#10b981', icon: '✅' },
            rifiutato: { label: 'Rifiutato', color: '#ef4444', icon: '❌' }
        };

        this.init();
    }

    /**
     * Get CSRF token (BUG-043 pattern)
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Initialize workflow manager
     */
    async init() {
        console.log('[WorkflowManager] Initializing...');

        // Create modals
        this.createWorkflowModals();

        // Load validators and approvers
        await this.loadWorkflowRoles();

        // Note: Workflow statuses loaded lazily per file via getWorkflowStatus()
        // Batch loading not supported by backend API (requires file_id parameter)

        // Load dashboard stats
        await this.loadDashboardStats();

        // Inject workflow UI into file manager
        this.injectWorkflowUI();

        // Setup auto-refresh
        this.setupAutoRefresh();

        console.log('[WorkflowManager] Initialized successfully');
    }

    /**
     * Load workflow roles (validators and approvers)
     */
    async loadWorkflowRoles() {
        try {
            const response = await fetch(`${this.config.rolesApi}list.php`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                const roles = data.data?.roles || [];
                this.state.validators = roles.filter(r => r.role === 'validator');
                this.state.approvers = roles.filter(r => r.role === 'approver');

                console.log('[WorkflowManager] Loaded roles:', {
                    validators: this.state.validators.length,
                    approvers: this.state.approvers.length
                });
            }
        } catch (error) {
            console.error('[WorkflowManager] Failed to load roles:', error);
        }
    }

    /**
     * Load workflow statuses for all documents
     */
    async loadWorkflowStatuses() {
        try {
            const response = await fetch(`${this.config.workflowApi}status.php?all=true`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                const workflows = data.data?.workflows || [];
                workflows.forEach(workflow => {
                    this.state.workflows.set(workflow.file_id, workflow);
                });

                // Update UI with workflow badges
                this.updateWorkflowBadges();
            }
        } catch (error) {
            console.error('[WorkflowManager] Failed to load statuses:', error);
        }
    }

    /**
     * Get workflow status for a single file
     * @param {number} fileId - File ID
     * @returns {Promise<object|null>} Workflow status or null
     */
    async getWorkflowStatus(fileId) {
        try {
            const response = await fetch(`${this.config.workflowApi}status.php?file_id=${fileId}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            // Handle 404: file not found (normal case - file has no workflow or doesn't exist)
            if (response.status === 404) {
                console.debug(`[WorkflowManager] File ${fileId} not found or not accessible`);
                return null;
            }

            const data = await response.json();

            if (data.success) {
                const workflow = data.data?.workflow || null;

                // Cache the workflow status
                if (workflow) {
                    this.state.workflows.set(fileId, workflow);
                }

                return workflow;
            }

            return null;
        } catch (error) {
            console.error(`[WorkflowManager] Failed to get status for file ${fileId}:`, error);
            return null;
        }
    }

    /**
     * Load dashboard statistics
     */
    async loadDashboardStats() {
        try {
            const response = await fetch(`${this.config.workflowApi}dashboard.php`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                this.state.dashboardStats = data.data?.stats || {
                    pendingValidation: 0,
                    pendingApproval: 0,
                    myDocuments: 0
                };

                // Update dashboard widget if present
                this.updateDashboardWidget();
            }
        } catch (error) {
            console.error('[WorkflowManager] Failed to load stats:', error);
        }
    }

    /**
     * Create all workflow modals
     */
    createWorkflowModals() {
        // Create action modals
        this.createActionModal();

        // Create history modal
        this.createHistoryModal();

        // Create role configuration modal
        this.createRoleConfigModal();
    }

    /**
     * Create workflow action modal (validate/reject/approve/recall)
     */
    createActionModal() {
        const modalHtml = `
            <div id="workflowActionModal" class="modal" style="display: none;">
                <div class="modal-overlay" style="position: absolute !important; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); backdrop-filter: none !important; z-index: 1 !important; pointer-events: none !important; display: block !important;"></div>
                <div class="modal-content" style="position: relative; z-index: 2 !important; pointer-events: auto !important; filter: none !important;">
                    <div class="modal-header">
                        <h3 class="modal-title" id="workflowActionTitle">Azione Workflow</h3>
                        <button type="button" class="modal-close" onclick="workflowManager.closeActionModal()">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <form id="workflowActionForm">
                            <div id="actionDescription" class="alert alert-info mb-3"></div>

                            <div class="form-group">
                                <label for="workflowComment">Commento <span id="commentRequired" style="color: red;">*</span></label>
                                <textarea id="workflowComment" class="form-control" rows="4" maxlength="1000"
                                    placeholder="Inserisci il tuo commento..."></textarea>
                                <small class="form-text text-muted">
                                    <span id="commentCharCount">0</span>/1000 caratteri
                                    <span id="commentMinimum" style="color: red; display: none;">
                                        (minimo 20 caratteri richiesti)
                                    </span>
                                </small>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="workflowManager.closeActionModal()">
                            Annulla
                        </button>
                        <button type="button" class="btn" id="workflowActionButton" onclick="workflowManager.executeAction()">
                            Conferma
                        </button>
                    </div>
                </div>
            </div>
        `;

        const modalContainer = document.createElement('div');
        modalContainer.innerHTML = modalHtml;
        document.body.appendChild(modalContainer.firstElementChild);

        // Setup character counter
        const commentTextarea = document.getElementById('workflowComment');
        const charCount = document.getElementById('commentCharCount');

        commentTextarea?.addEventListener('input', () => {
            charCount.textContent = commentTextarea.value.length;

            // Check minimum for rejection
            if (this.currentAction === 'reject' && commentTextarea.value.length < 20) {
                document.getElementById('commentMinimum').style.display = 'inline';
            } else {
                document.getElementById('commentMinimum').style.display = 'none';
            }
        });
    }

    /**
     * Create workflow history modal
     */
    createHistoryModal() {
        const modalHtml = `
            <div id="workflowHistoryModal" class="modal" style="display: none;">
                <div class="modal-overlay" style="position: absolute !important; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); backdrop-filter: none !important; z-index: 1 !important; pointer-events: none !important; display: block !important;"></div>
                <div class="modal-content modal-lg" style="position: relative; z-index: 2 !important; pointer-events: auto !important; filter: none !important;">
                    <div class="modal-header">
                        <h3 class="modal-title">Storico Workflow</h3>
                        <button type="button" class="modal-close" onclick="workflowManager.closeHistoryModal()">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div id="workflowTimeline" class="workflow-timeline">
                            <!-- Timeline will be rendered here -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="workflowManager.closeHistoryModal()">
                            Chiudi
                        </button>
                    </div>
                </div>
            </div>
        `;

        const modalContainer = document.createElement('div');
        modalContainer.innerHTML = modalHtml;
        document.body.appendChild(modalContainer.firstElementChild);
    }

    /**
     * Create role configuration modal (only if not exists in HTML)
     */
    createRoleConfigModal() {
        // Check if modal already exists in HTML (BUG-058 fix)
        if (document.getElementById('workflowRoleConfigModal')) {
            console.log('[WorkflowManager] Role config modal already exists in HTML, skipping creation');
            return;
        }

        const modalHtml = `
            <div id="workflowRoleConfigModal" class="modal" style="display: none;">
                <div class="modal-overlay"></div>
                <div class="modal-content modal-lg">
                    <div class="modal-header">
                        <h3 class="modal-title">Configurazione Ruoli Workflow</h3>
                        <button type="button" class="modal-close" onclick="workflowManager.closeRoleConfigModal()">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h4>Validatori</h4>
                                <div class="form-group">
                                    <label>Seleziona utenti che possono validare documenti:</label>
                                    <select id="validatorUsers" class="form-control" multiple size="8">
                                        <!-- Users will be loaded here -->
                                    </select>
                                    <small class="form-text text-muted">
                                        Tieni premuto Ctrl per selezione multipla
                                    </small>
                                </div>
                                <button class="btn btn-primary btn-sm" onclick="workflowManager.saveValidators()">
                                    Salva Validatori
                                </button>
                            </div>

                            <div class="col-md-6">
                                <h4>Approvatori</h4>
                                <div class="form-group">
                                    <label>Seleziona utenti che possono approvare documenti:</label>
                                    <select id="approverUsers" class="form-control" multiple size="8">
                                        <!-- Users will be loaded here -->
                                    </select>
                                    <small class="form-text text-muted">
                                        Tieni premuto Ctrl per selezione multipla
                                    </small>
                                </div>
                                <button class="btn btn-primary btn-sm" onclick="workflowManager.saveApprovers()">
                                    Salva Approvatori
                                </button>
                            </div>
                        </div>

                        <hr>

                        <h4>Ruoli Attuali</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Validatori:</h5>
                                <ul id="currentValidators" class="list-group">
                                    <!-- Current validators will be listed here -->
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h5>Approvatori:</h5>
                                <ul id="currentApprovers" class="list-group">
                                    <!-- Current approvers will be listed here -->
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="workflowManager.closeRoleConfigModal()">
                            Chiudi
                        </button>
                    </div>
                </div>
            </div>
        `;

        const modalContainer = document.createElement('div');
        modalContainer.innerHTML = modalHtml;
        document.body.appendChild(modalContainer.firstElementChild);
    }

    /**
     * Show workflow action modal
     * BUG-085 FIX: Close status modal FIRST to prevent overlay stacking blur
     * Previous behavior: Action modal opened while status modal still visible → overlays stacked → blur applied to everything
     * New behavior: Status modal closes cleanly BEFORE action modal opens → single overlay → no blur issues
     */
    showActionModal(action, fileId, fileName) {
        // BUG-085 FIX: Close status modal FIRST to prevent overlay stacking
        // This ensures clean transition and prevents blur from stacking overlays
        this.closeStatusModal();

        // Small delay ensures status modal closes completely before action modal opens
        // This prevents race conditions with modal overlay z-index and blur effects
        setTimeout(() => {
            this.currentAction = action;
            this.state.currentFileId = fileId;

            const modal = document.getElementById('workflowActionModal');
            const title = document.getElementById('workflowActionTitle');
            const description = document.getElementById('actionDescription');
            const button = document.getElementById('workflowActionButton');
            const commentRequired = document.getElementById('commentRequired');
            const commentMinimum = document.getElementById('commentMinimum');

            // Configure modal based on action
            switch (action) {
            case 'submit':
                title.textContent = 'Invia in Validazione';
                description.textContent = `Stai per inviare il documento "${fileName}" in validazione. I validatori riceveranno una notifica.`;
                button.textContent = 'Invia in Validazione';
                button.className = 'btn btn-primary';
                commentRequired.style.display = 'none';
                break;

            case 'validate':
                title.textContent = 'Valida Documento';
                description.textContent = `Stai per validare il documento "${fileName}". Il documento passerà alla fase di approvazione.`;
                button.textContent = 'Valida';
                button.className = 'btn btn-success';
                commentRequired.style.display = 'none';
                break;

            case 'reject':
                title.textContent = 'Rifiuta Documento';
                description.textContent = `Stai per rifiutare il documento "${fileName}". Il documento tornerà allo stato bozza e il creatore riceverà una notifica.`;
                button.textContent = 'Rifiuta';
                button.className = 'btn btn-danger';
                commentRequired.style.display = 'inline';
                commentMinimum.style.display = 'none';
                break;

            case 'approve':
                title.textContent = 'Approva Documento';
                description.textContent = `Stai per approvare definitivamente il documento "${fileName}". Il documento sarà considerato ufficiale.`;
                button.textContent = 'Approva';
                button.className = 'btn btn-success';
                commentRequired.style.display = 'none';
                break;

            case 'recall':
                title.textContent = 'Richiama Documento';
                description.textContent = `Stai per richiamare il documento "${fileName}". Il documento tornerà allo stato bozza per ulteriori modifiche.`;
                button.textContent = 'Richiama';
                button.className = 'btn btn-warning';
                commentRequired.style.display = 'none';
                break;
            }

            // Reset form
            document.getElementById('workflowActionForm').reset();
            document.getElementById('commentCharCount').textContent = '0';

            // Show modal
            modal.style.display = 'flex';
        }, 50); // BUG-085 FIX: 50ms delay ensures clean modal transition without overlay stacking
    }

    /**
     * Close action modal
     */
    closeActionModal() {
        document.getElementById('workflowActionModal').style.display = 'none';
    }

    /**
     * Execute workflow action
     */
    async executeAction() {
        const comment = document.getElementById('workflowComment').value;

        // Validate comment for rejection
        if (this.currentAction === 'reject' && comment.length < 20) {
            this.showToast('Il commento deve essere di almeno 20 caratteri per il rifiuto', 'error');
            return;
        }

        try {
            const endpoint = `${this.config.workflowApi}${this.currentAction}.php`;

            const body = {
                file_id: this.state.currentFileId,
                comment: comment || null,
                tenant_id: this.getCurrentTenantId() || null  // BUG-087 FIX: Pass current folder tenant_id
            };

            const response = await fetch(endpoint, {
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
                this.showToast(data.message || 'Azione completata con successo', 'success');
                this.closeActionModal();

                // Note: Workflow status reloaded per file via getWorkflowStatus() when needed
                // Batch loading not supported by backend API

                // Reload dashboard stats
                await this.loadDashboardStats();

                // Update UI
                this.updateWorkflowBadges();
            } else {
                throw new Error(data.error || 'Errore durante l\'esecuzione dell\'azione');
            }
        } catch (error) {
            console.error('[WorkflowManager] Action failed:', error);
            this.showToast(error.message || 'Errore durante l\'esecuzione', 'error');
        }
    }

    /**
     * Show workflow history modal
     * BUG-085 FIX: Close status modal FIRST to prevent overlay stacking (same pattern as showActionModal)
     */
    async showHistoryModal(fileId, fileName) {
        // BUG-085 FIX: Close status modal before opening history modal
        this.closeStatusModal();

        // Small delay for clean modal transition
        setTimeout(async () => {
            const modal = document.getElementById('workflowHistoryModal');
            const title = modal.querySelector('.modal-title');
            title.textContent = `Storico Workflow - ${fileName}`;

            // Load history
            try {
            const response = await fetch(`${this.config.workflowApi}history.php?file_id=${fileId}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

                const data = await response.json();

                if (data.success) {
                    const history = data.data?.history || [];
                    this.renderHistoryTimeline(history);
                }
            } catch (error) {
                console.error('[WorkflowManager] Failed to load history:', error);
                this.showToast('Errore caricamento storico', 'error');
            }

            modal.style.display = 'flex';
        }, 50); // BUG-085 FIX: 50ms delay ensures clean modal transition
    }

    /**
     * Close history modal
     */
    closeHistoryModal() {
        document.getElementById('workflowHistoryModal').style.display = 'none';
    }

    /**
     * Render history timeline
     */
    renderHistoryTimeline(history) {
        const timeline = document.getElementById('workflowTimeline');

        if (!history || history.length === 0) {
            timeline.innerHTML = '<p class="text-muted text-center">Nessuna attività nel workflow</p>';
            return;
        }

        timeline.innerHTML = history.map((entry, index) => {
            const stateInfo = this.workflowStates[entry.new_state] || {};
            const isLast = index === history.length - 1;

            const actionIcons = {
                submit: '📤',
                validate: '✓',
                reject: '❌',
                approve: '✅',
                recall: '↩️',
                create: '➕'
            };

            const icon = actionIcons[entry.action] || '📋';

            return `
                <div class="timeline-item ${isLast ? 'timeline-item-last' : ''}">
                    <div class="timeline-marker" style="background: ${stateInfo.color || '#6b7280'}">
                        ${icon}
                    </div>
                    <div class="timeline-content">
                        <div class="timeline-header">
                            <span class="timeline-state-badge" style="background: ${stateInfo.color || '#6b7280'}">
                                ${stateInfo.icon || ''} ${stateInfo.label || entry.new_state}
                            </span>
                            <span class="timeline-date">
                                ${new Date(entry.created_at).toLocaleString('it-IT')}
                            </span>
                        </div>
                        <div class="timeline-body">
                            <div class="timeline-user">
                                <strong>${entry.user_name}</strong>
                                <span class="text-muted">(${entry.user_role})</span>
                            </div>
                            ${entry.comment ? `
                                <div class="timeline-comment">
                                    <em>"${entry.comment}"</em>
                                </div>
                            ` : ''}
                            ${entry.from_state && entry.from_state !== entry.new_state ? `
                                <div class="timeline-transition">
                                    <span class="text-muted">
                                        ${this.workflowStates[entry.from_state]?.label || entry.from_state}
                                    </span>
                                    →
                                    <span style="color: ${stateInfo.color}">
                                        ${stateInfo.label || entry.new_state}
                                    </span>
                                </div>
                            ` : ''}
                            <div class="timeline-meta">
                                <span class="text-muted">IP: ${entry.ip_address}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    /**
     * Show role configuration modal
     */
    async showRoleConfigModal() {
        const modal = document.getElementById('workflowRoleConfigModal');

        // Load users (this method already populates both dropdowns AND current roles lists)
        // via updateCurrentValidatorsList() and updateCurrentApproversList() at lines 936-937
        await this.loadUsersForRoleConfig();

        // BUG-071 FIX: Removed legacy updateCurrentRolesList() call
        // The legacy method uses this.state.validators/approvers which are EMPTY (pre-BUG-066 structure)
        // loadUsersForRoleConfig() already correctly populates the UI via:
        // - updateCurrentValidatorsList(availableUsers, currentValidators) [line 936]
        // - updateCurrentApproversList(availableUsers, currentApprovers) [line 937]
        // These methods use the normalized API structure (BUG-066) and populate the DOM correctly

        modal.style.display = 'flex';
    }

    /**
     * Close role configuration modal
     */
    closeRoleConfigModal() {
        document.getElementById('workflowRoleConfigModal').style.display = 'none';
    }

    /**
     * Show workflow status modal for a file
     * @param {number} fileId - File ID to show workflow status for
     */
    async showStatusModal(fileId) {
        const modal = document.getElementById('workflowStatusModal');
        const content = document.getElementById('workflowStatusContent');

        if (!modal || !content) {
            console.error('[WorkflowManager] Status modal elements not found');
            return;
        }

        // Show loading
        content.innerHTML = `
            <div class="loading-spinner">
                <div class="spinner"></div>
                <p>Caricamento stato workflow...</p>
            </div>
        `;

        modal.style.display = 'flex';

        try {
            // Fetch workflow status
            const response = await fetch(`${this.config.workflowApi}status.php?file_id=${fileId}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            // Handle 404: file has no workflow (normal case - BUG-052/059)
            if (response.status === 404) {
                console.debug(`[WorkflowManager] File ${fileId} has no workflow`);
                content.innerHTML = `
                    <div class="alert alert-info">
                        <p><strong>Nessun workflow attivo per questo documento.</strong></p>
                        <p>Il workflow può essere avviato inviando il documento per validazione.</p>
                    </div>
                `;
                return;
            }

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Errore durante il caricamento dello stato workflow');
            }

            // Render workflow status
            this.renderWorkflowStatus(content, data.data, fileId);

        } catch (error) {
            console.error('[WorkflowManager] Failed to load workflow status:', error);
            content.innerHTML = `
                <div class="alert alert-danger">
                    <strong>Errore:</strong> ${error.message || 'Impossibile caricare lo stato workflow'}
                </div>
            `;
        }
    }

    /**
     * Close workflow status modal
     */
    closeStatusModal() {
        const modal = document.getElementById('workflowStatusModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    /**
     * Render workflow status in modal content
     * @param {HTMLElement} container - Container element
     * @param {object} data - Workflow status data from API
     * @param {number} fileId - File ID
     */
    renderWorkflowStatus(container, data, fileId) {
        const { file, workflow_exists, workflow, available_actions, user_role_in_workflow, can_start_workflow } = data;

        if (!workflow_exists) {
            // No workflow exists for this file
            container.innerHTML = `
                <div class="alert alert-info">
                    <p><strong>Nessun workflow attivo per questo documento.</strong></p>
                    ${can_start_workflow ? `
                        <p>Puoi avviare il workflow inviando il documento per validazione.</p>
                        <button class="btn btn-primary mt-3" onclick="window.workflowManager.submitForValidation(${fileId}, '${file.name.replace(/'/g, "\\'")}')">
                            Invia per Validazione
                        </button>
                    ` : '<p>Solo il creatore del documento o un Manager/Admin possono avviare il workflow.</p>'}
                </div>
            `;
            return;
        }

        // Workflow exists - render detailed status
        const stateInfo = this.workflowStates[workflow.state] || {};
        const statusHtml = `
            <div class="workflow-status-details">
                <div class="mb-4">
                    <h4>Documento: ${file.name}</h4>
                    <p class="text-muted">ID: ${file.id} | Creato da: ${workflow.creator_name || 'N/A'}</p>
                </div>

                <div class="mb-4">
                    <label class="font-weight-bold">Stato Attuale:</label>
                    <div class="mt-2">
                        ${this.renderWorkflowBadge(workflow.state)}
                    </div>
                </div>

                ${workflow.validator_name ? `
                    <div class="mb-3">
                        <label class="font-weight-bold">Validatore:</label>
                        <p>${workflow.validator_name}</p>
                    </div>
                ` : ''}

                ${workflow.approver_name ? `
                    <div class="mb-3">
                        <label class="font-weight-bold">Approvatore:</label>
                        <p>${workflow.approver_name}</p>
                    </div>
                ` : ''}

                ${workflow.rejection_reason ? `
                    <div class="mb-3 alert alert-warning">
                        <label class="font-weight-bold">Motivo Rifiuto:</label>
                        <p>${workflow.rejection_reason}</p>
                    </div>
                ` : ''}

                <div class="mb-4">
                    <label class="font-weight-bold">Il Tuo Ruolo:</label>
                    <p>${user_role_in_workflow || 'Nessuno (solo visualizzazione)'}</p>
                </div>

                ${available_actions && available_actions.length > 0 ? `
                    <div class="mb-4">
                        <label class="font-weight-bold">Azioni Disponibili:</label>
                        <div class="mt-2">
                            ${available_actions.map(action => {
                                let btnClass = 'btn-secondary';
                                let label = action;

                                if (action === 'submit') {
                                    btnClass = 'btn-primary';
                                    label = 'Invia per Validazione';
                                } else if (action === 'validate') {
                                    btnClass = 'btn-success';
                                    label = 'Valida';
                                } else if (action === 'approve') {
                                    btnClass = 'btn-success';
                                    label = 'Approva';
                                } else if (action === 'reject') {
                                    btnClass = 'btn-danger';
                                    label = 'Rifiuta';
                                } else if (action === 'recall') {
                                    btnClass = 'btn-warning';
                                    label = 'Richiama';
                                }

                                // BUG-085 FIX: Removed closeStatusModal() call - now handled internally by showActionModal()
                                // This prevents double-close and ensures proper modal transition timing
                                return `<button class="btn ${btnClass} mr-2 mb-2" onclick="window.workflowManager.showActionModal('${action}', ${fileId}, '${file.name.replace(/'/g, "\\'")}')">${label}</button>`;
                            }).join('')}
                        </div>
                    </div>
                ` : '<div class="alert alert-info">Nessuna azione disponibile al momento.</div>'}

                <div class="mt-4">
                    <!-- BUG-085 FIX: Removed closeStatusModal() call - now handled internally by showHistoryModal() -->
                    <button class="btn btn-info" onclick="window.workflowManager.showHistoryModal(${fileId}, '${file.name.replace(/'/g, "\\'")}')">
                        Visualizza Storico
                    </button>
                </div>
            </div>
        `;

        container.innerHTML = statusHtml;
    }

    /**
     * Helper method to submit file for validation (called from status modal)
     * @param {number} fileId - File ID
     * @param {string} fileName - File name
     */
    async submitForValidation(fileId, fileName) {
        this.closeStatusModal();
        this.showActionModal('submit', fileId, fileName);
    }

    /**
     * Get current tenant ID from file manager state or DOM (BUG-060 fix)
     * @returns {number|null} Current tenant ID or null if not available
     */
    getCurrentTenantId() {
        // Try multiple sources for tenant ID

        // 1. Check file manager state (most reliable if navigating)
        if (window.fileManager && window.fileManager.state && window.fileManager.state.currentTenantId) {
            return parseInt(window.fileManager.state.currentTenantId);
        }

        // 2. Check hidden field in DOM
        const hiddenField = document.getElementById('currentTenantId');
        if (hiddenField && hiddenField.value) {
            return parseInt(hiddenField.value);
        }

        // 3. Fallback to null (API will use session tenant)
        return null;
    }

    /**
     * Load users for role configuration
     * BUG-059: Use workflow roles API for consistency (uses user_tenant_access JOIN)
     * BUG-060: Pass current tenant_id to API
     * BUG-061: Add debug logging to diagnose dropdown issues
     */
    async loadUsersForRoleConfig() {
        try {
            // Get current tenant ID from file manager or hidden field
            const tenantId = this.getCurrentTenantId();

            // Build API URL with tenant_id parameter
            const apiUrl = tenantId
                ? `${this.config.rolesApi}list.php?tenant_id=${tenantId}`
                : `${this.config.rolesApi}list.php`;

            console.log('[WorkflowManager] Loading roles from:', apiUrl);

            // Fetch with CSRF token
            const token = this.getCsrfToken();
            const response = await fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-Token': token
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Unknown error');
            }

            // Extract from FIXED structure (BUG-066)
            const availableUsers = result.data?.available_users || [];
            const currentValidators = result.data?.current?.validators || [];
            const currentApprovers = result.data?.current?.approvers || [];

            console.log('[WorkflowManager] Roles payload:', {
                available: availableUsers.length,
                validators: currentValidators.length,
                approvers: currentApprovers.length
            });

            // Populate dropdowns
            this.populateValidatorDropdown(availableUsers, currentValidators);
            this.populateApproverDropdown(availableUsers, currentApprovers);

            // Update "Current Roles" sections
            this.updateCurrentValidatorsList(availableUsers, currentValidators);
            this.updateCurrentApproversList(availableUsers, currentApprovers);

            console.log('[WorkflowManager] Populated validator dropdown with', availableUsers.length, 'users');
            console.log('[WorkflowManager] Populated approver dropdown with', availableUsers.length, 'users');

        } catch (error) {
            console.error('[WorkflowManager] Failed to load roles:', error);
            this.showToast('Errore durante il caricamento degli utenti', 'error');
        }
    }

    /**
     * Check if user is a validator
     */
    isValidator(userId) {
        return this.state.validators.some(v => v.user_id == userId);
    }

    /**
     * Check if user is an approver
     */
    isApprover(userId) {
        return this.state.approvers.some(a => a.user_id == userId);
    }

    /**
     * Populate validator dropdown with users (BUG-066 implementation)
     */
    populateValidatorDropdown(users, selectedIds) {
        const select = document.getElementById('validatorUsers');
        if (!select) {
            console.error('[WorkflowManager] #validatorUsers not found');
            return;
        }

        // Clear existing options
        select.innerHTML = '';

        // Empty state
        if (users.length === 0) {
            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = '— Nessun utente disponibile per questo tenant —';
            emptyOption.disabled = true;
            select.appendChild(emptyOption);
            return;
        }

        // Populate with users
        users.forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = `${user.name} (${user.email}) - ${user.system_role}`;

            // Pre-select if in current validators
            if (selectedIds.includes(user.id)) {
                option.selected = true;
            }

            select.appendChild(option);
        });
    }

    /**
     * Populate approver dropdown with users (BUG-066 implementation)
     */
    populateApproverDropdown(users, selectedIds) {
        const select = document.getElementById('approverUsers');
        if (!select) {
            console.error('[WorkflowManager] #approverUsers not found');
            return;
        }

        // Clear existing options
        select.innerHTML = '';

        // Empty state
        if (users.length === 0) {
            const emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = '— Nessun utente disponibile per questo tenant —';
            emptyOption.disabled = true;
            select.appendChild(emptyOption);
            return;
        }

        // Populate with users
        users.forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = `${user.name} (${user.email}) - ${user.system_role}`;

            // Pre-select if in current approvers
            if (selectedIds.includes(user.id)) {
                option.selected = true;
            }

            select.appendChild(option);
        });
    }

    /**
     * Update current validators list in modal (BUG-066 implementation)
     */
    updateCurrentValidatorsList(users, validatorIds) {
        const container = document.getElementById('currentValidators');
        if (!container) return;

        container.innerHTML = '';

        if (validatorIds.length === 0) {
            container.innerHTML = '<p class="text-muted">Nessun validatore assegnato</p>';
            return;
        }

        const ul = document.createElement('ul');
        ul.className = 'list-unstyled';
        validatorIds.forEach(id => {
            const user = users.find(u => u.id === id);
            if (user) {
                const li = document.createElement('li');
                li.textContent = `${user.name} (${user.email})`;
                ul.appendChild(li);
            }
        });
        container.appendChild(ul);
    }

    /**
     * Update current approvers list in modal (BUG-066 implementation)
     */
    updateCurrentApproversList(users, approverIds) {
        const container = document.getElementById('currentApprovers');
        if (!container) return;

        container.innerHTML = '';

        if (approverIds.length === 0) {
            container.innerHTML = '<p class="text-muted">Nessun approvatore assegnato</p>';
            return;
        }

        const ul = document.createElement('ul');
        ul.className = 'list-unstyled';
        approverIds.forEach(id => {
            const user = users.find(u => u.id === id);
            if (user) {
                const li = document.createElement('li');
                li.textContent = `${user.name} (${user.email})`;
                ul.appendChild(li);
            }
        });
        container.appendChild(ul);
    }

    /**
     * Update current roles list in modal (Legacy method - kept for compatibility)
     */
    updateCurrentRolesList() {
        // Update validators list
        const validatorsList = document.getElementById('currentValidators');
        if (validatorsList) {
            validatorsList.innerHTML = this.state.validators.length > 0 ?
                this.state.validators.map(v => `
                    <li class="list-group-item">
                        ${v.user_name} (${v.user_email})
                    </li>
                `).join('') :
                '<li class="list-group-item text-muted">Nessun validatore configurato</li>';
        }

        // Update approvers list
        const approversList = document.getElementById('currentApprovers');
        if (approversList) {
            approversList.innerHTML = this.state.approvers.length > 0 ?
                this.state.approvers.map(a => `
                    <li class="list-group-item">
                        ${a.user_name} (${a.user_email})
                    </li>
                `).join('') :
                '<li class="list-group-item text-muted">Nessun approvatore configurato</li>';
        }
    }

    /**
     * Save validators
     */
    async saveValidators() {
        const select = document.getElementById('validatorUsers');
        const selectedUsers = Array.from(select.selectedOptions).map(opt => parseInt(opt.value));

        await this.saveWorkflowRoles(selectedUsers, 'validator');
    }

    /**
     * Save approvers
     */
    async saveApprovers() {
        const select = document.getElementById('approverUsers');
        const selectedUsers = Array.from(select.selectedOptions).map(opt => parseInt(opt.value));

        await this.saveWorkflowRoles(selectedUsers, 'approver');
    }

    /**
     * Save workflow roles
     * API expects single user_id per call, so we loop
     */
    async saveWorkflowRoles(userIds, role) {
        try {
            // Validate input
            if (!Array.isArray(userIds) || userIds.length === 0) {
                throw new Error('Seleziona almeno un utente');
            }

            let successCount = 0;
            let errorCount = 0;

            // API accepts single user_id, not array
            // Loop through selected users
            for (const userId of userIds) {
                try {
                    const response = await fetch(`${this.config.rolesApi}create.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': this.getCsrfToken()
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            user_id: userId,  // Changed from user_ids to user_id (API expects single)
                            workflow_role: role,  // Changed from role to workflow_role (API parameter name)
                            tenant_id: this.getCurrentTenantId() || null  // BUG-072 FIX: Pass current tenant_id to prevent wrong tenant context
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        successCount++;
                    } else {
                        errorCount++;
                        console.error(`[WorkflowManager] Failed to assign role to user ${userId}:`, data.error);
                    }
                } catch (error) {
                    errorCount++;
                    console.error(`[WorkflowManager] Failed to save role for user ${userId}:`, error);
                }
            }

            // Show result toast
            if (successCount > 0 && errorCount === 0) {
                this.showToast(`${successCount} ${role === 'validator' ? 'validatori' : 'approvatori'} aggiornati con successo`, 'success');
            } else if (successCount > 0 && errorCount > 0) {
                this.showToast(`${successCount} salvati, ${errorCount} errori`, 'warning');
            } else {
                throw new Error('Errore durante il salvataggio');
            }

            // Reload roles and update UI
            await this.loadWorkflowRoles();
            await this.loadUsersForRoleConfig(); // Refresh dropdowns with new data (BUG-066)

        } catch (error) {
            console.error('[WorkflowManager] Failed to save roles:', error);
            this.showToast(error.message || 'Errore durante il salvataggio', 'error');
        }
    }

    /**
     * Update workflow badges on files
     */
    updateWorkflowBadges() {
        // Find all file items
        const fileItems = document.querySelectorAll('.file-item');

        fileItems.forEach(item => {
            const fileId = item.dataset.fileId;

            if (fileId && this.state.workflows.has(parseInt(fileId))) {
                const workflow = this.state.workflows.get(parseInt(fileId));
                this.addWorkflowBadge(item, workflow);
                this.addWorkflowActions(item, workflow);
            }
        });
    }

    /**
     * Add workflow badge to file element
     */
    addWorkflowBadge(element, workflow) {
        // Remove existing badge
        const existingBadge = element.querySelector('.workflow-badge');
        if (existingBadge) {
            existingBadge.remove();
        }

        const stateInfo = this.workflowStates[workflow.state];
        if (!stateInfo) return;

        // Create badge
        const badge = document.createElement('span');
        badge.className = 'workflow-badge';
        badge.innerHTML = `${stateInfo.icon} ${stateInfo.label}`;
        badge.style.cssText = `
            display: inline-block;
            padding: 3px 8px;
            background: ${stateInfo.color};
            color: white;
            font-size: 11px;
            border-radius: 4px;
            margin-left: 8px;
            font-weight: 600;
            cursor: pointer;
        `;
        badge.title = 'Clicca per vedere lo storico workflow';

        // Add click handler
        badge.onclick = (e) => {
            e.stopPropagation();
            const fileName = element.querySelector('.file-name')?.textContent || '';
            this.showHistoryModal(workflow.file_id, fileName);
        };

        // Find appropriate place to insert badge
        const nameElement = element.querySelector('.file-name');
        if (nameElement) {
            nameElement.appendChild(badge);
        }
    }

    /**
     * Render workflow badge HTML for a given state
     * @param {string} state - Workflow state (bozza, in_validazione, etc.)
     * @returns {string} HTML string for badge
     */
    renderWorkflowBadge(state) {
        const stateInfo = this.workflowStates[state];

        if (!stateInfo) {
            console.warn(`[WorkflowManager] Unknown workflow state: ${state}`);
            return '';
        }

        return `
            <span class="workflow-badge"
                  style="display: inline-block; padding: 3px 8px; background: ${stateInfo.color};
                         color: white; font-size: 11px; border-radius: 4px; margin-left: 8px;
                         font-weight: 600; white-space: nowrap;">
                ${stateInfo.icon} ${stateInfo.label}
            </span>
        `;
    }

    /**
     * Add workflow actions to file context menu
     */
    addWorkflowActions(element, workflow) {
        // This will be called when context menu is shown
        element.dataset.workflowState = workflow.state;
        element.dataset.workflowActions = JSON.stringify(workflow.available_actions || []);
    }

    /**
     * Inject workflow UI into file manager
     */
    injectWorkflowUI() {
        // Wait for file manager to be ready
        if (!window.fileManager) {
            console.warn('[WorkflowManager] File manager not ready, retrying...');
            setTimeout(() => this.injectWorkflowUI(), 100);
            return;
        }

        // NOTE: Context menu workflow items are now in files.php HTML (BUG-053 fix)
        // No longer need to inject dynamically to avoid conflicts

        // Add workflow configuration button for managers/admins
        const userRole = document.getElementById('userRole')?.value || window.userRole;
        if (['manager', 'admin', 'super_admin'].includes(userRole)) {
            this.addConfigButton();
        }

        console.log('[WorkflowManager] UI injection complete (context menu items in HTML)');
    }

    /**
     * Add configuration button to toolbar
     */
    addConfigButton() {
        const toolbar = document.querySelector('.file-actions');
        if (toolbar) {
            const configBtn = document.createElement('button');
            configBtn.className = 'btn btn-secondary';
            configBtn.innerHTML = '⚙️ Configura Workflow';
            configBtn.onclick = () => this.showRoleConfigModal();
            toolbar.appendChild(configBtn);
        }
    }

    /**
     * Create and update dashboard widget
     */
    updateDashboardWidget() {
        let widget = document.getElementById('workflowDashboardWidget');

        if (!widget) {
            // Create widget if it doesn't exist
            const dashboardContainer = document.querySelector('.dashboard-widgets, .file-sidebar');
            if (!dashboardContainer) return;

            widget = document.createElement('div');
            widget.id = 'workflowDashboardWidget';
            widget.className = 'dashboard-widget workflow-widget';
            dashboardContainer.appendChild(widget);
        }

        const stats = this.state.dashboardStats;

        widget.innerHTML = `
            <div class="widget-header">
                <h3 class="widget-title">📋 Workflow Documenti</h3>
            </div>
            <div class="widget-body">
                <div class="workflow-stats">
                    <div class="stat-item" onclick="workflowManager.filterByWorkflowState('in_validazione')">
                        <div class="stat-value">${stats.pendingValidation}</div>
                        <div class="stat-label">In attesa di validazione</div>
                    </div>
                    <div class="stat-item" onclick="workflowManager.filterByWorkflowState('in_approvazione')">
                        <div class="stat-value">${stats.pendingApproval}</div>
                        <div class="stat-label">In attesa di approvazione</div>
                    </div>
                    <div class="stat-item" onclick="workflowManager.filterByWorkflowState('my_documents')">
                        <div class="stat-value">${stats.myDocuments}</div>
                        <div class="stat-label">I miei documenti</div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Filter files by workflow state
     */
    filterByWorkflowState(state) {
        console.log('[WorkflowManager] Filtering by state:', state);
        // TODO: Implement filtering logic in file manager
        this.showToast('Filtro workflow: ' + state, 'info');
    }

    /**
     * Setup auto-refresh
     */
    setupAutoRefresh() {
        setInterval(async () => {
            // Note: Workflow statuses loaded lazily per file via getWorkflowStatus()
            // Only refresh dashboard stats (lightweight operation)
            await this.loadDashboardStats();
        }, 30000); // Refresh every 30 seconds
    }

    /**
     * Enable workflow for a folder
     * @param {number} folderId - ID of the folder
     * @param {boolean} applyToSubfolders - Whether to apply to subfolders
     */
    async enableWorkflowForFolder(folderId, applyToSubfolders = false) {
        console.log('[WorkflowManager] Enabling workflow for folder:', folderId);

        try {
            const response = await fetch('/CollaboraNexio/api/workflow/settings/enable.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    entity_type: 'folder',
                    entity_id: folderId,
                    apply_to_children: applyToSubfolders,
                    reason: 'Workflow abilitato per questa cartella'
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Workflow abilitato con successo', 'success');

                // Update UI to show workflow badge
                this.updateFolderWorkflowBadge(folderId, true);

                // Refresh file list if available
                if (window.fileManager) {
                    window.fileManager.loadFiles();
                }

                return true;
            } else {
                this.showToast(data.message || 'Errore durante l\'abilitazione del workflow', 'error');
                return false;
            }
        } catch (error) {
            console.error('[WorkflowManager] Error enabling workflow:', error);
            this.showToast('Errore di connessione', 'error');
            return false;
        }
    }

    /**
     * Disable workflow for a folder
     * @param {number} folderId - ID of the folder
     * @param {boolean} applyToSubfolders - Whether to apply to subfolders
     */
    async disableWorkflowForFolder(folderId, applyToSubfolders = false) {
        console.log('[WorkflowManager] Disabling workflow for folder:', folderId);

        try {
            const response = await fetch('/CollaboraNexio/api/workflow/settings/disable.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    entity_type: 'folder',
                    entity_id: folderId,
                    apply_to_children: applyToSubfolders,
                    reason: 'Workflow disabilitato per questa cartella'
                })
            });

            const data = await response.json();

            if (data.success) {
                this.showToast('Workflow disabilitato con successo', 'success');

                // Update UI to remove workflow badge
                this.updateFolderWorkflowBadge(folderId, false);

                // Refresh file list if available
                if (window.fileManager) {
                    window.fileManager.loadFiles();
                }

                return true;
            } else {
                this.showToast(data.message || 'Errore durante la disabilitazione del workflow', 'error');
                return false;
            }
        } catch (error) {
            console.error('[WorkflowManager] Error disabling workflow:', error);
            this.showToast('Errore di connessione', 'error');
            return false;
        }
    }

    /**
     * Check if workflow is enabled for a folder
     * @param {number} folderId - ID of the folder
     */
    async checkWorkflowStatusForFolder(folderId) {
        console.log('[WorkflowManager] Checking workflow status for folder:', folderId);

        try {
            const response = await fetch(`/CollaboraNexio/api/workflow/settings/status.php?folder_id=${folderId}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-Token': this.getCsrfToken()
                },
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                return data.data;
            } else {
                console.error('[WorkflowManager] Error checking workflow status:', data.message);
                return null;
            }
        } catch (error) {
            console.error('[WorkflowManager] Error checking workflow status:', error);
            return null;
        }
    }

    /**
     * Show workflow settings modal for a folder
     * @param {number} folderId - ID of the folder
     * @param {string} folderName - Name of the folder
     */
    async showWorkflowSettingsModal(folderId, folderName) {
        console.log('[WorkflowManager] Showing workflow settings for folder:', folderId, folderName);

        // Get current status
        const status = await this.checkWorkflowStatusForFolder(folderId);

        // Get modal or create it
        let modal = document.getElementById('workflowSettingsModal');
        if (!modal) {
            modal = this.createWorkflowSettingsModal();
        }

        // Update modal content
        const modalTitle = modal.querySelector('.modal-header h3');
        const modalBody = modal.querySelector('.modal-body');

        modalTitle.textContent = `Impostazioni Workflow - ${folderName}`;

        // Build modal body content
        let statusText = 'Disabilitato';
        let statusColor = '#6b7280';
        let inheritedText = '';

        if (status) {
            if (status.enabled) {
                statusText = 'Abilitato';
                statusColor = '#10b981';
            }

            if (status.inherited_from) {
                inheritedText = `<p class="inherited-info" style="color: #3b82f6; margin-top: 10px;">
                    <i>⚠️ Stato ereditato da: ${status.inherited_from}</i>
                </p>`;
            }

            if (status.configured_by) {
                inheritedText += `<p style="color: #6b7280; font-size: 0.875rem; margin-top: 5px;">
                    Configurato da: ${status.configured_by} il ${new Date(status.configured_at).toLocaleDateString('it-IT')}
                </p>`;
            }
        }

        modalBody.innerHTML = `
            <div class="workflow-settings-content">
                <div class="current-status" style="margin-bottom: 20px; padding: 15px; background: #f9fafb; border-radius: 8px;">
                    <h4 style="margin: 0 0 10px 0;">Stato Corrente</h4>
                    <p style="margin: 0; font-size: 1.125rem;">
                        <span style="display: inline-block; width: 12px; height: 12px; background: ${statusColor}; border-radius: 50%; margin-right: 8px;"></span>
                        <strong>${statusText}</strong>
                    </p>
                    ${inheritedText}
                </div>

                <div class="workflow-toggle" style="margin-bottom: 20px;">
                    <label class="toggle-container" style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="workflowEnabled" ${status?.enabled ? 'checked' : ''}
                               style="width: 20px; height: 20px; margin-right: 10px;">
                        <span>Abilita workflow per questa cartella</span>
                    </label>
                </div>

                <div class="apply-to-subfolders" style="margin-bottom: 20px;">
                    <label class="checkbox-container" style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="applyToSubfolders"
                               style="width: 20px; height: 20px; margin-right: 10px;">
                        <span>Applica a tutte le sottocartelle</span>
                    </label>
                </div>

                <div class="workflow-info" style="padding: 15px; background: #fef3c7; border-radius: 8px; border-left: 4px solid #f59e0b;">
                    <p style="margin: 0; color: #92400e;">
                        <strong>ℹ️ Informazione:</strong> Quando il workflow è abilitato, tutti i nuovi documenti
                        caricati in questa cartella inizieranno automaticamente come "Bozza" e dovranno
                        seguire il processo di validazione e approvazione.
                    </p>
                </div>
            </div>
        `;

        // Update footer buttons
        const modalFooter = modal.querySelector('.modal-footer');
        modalFooter.innerHTML = `
            <button type="button" class="btn btn-secondary" onclick="window.workflowManager?.closeWorkflowSettingsModal()">
                Annulla
            </button>
            <button type="button" class="btn btn-primary" onclick="window.workflowManager?.saveWorkflowSettings(${folderId})">
                Salva Impostazioni
            </button>
        `;

        // Show modal
        modal.style.display = 'flex';
    }

    /**
     * Create workflow settings modal
     */
    createWorkflowSettingsModal() {
        const modal = document.createElement('div');
        modal.id = 'workflowSettingsModal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Impostazioni Workflow</h3>
                    <button type="button" class="close" onclick="window.workflowManager?.closeWorkflowSettingsModal()">×</button>
                </div>
                <div class="modal-body">
                    <!-- Content will be populated dynamically -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="window.workflowManager?.closeWorkflowSettingsModal()">
                        Annulla
                    </button>
                    <button type="button" class="btn btn-primary" onclick="window.workflowManager?.saveWorkflowSettings()">
                        Salva
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        return modal;
    }

    /**
     * Close workflow settings modal
     */
    closeWorkflowSettingsModal() {
        const modal = document.getElementById('workflowSettingsModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    /**
     * Save workflow settings
     * @param {number} folderId - ID of the folder
     */
    async saveWorkflowSettings(folderId) {
        const enabledCheckbox = document.getElementById('workflowEnabled');
        const applyToSubfoldersCheckbox = document.getElementById('applyToSubfolders');

        if (!enabledCheckbox) return;

        const enabled = enabledCheckbox.checked;
        const applyToSubfolders = applyToSubfoldersCheckbox?.checked || false;

        let success;
        if (enabled) {
            success = await this.enableWorkflowForFolder(folderId, applyToSubfolders);
        } else {
            success = await this.disableWorkflowForFolder(folderId, applyToSubfolders);
        }

        if (success) {
            this.closeWorkflowSettingsModal();
        }
    }

    /**
     * Update folder workflow badge in UI
     * @param {number} folderId - ID of the folder
     * @param {boolean} enabled - Whether workflow is enabled
     */
    updateFolderWorkflowBadge(folderId, enabled) {
        // Find folder element
        const folderElements = document.querySelectorAll(`[data-folder-id="${folderId}"], [data-id="${folderId}"][data-type="folder"]`);

        folderElements.forEach(element => {
            // Remove existing badge
            const existingBadge = element.querySelector('.workflow-folder-badge');
            if (existingBadge) {
                existingBadge.remove();
            }

            // Add new badge if enabled
            if (enabled) {
                const badge = document.createElement('span');
                badge.className = 'workflow-folder-badge';
                badge.innerHTML = '📋';
                badge.title = 'Workflow Attivo';
                badge.style.cssText = `
                    position: absolute;
                    top: 5px;
                    right: 5px;
                    background: #10b981;
                    color: white;
                    border-radius: 12px;
                    padding: 2px 8px;
                    font-size: 0.75rem;
                    font-weight: 600;
                    z-index: 10;
                `;

                element.style.position = 'relative';
                element.appendChild(badge);
            }
        });
    }

    /**
     * Show toast notification
     */
    showToast(message, type = 'info') {
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

        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}

// Initialize when document is ready
document.addEventListener('DOMContentLoaded', () => {
    window.workflowManager = new DocumentWorkflowManager();
});