/**
 * TicketManager - Sistema di Gestione Ticket per CollaboraNexio
 *
 * Gestisce:
 * - Caricamento e visualizzazione ticket
 * - Statistiche dashboard
 * - Creazione/modifica/chiusura ticket
 * - Risposte e note ai ticket
 * - Filtri e ricerca
 *
 * @version 1.0.0
 * @requires CSRF token in meta tag
 */

class TicketManager {
    constructor(userConfig = {}) {
        this.config = {
            apiBase: '/CollaboraNexio/api/tickets',
            endpoints: {
                list: '/list.php',
                create: '/create.php',
                update: '/update.php',
                get: '/get.php',
                respond: '/respond.php',
                assign: '/assign.php',
                updateStatus: '/update_status.php',
                delete: '/delete.php',
                close: '/close.php',
                stats: '/stats.php'
            },
            // User context (passed from PHP)
            userRole: userConfig.userRole || 'user',
            userId: userConfig.userId || null,
            userName: userConfig.userName || 'Unknown'
        };

        this.state = {
            tickets: [],
            stats: {},
            filters: {
                status: '',
                urgency: '',
                search: '',
                created_by_me: '',
                assigned_to_me: ''
            },
            currentPage: 1,
            totalPages: 1,
            currentTicket: null
        };

        this.init();
    }

    /**
     * Inizializzazione componente
     */
    init() {
        console.log('[TicketManager] Initializing...');

        this.setupEventListeners();
        this.loadStats();
        this.loadTickets();

        console.log('[TicketManager] Initialization complete');
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Filter listeners
        const statusFilter = document.getElementById('status-filter');
        const priorityFilter = document.getElementById('priority-filter');
        const assignedFilter = document.getElementById('assigned-filter');
        const searchInput = document.getElementById('search-input');

        if (statusFilter) {
            statusFilter.addEventListener('change', () => {
                this.state.filters.status = statusFilter.value;
                this.state.currentPage = 1;  // Reset to first page
                this.loadTickets();
            });
        }

        if (priorityFilter) {
            priorityFilter.addEventListener('change', () => {
                this.state.filters.urgency = priorityFilter.value;  // Note: filter is called priority but sets urgency
                this.state.currentPage = 1;
                this.loadTickets();
            });
        }

        if (assignedFilter) {
            assignedFilter.addEventListener('change', () => {
                const value = assignedFilter.value;
                // Handle special filter values
                if (value === 'mine') {
                    this.state.filters.created_by_me = '1';
                    delete this.state.filters.assigned_to_me;
                } else if (value === 'assigned') {
                    this.state.filters.assigned_to_me = '1';
                    delete this.state.filters.created_by_me;
                } else {
                    delete this.state.filters.created_by_me;
                    delete this.state.filters.assigned_to_me;
                }
                this.state.currentPage = 1;
                this.loadTickets();
            });
        }

        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.state.filters.search = e.target.value;
                    this.state.currentPage = 1;
                    this.loadTickets();
                }, 300);
            });
        }

        // Create ticket button
        const createBtn = document.getElementById('create-ticket-btn');
        if (createBtn) {
            createBtn.addEventListener('click', () => this.showCreateModal());
        }
    }

    /**
     * Carica statistiche dashboard
     */
    async loadStats() {
        try {
            const response = await this.apiRequest(this.config.endpoints.stats);

            if (response.success) {
                // API returns data directly (BUG-022 compliant)
                this.state.stats = response.data || {};
                this.renderStats();
            } else {
                console.error('[TicketManager] Failed to load stats:', response.message);
            }
        } catch (error) {
            console.error('[TicketManager] Error loading stats:', error);
        }
    }

    /**
     * Renderizza statistiche
     */
    renderStats() {
        const stats = this.state.stats;
        const summary = stats.summary || {};

        // Update stat cards
        const totalElem = document.querySelector('.stat-card:nth-child(1) .stat-number');
        const openElem = document.querySelector('.stat-card:nth-child(2) .stat-number');
        const closedElem = document.querySelector('.stat-card:nth-child(3) .stat-number');
        const avgTimeElem = document.querySelector('.stat-card:nth-child(4) .stat-number');

        if (totalElem) totalElem.textContent = summary.total || 0;
        if (openElem) openElem.textContent = summary.open || 0;
        if (closedElem) closedElem.textContent = (summary.resolved || 0) + (summary.closed || 0);
        if (avgTimeElem) {
            const avgTime = stats.avg_response_time_hours || (stats.avg_response_time_minutes ? stats.avg_response_time_minutes / 60 : 0);
            avgTimeElem.textContent = avgTime > 0 ? `${avgTime.toFixed(1)}h` : '0h';
        }

        console.log('[TicketManager] Stats updated:', summary);
    }

    /**
     * Carica lista ticket con filtri
     */
    async loadTickets() {
        try {
            const params = new URLSearchParams({
                page: this.state.currentPage,
                per_page: 20
            });

            // Add filters
            if (this.state.filters.status) params.append('status', this.state.filters.status);
            if (this.state.filters.urgency) params.append('urgency', this.state.filters.urgency);
            if (this.state.filters.search) params.append('search', this.state.filters.search);
            if (this.state.filters.created_by_me) params.append('created_by_me', this.state.filters.created_by_me);
            if (this.state.filters.assigned_to_me) params.append('assigned_to_me', this.state.filters.assigned_to_me);

            const response = await this.apiRequest(
                `${this.config.endpoints.list}?${params.toString()}`
            );

            if (response.success) {
                this.state.tickets = response.data?.tickets || [];
                this.state.currentPage = response.data?.pagination?.current_page || 1;
                this.state.totalPages = response.data?.pagination?.total_pages || 1;

                this.renderTickets();
                this.renderPagination();

                console.log(`[TicketManager] Loaded ${this.state.tickets.length} tickets`);
            } else {
                console.error('[TicketManager] Failed to load tickets:', response.message);
                this.showError('Errore caricamento ticket');
            }
        } catch (error) {
            console.error('[TicketManager] Error loading tickets:', error);
            this.showError('Errore di connessione');
        }
    }

    /**
     * Renderizza tabella ticket
     */
    renderTickets() {
        const tbody = document.querySelector('.tickets-table tbody');
        if (!tbody) return;

        if (this.state.tickets.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align: center; padding: 2rem;">
                        <div style="color: #6c757d;">
                            <i class="fas fa-inbox fa-3x" style="opacity: 0.3; margin-bottom: 1rem;"></i>
                            <p>Nessun ticket trovato</p>
                            <small>Prova a modificare i filtri o crea un nuovo ticket</small>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = this.state.tickets.map(ticket => `
            <tr data-ticket-id="${this.escapeHtml(String(ticket.id))}" style="cursor: pointer;" onclick="window.ticketManager.viewTicket(${parseInt(ticket.id, 10)})">
                <td>
                    <strong>#${this.escapeHtml(ticket.ticket_number)}</strong>
                </td>
                <td>
                    <div>${this.escapeHtml(ticket.subject)}</div>
                    <small class="text-muted">${this.escapeHtml(ticket.requester_name || '')}</small>
                </td>
                <td>
                    <span class="badge badge-${this.getStatusColor(ticket.status)}">
                        ${this.getStatusLabel(ticket.status)}
                    </span>
                </td>
                <td>
                    <span class="badge badge-${this.getUrgencyColor(ticket.urgency)}">
                        ${this.getUrgencyLabel(ticket.urgency)}
                    </span>
                </td>
                <td>
                    <small class="text-muted">${this.escapeHtml(ticket.category || 'N/A')}</small>
                </td>
                <td>
                    ${ticket.assigned_to_name ?
                        `<small>${this.escapeHtml(ticket.assigned_to_name)}</small>` :
                        '<small class="text-muted">Non assegnato</small>'
                    }
                </td>
                <td>
                    <small class="text-muted">${this.formatDate(ticket.created_at)}</small>
                </td>
            </tr>
        `).join('');
    }

    /**
     * Renderizza paginazione
     */
    renderPagination() {
        const paginationContainer = document.querySelector('.pagination-container');
        if (!paginationContainer || this.state.totalPages <= 1) {
            if (paginationContainer) paginationContainer.innerHTML = '';
            return;
        }

        const pagination = [];
        pagination.push(`
            <button class="btn btn-sm btn-outline-primary"
                    ${this.state.currentPage === 1 ? 'disabled' : ''}
                    onclick="window.ticketManager.changePage(${this.state.currentPage - 1})">
                <i class="fas fa-chevron-left"></i> Precedente
            </button>
        `);

        pagination.push(`
            <span class="mx-3">Pagina ${this.state.currentPage} di ${this.state.totalPages}</span>
        `);

        pagination.push(`
            <button class="btn btn-sm btn-outline-primary"
                    ${this.state.currentPage === this.state.totalPages ? 'disabled' : ''}
                    onclick="window.ticketManager.changePage(${this.state.currentPage + 1})">
                Successiva <i class="fas fa-chevron-right"></i>
            </button>
        `);

        paginationContainer.innerHTML = pagination.join('');
    }

    /**
     * Cambia pagina
     */
    changePage(page) {
        if (page < 1 || page > this.state.totalPages) return;
        this.state.currentPage = page;
        this.loadTickets();
    }

    /**
     * Visualizza dettagli ticket
     */
    async viewTicket(ticketId) {
        try {
            const response = await this.apiRequest(`${this.config.endpoints.get}?id=${ticketId}`);

            if (response.success) {
                // Save complete ticket data including responses, assignments, history
                const ticketData = response.data?.ticket || null;
                if (ticketData) {
                    // Attach related data to ticket object
                    ticketData.responses = response.data?.responses || [];
                    ticketData.assignments = response.data?.assignments || [];
                    ticketData.history = response.data?.history || [];
                    ticketData.response_count = response.data?.response_count || 0;
                }
                this.state.currentTicket = ticketData;
                this.showTicketDetailModal();
            } else {
                this.showError('Errore caricamento ticket');
            }
        } catch (error) {
            console.error('[TicketManager] Error viewing ticket:', error);
            this.showError('Errore di connessione');
        }
    }

    /**
     * Mostra modal creazione ticket
     */
    showCreateModal() {
        const modal = document.getElementById('create-ticket-modal');
        if (modal) {
            modal.style.display = 'flex';
            // Reset form
            const form = document.getElementById('create-ticket-form');
            if (form) form.reset();
            // Hide error
            const error = document.getElementById('create-ticket-error');
            if (error) error.style.display = 'none';
        }
    }

    /**
     * Chiudi modal creazione ticket
     */
    closeCreateModal() {
        const modal = document.getElementById('create-ticket-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    /**
     * Gestisci submit form creazione ticket
     */
    async handleCreateSubmit(event) {
        event.preventDefault();

        const submitBtn = document.getElementById('create-ticket-submit-btn');
        const errorDiv = document.getElementById('create-ticket-error');

        // Disable submit button
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="icon icon--spinner"></i> Creazione...';
        }

        // Hide previous errors
        if (errorDiv) errorDiv.style.display = 'none';

        // Get form data
        const formData = {
            subject: document.getElementById('ticket-subject')?.value || '',
            category: document.getElementById('ticket-category')?.value || '',
            urgency: document.getElementById('ticket-urgency')?.value || 'medium',
            description: document.getElementById('ticket-description')?.value || ''
        };

        try {
            const response = await this.apiRequest(this.config.endpoints.create, {
                method: 'POST',
                body: JSON.stringify(formData)
            });

            if (response.success) {
                this.showSuccess('Ticket creato con successo!');
                this.closeCreateModal();
                // Reload tickets and stats
                this.loadTickets();
                this.loadStats();
            } else {
                if (errorDiv) {
                    errorDiv.textContent = response.message || 'Errore durante la creazione del ticket';
                    errorDiv.style.display = 'block';
                }
            }
        } catch (error) {
            console.error('[TicketManager] Error creating ticket:', error);
            if (errorDiv) {
                errorDiv.textContent = 'Errore di connessione. Riprova più tardi.';
                errorDiv.style.display = 'block';
            }
        } finally {
            // Re-enable submit button
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="icon icon--save"></i> Crea Ticket';
            }
        }
    }

    /**
     * Mostra modal dettaglio ticket
     */
    showTicketDetailModal() {
        console.log('[TicketManager] Opening ticket detail modal');

        const ticket = this.state.currentTicket;
        if (!ticket) {
            console.error('[TicketManager] No ticket data available');
            return;
        }

        // Populate modal with ticket data
        document.getElementById('detail-ticket-number').textContent = ticket.ticket_number || '';
        document.getElementById('detail-ticket-subject').textContent = ticket.subject || '';
        document.getElementById('detail-ticket-description').textContent = ticket.description || '';

        // Status badge
        const statusBadge = document.getElementById('detail-ticket-status-badge');
        statusBadge.textContent = this.getStatusLabel(ticket.status);
        statusBadge.className = `status-badge status-${this.getStatusColor(ticket.status)}`;

        // Urgency badge
        const urgencyBadge = document.getElementById('detail-ticket-urgency-badge');
        urgencyBadge.textContent = this.getUrgencyLabel(ticket.urgency);
        urgencyBadge.className = `priority-badge priority-${this.getUrgencyColor(ticket.urgency)}`;

        // Category badge
        document.getElementById('detail-ticket-category-badge').textContent = this.getCategoryLabel(ticket.category);

        // Metadata
        document.getElementById('detail-ticket-creator').textContent = ticket.created_by_name || 'Sconosciuto';
        document.getElementById('detail-ticket-assigned').textContent = ticket.assigned_to_name || 'Non assegnato';
        document.getElementById('detail-ticket-created').textContent = this.formatDate(ticket.created_at);
        document.getElementById('detail-ticket-updated').textContent = this.formatDate(ticket.updated_at);

        // Render responses
        this.renderResponses(this.state.currentTicket.responses || []);

        // Update response count
        const responseCount = (this.state.currentTicket.responses || []).length;
        document.getElementById('detail-response-count').textContent = responseCount;

        // Show/hide admin actions based on role
        const isAdmin = this.config.userRole === 'admin' || this.config.userRole === 'super_admin';
        const adminActionsSection = document.getElementById('detail-admin-actions');
        const internalNoteSection = document.getElementById('detail-internal-note-section');

        if (isAdmin) {
            adminActionsSection.style.display = 'block';
            internalNoteSection.style.display = 'block';

            // Populate status dropdown (set current status as selected)
            const statusSelect = document.getElementById('detail-change-status');
            statusSelect.value = '';  // Reset to placeholder

            // Populate assign dropdown
            this.populateAssignDropdown();
        } else {
            adminActionsSection.style.display = 'none';
            internalNoteSection.style.display = 'none';
        }

        // Show/hide delete button (super_admin only, closed tickets only)
        const isSuperAdmin = this.config.userRole === 'super_admin';
        const isTicketClosed = ticket.status === 'closed';
        const deleteSection = document.getElementById('detail-delete-section');

        if (deleteSection) {
            if (isSuperAdmin && isTicketClosed) {
                deleteSection.style.display = 'block';
            } else {
                deleteSection.style.display = 'none';
            }
        }

        // Show modal
        document.getElementById('ticket-detail-modal').style.display = 'flex';

        console.log('[TicketManager] Ticket detail modal opened');
    }

    /**
     * Chiudi modal dettaglio ticket
     */
    closeTicketDetailModal() {
        document.getElementById('ticket-detail-modal').style.display = 'none';
        document.getElementById('ticket-reply-form').reset();
        this.state.currentTicket = null;
        console.log('[TicketManager] Ticket detail modal closed');
    }

    /**
     * Renderizza le risposte del ticket
     */
    renderResponses(responses) {
        const container = document.getElementById('detail-responses-container');
        const noResponsesDiv = document.getElementById('detail-no-responses');

        if (!responses || responses.length === 0) {
            noResponsesDiv.style.display = 'block';
            container.querySelectorAll('.response-item').forEach(item => item.remove());
            return;
        }

        noResponsesDiv.style.display = 'none';

        // Clear existing responses (except no-responses placeholder)
        container.querySelectorAll('.response-item').forEach(item => item.remove());

        // Render each response
        responses.forEach(response => {
            const responseDiv = document.createElement('div');
            responseDiv.className = 'response-item';
            responseDiv.style.cssText = `
                margin-bottom: 16px;
                padding: 16px;
                background: ${response.is_internal_note ? '#FEF3C7' : '#F9FAFB'};
                border-left: 4px solid ${response.is_internal_note ? '#F59E0B' : '#2563EB'};
                border-radius: 8px;
            `;

            const header = document.createElement('div');
            header.style.cssText = 'display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;';

            const userInfo = document.createElement('div');
            userInfo.innerHTML = `
                <div style="font-weight: 600; color: #1F2937; font-size: 14px;">${this.escapeHtml(response.user_name)}</div>
                <div style="font-size: 12px; color: #6B7280;">${this.formatDate(response.created_at)}</div>
            `;

            const badges = document.createElement('div');
            badges.style.cssText = 'display: flex; gap: 6px;';

            if (response.is_internal_note) {
                const internalBadge = document.createElement('span');
                internalBadge.textContent = 'Nota Interna';
                internalBadge.style.cssText = `
                    display: inline-block;
                    padding: 4px 8px;
                    background: #F59E0B;
                    color: white;
                    border-radius: 4px;
                    font-size: 11px;
                    font-weight: 600;
                `;
                badges.appendChild(internalBadge);
            }

            header.appendChild(userInfo);
            header.appendChild(badges);

            const message = document.createElement('div');
            message.style.cssText = 'font-size: 14px; line-height: 1.6; color: #1F2937; white-space: pre-wrap;';
            message.textContent = response.message;

            responseDiv.appendChild(header);
            responseDiv.appendChild(message);

            container.appendChild(responseDiv);
        });
    }

    /**
     * Invia risposta al ticket
     */
    async submitReply(event) {
        event.preventDefault();

        const messageInput = document.getElementById('reply-message');
        const isInternalCheckbox = document.getElementById('reply-is-internal');
        const submitBtn = document.getElementById('reply-submit-btn');

        const message = messageInput.value.trim();
        if (!message) {
            this.showError('La risposta non può essere vuota');
            return;
        }

        const ticketId = this.state.currentTicket?.id;
        if (!ticketId) {
            this.showError('ID ticket non disponibile');
            return;
        }

        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="icon icon--save"></i> Invio in corso...';

        try {
            const response = await this.apiRequest(this.config.endpoints.respond, {
                method: 'POST',
                body: JSON.stringify({
                    ticket_id: ticketId,
                    message: message,
                    is_internal_note: isInternalCheckbox?.checked || false
                })
            });

            if (response.success) {
                console.log('[TicketManager] Reply submitted successfully');

                // Clear form
                messageInput.value = '';
                if (isInternalCheckbox) isInternalCheckbox.checked = false;

                // Reload ticket to get updated responses
                await this.viewTicket(ticketId);

                // Show success message
                alert('Risposta inviata con successo!');
            } else {
                this.showError(response.message || 'Errore nell\'invio della risposta');
            }
        } catch (error) {
            console.error('[TicketManager] Error submitting reply:', error);
            this.showError('Errore di connessione');
        } finally {
            // Re-enable submit button
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="icon icon--save"></i> Invia Risposta';
        }
    }

    /**
     * Cambia stato del ticket
     */
    async changeTicketStatus(newStatus) {
        if (!newStatus) return;

        const ticketId = this.state.currentTicket?.id;
        if (!ticketId) {
            this.showError('ID ticket non disponibile');
            return;
        }

        // Confirm action
        const statusLabel = this.getStatusLabel(newStatus);
        if (!confirm(`Confermi di voler cambiare lo stato del ticket in "${statusLabel}"?`)) {
            // Reset dropdown
            document.getElementById('detail-change-status').value = '';
            return;
        }

        try {
            const response = await this.apiRequest(this.config.endpoints.updateStatus, {
                method: 'POST',
                body: JSON.stringify({
                    ticket_id: ticketId,
                    status: newStatus
                })
            });

            if (response.success) {
                console.log('[TicketManager] Status changed successfully');

                // Reload ticket and ticket list
                await this.viewTicket(ticketId);
                await this.loadTickets();

                alert('Stato del ticket aggiornato con successo!');
            } else {
                this.showError(response.message || 'Errore nell\'aggiornamento dello stato');
            }
        } catch (error) {
            console.error('[TicketManager] Error changing status:', error);
            this.showError('Errore di connessione');
        }

        // Reset dropdown
        document.getElementById('detail-change-status').value = '';
    }

    /**
     * Assegna ticket a un utente
     */
    async assignTicket(userId) {
        if (!userId) return;

        const ticketId = this.state.currentTicket?.id;
        if (!ticketId) {
            this.showError('ID ticket non disponibile');
            return;
        }

        // Find user name for confirmation
        const assignSelect = document.getElementById('detail-assign-to');
        const selectedOption = assignSelect.options[assignSelect.selectedIndex];
        const userName = selectedOption.text;

        if (!confirm(`Confermi di voler assegnare il ticket a "${userName}"?`)) {
            // Reset dropdown
            assignSelect.value = '';
            return;
        }

        try {
            const response = await this.apiRequest(this.config.endpoints.assign, {
                method: 'POST',
                body: JSON.stringify({
                    ticket_id: ticketId,
                    assigned_to: userId
                })
            });

            if (response.success) {
                console.log('[TicketManager] Ticket assigned successfully');

                // Reload ticket and ticket list
                await this.viewTicket(ticketId);
                await this.loadTickets();

                alert('Ticket assegnato con successo!');
            } else {
                this.showError(response.message || 'Errore nell\'assegnazione del ticket');
            }
        } catch (error) {
            console.error('[TicketManager] Error assigning ticket:', error);
            this.showError('Errore di connessione');
        }

        // Reset dropdown
        document.getElementById('detail-assign-to').value = '';
    }

    /**
     * Elimina ticket (super_admin only, closed tickets only)
     */
    async deleteTicket() {
        const ticket = this.state.currentTicket;
        if (!ticket) {
            this.showError('Nessun ticket selezionato');
            return;
        }

        // CLIENT-SIDE VALIDATION (defense in depth)
        if (this.config.userRole !== 'super_admin') {
            this.showError('Solo i super_admin possono eliminare i ticket');
            return;
        }

        if (ticket.status !== 'closed') {
            this.showError('Solo i ticket chiusi possono essere eliminati');
            return;
        }

        // Double confirmation for delete action
        if (!confirm(`⚠️ ATTENZIONE!\n\nStai per eliminare definitivamente il ticket #${ticket.ticket_number}.\n\nQuesta azione è IRREVERSIBILE e sarà registrata nel log di sistema.\n\nConfermi l'eliminazione?`)) {
            return;
        }

        // Second confirmation
        if (!confirm('Sei ASSOLUTAMENTE SICURO?\n\nDigita OK nella prossima finestra per confermare.')) {
            return;
        }

        const deleteBtn = document.getElementById('detail-delete-btn');
        const originalText = deleteBtn.innerHTML;

        try {
            // Disable button and show loading state
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="icon icon--spinner"></i> Eliminazione in corso...';

            const response = await this.apiRequest(this.config.endpoints.delete, {
                method: 'POST',
                body: JSON.stringify({
                    ticket_id: ticket.id
                })
            });

            if (response.success) {
                console.log('[TicketManager] Ticket deleted successfully');

                // Close modal
                this.closeTicketDetailModal();

                // Reload ticket list and stats
                await this.loadTickets();
                await this.loadStats();

                alert(`✅ Ticket #${ticket.ticket_number} eliminato con successo!\n\nL'eliminazione è stata registrata nel log di sistema.`);
            } else {
                this.showError(response.message || 'Errore nell\'eliminazione del ticket');
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = originalText;
            }
        } catch (error) {
            console.error('[TicketManager] Error deleting ticket:', error);
            this.showError('Errore di connessione durante l\'eliminazione');
            deleteBtn.disabled = false;
            deleteBtn.innerHTML = originalText;
        }
    }

    /**
     * Popola dropdown assegnazione con lista utenti
     */
    async populateAssignDropdown() {
        const assignSelect = document.getElementById('detail-assign-to');

        // Clear existing options except placeholder
        while (assignSelect.options.length > 1) {
            assignSelect.remove(1);
        }

        // Get users list from state or load if needed
        if (!this.state.users || this.state.users.length === 0) {
            // RACE CONDITION PROTECTION: Check if already loading
            if (this._loadingUsers) {
                console.log('[TicketManager] Users already loading, waiting...');
                await this._loadingUsers;
                // After loading completes, populate dropdown
                this._populateDropdownOptions(assignSelect);
                return;
            }

            // Set loading state with loading indicator
            assignSelect.disabled = true;
            const loadingOption = document.createElement('option');
            loadingOption.value = '';
            loadingOption.textContent = 'Caricamento utenti...';
            assignSelect.appendChild(loadingOption);

            try {
                // Create promise to track loading state
                this._loadingUsers = (async () => {
                    // Use direct fetch for cross-module API call (not using apiBase which points to /tickets)
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    const response = await fetch('/CollaboraNexio/api/users/list_managers.php', {
                        method: 'GET',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken || ''
                        },
                        credentials: 'same-origin'
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }

                    const data = await response.json();

                    if (data.success) {
                        // API returns data directly as array, not nested in data.users
                        this.state.users = data.data || [];
                        console.log(`[TicketManager] Loaded ${this.state.users.length} users for assignment dropdown`);
                    } else {
                        throw new Error(data.error || data.message || 'Failed to load users');
                    }
                })();

                // Wait for loading to complete
                await this._loadingUsers;

                // Clear loading indicator
                assignSelect.remove(1);
                assignSelect.disabled = false;

            } catch (error) {
                console.error('[TicketManager] Error loading users:', error);

                // Show user-friendly error
                this.showError(`Impossibile caricare la lista utenti: ${error.message}`);

                // Clear loading indicator
                if (assignSelect.options.length > 1) {
                    assignSelect.remove(1);
                }
                assignSelect.disabled = false;

                return;
            } finally {
                // Clear loading flag
                this._loadingUsers = null;
            }
        }

        // Populate dropdown with loaded users
        this._populateDropdownOptions(assignSelect);
    }

    /**
     * Helper: Populate dropdown with user options
     */
    _populateDropdownOptions(assignSelect) {
        this.state.users.forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = `${user.name} (${user.email})`;
            assignSelect.appendChild(option);
        });
    }

    /**
     * Utility: Get category label
     */
    getCategoryLabel(category) {
        const labels = {
            'technical': 'Tecnico',
            'billing': 'Fatturazione',
            'feature_request': 'Richiesta Funzionalità',
            'bug_report': 'Segnalazione Bug',
            'general': 'Generale',
            'other': 'Altro'
        };
        return labels[category] || category;
    }

    /**
     * API request helper
     */
    async apiRequest(endpoint, options = {}) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken || ''
            },
            credentials: 'same-origin'
        };

        const finalOptions = { ...defaultOptions, ...options };

        const url = `${this.config.apiBase}${endpoint}`;

        try {
            const response = await fetch(url, finalOptions);
            const data = await response.json();
            return data;
        } catch (error) {
            console.error('[TicketManager] API request failed:', error);
            throw error;
        }
    }

    /**
     * Utility: Get status color
     */
    getStatusColor(status) {
        const colors = {
            'open': 'primary',
            'in_progress': 'info',
            'waiting_customer': 'warning',
            'waiting_staff': 'warning',
            'resolved': 'success',
            'closed': 'secondary'
        };
        return colors[status] || 'secondary';
    }

    /**
     * Utility: Get status label
     */
    getStatusLabel(status) {
        const labels = {
            'open': 'Aperto',
            'in_progress': 'In Lavorazione',
            'waiting_customer': 'In Attesa Cliente',
            'waiting_staff': 'In Attesa Staff',
            'resolved': 'Risolto',
            'closed': 'Chiuso'
        };
        return labels[status] || status;
    }

    /**
     * Utility: Get urgency color
     */
    getUrgencyColor(urgency) {
        const colors = {
            'low': 'success',
            'medium': 'info',
            'high': 'warning',
            'critical': 'danger'
        };
        return colors[urgency] || 'secondary';
    }

    /**
     * Utility: Get urgency label
     */
    getUrgencyLabel(urgency) {
        const labels = {
            'low': 'Bassa',
            'medium': 'Normale',
            'high': 'Alta',
            'critical': 'Critica'
        };
        return labels[urgency] || urgency;
    }

    /**
     * Utility: Format date
     */
    formatDate(dateString) {
        if (!dateString) return 'N/A';

        const date = new Date(dateString);
        const now = new Date();
        const diff = now - date;
        const minutes = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days = Math.floor(diff / 86400000);

        if (minutes < 1) return 'Ora';
        if (minutes < 60) return `${minutes}m fa`;
        if (hours < 24) return `${hours}h fa`;
        if (days < 7) return `${days}g fa`;

        return date.toLocaleDateString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    }

    /**
     * Utility: Escape HTML
     */
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Show error message
     */
    showError(message) {
        console.error('[TicketManager] Error:', message);
        // TODO: Implement toast notification
        alert(message);
    }

    /**
     * Show success message
     */
    showSuccess(message) {
        console.log('[TicketManager] Success:', message);
        // TODO: Implement toast notification
        alert(message);
    }
}

// Export for use in ticket.php
if (typeof window !== 'undefined') {
    window.TicketManager = TicketManager;
}
