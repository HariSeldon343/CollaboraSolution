/**
 * CollaboraNexio Document Editor - OnlyOffice Integration
 *
 * Vanilla JavaScript module for integrating OnlyOffice Document Editor
 * with the CollaboraNexio file management system.
 *
 * Features:
 * - Full-screen modal editor
 * - Real-time collaborative editing
 * - Auto-save functionality
 * - Role-based permissions (user/manager/admin/super_admin)
 * - Multi-tenant isolation
 * - Italian language support
 *
 * @version 1.0.0
 * @author CollaboraNexio Development Team
 * @requires OnlyOffice Document Server API (http://localhost:8083)
 */

'use strict';

/**
 * DocumentEditor Class
 * Manages the complete lifecycle of document editing sessions
 */
class DocumentEditor {
    /**
     * Initialize the document editor
     * @param {Object} options - Configuration options
     */
    constructor(options = {}) {
        // Auto-detect base path from current location (e.g., /CollaboraNexio/)
        const detectBasePath = () => {
            const pathParts = window.location.pathname.split('/').filter(p => p);
            // If we're in a subdirectory, use it. Otherwise, use root.
            return pathParts.length > 0 ? `/${pathParts[0]}` : '';
        };

        this.options = {
            // Use explicit base to avoid path detection issues
            apiBaseUrl: options.apiBaseUrl || '/CollaboraNexio/api/documents',
            onlyOfficeApiUrl: options.onlyOfficeApiUrl || 'http://localhost:8083/web-apps/apps/api/documents/api.js',
            autoSaveInterval: options.autoSaveInterval || 30000, // 30 seconds
            csrfToken: options.csrfToken || document.getElementById('csrfToken')?.value || '',
            userRole: options.userRole || document.getElementById('userRole')?.value || 'user',
            ...options
        };

        // State management
        this.state = {
            isEditorOpen: false,
            currentFileId: null,
            currentSessionToken: null,
            editorInstance: null,
            editorConfig: null,
            isLoading: false,
            isSaving: false,
            lastSavedAt: null,
            hasUnsavedChanges: false,
            activeCollaborators: []
        };

        // Bind methods
        this.openDocument = this.openDocument.bind(this);
        this.closeEditor = this.closeEditor.bind(this);
        this.handleDocumentReady = this.handleDocumentReady.bind(this);
        this.handleError = this.handleError.bind(this);
        this.handleWarning = this.handleWarning.bind(this);
        this.handleRequestClose = this.handleRequestClose.bind(this);
        this.handleBeforeUnload = this.handleBeforeUnload.bind(this);

        // Initialize
        this.init();
    }

    /**
     * Initialize the editor module
     */
    init() {
        console.log('[DocumentEditor] Initializing document editor module');

        // Load OnlyOffice API script if not already loaded
        if (!window.DocsAPI) {
            this.loadOnlyOfficeAPI();
        }

        // Listen for beforeunload to warn about unsaved changes
        window.addEventListener('beforeunload', this.handleBeforeUnload);

        // Listen for keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // ESC to close editor
            if (e.key === 'Escape' && this.state.isEditorOpen) {
                this.closeEditor();
            }
        });

        console.log('[DocumentEditor] Initialization complete');
    }

    /**
     * Load OnlyOffice Document Editor API
     */
    loadOnlyOfficeAPI() {
        return new Promise((resolve, reject) => {
            console.log('[DocumentEditor] Loading OnlyOffice API script');

            const script = document.createElement('script');
            script.src = this.options.onlyOfficeApiUrl;
            script.type = 'text/javascript';
            script.async = true;

            script.onload = () => {
                console.log('[DocumentEditor] OnlyOffice API loaded successfully');
                resolve();
            };

            script.onerror = () => {
                const error = 'Impossibile caricare l\'API di OnlyOffice. Verifica che il server sia attivo.';
                console.error('[DocumentEditor] ' + error);
                this.showToast(error, 'error');
                reject(new Error(error));
            };

            document.head.appendChild(script);
        });
    }

    /**
     * Open a document in the editor
     * @param {number} fileId - ID of the file to open
     * @param {string} mode - Editor mode ('edit' or 'view')
     */
    async openDocument(fileId, mode = 'edit') {
        console.log(`[DocumentEditor] Opening document ${fileId} in ${mode} mode`);

        if (this.state.isEditorOpen) {
            this.showToast('Un documento è già aperto. Chiudilo prima di aprirne un altro.', 'warning');
            return;
        }

        try {
            this.state.isLoading = true;
            this.showLoadingOverlay();

            // Ensure OnlyOffice API is loaded
            if (!window.DocsAPI) {
                await this.loadOnlyOfficeAPI();
            }

            // Fetch editor configuration from API
            // Add cache busting to prevent browser from using cached 404 responses
            const cacheBuster = `_t=${Date.now()}_${Math.random().toString(36).substring(7)}`;
            const response = await fetch(
                `${this.options.apiBaseUrl}/open_document.php?file_id=${fileId}&mode=${mode}&${cacheBuster}`,
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': this.options.csrfToken,
                        'Cache-Control': 'no-cache, no-store, must-revalidate',
                        'Pragma': 'no-cache',
                        'Expires': '0'
                    }
                }
            );

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || 'Errore durante l\'apertura del documento');
            }

            // Store configuration
            this.state.currentFileId = fileId;
            this.state.currentSessionToken = result.data.session_token;
            this.state.editorConfig = result.data.config;
            this.state.activeCollaborators = result.data.collaborators || [];

            // Create modal and initialize editor
            this.createEditorModal(result.data);
            this.initializeEditor(result.data);

            this.state.isEditorOpen = true;
            this.state.isLoading = false;
            this.hideLoadingOverlay();

            console.log('[DocumentEditor] Document opened successfully');

        } catch (error) {
            console.error('[DocumentEditor] Error opening document:', error);
            // Try to surface OnlyOffice error details when available
            if (error && error.data && error.data.errorCode) {
                const code = error.data.errorCode;
                const desc = error.data.errorDescription || 'Errore non specificato';
                this.showToast(`OnlyOffice errore ${code}: ${desc}`, 'error');
            } else if (error && typeof error === 'object') {
                try {
                    this.showToast(error.message || JSON.stringify(error), 'error');
                } catch (e) {
                    this.showToast('Errore durante l\'apertura del documento', 'error');
                }
            } else {
                this.showToast('Errore durante l\'apertura del documento', 'error');
            }
            this.state.isLoading = false;
            this.hideLoadingOverlay();
        }
    }

    /**
     * Create the full-screen modal container for the editor
     * @param {Object} data - Editor data from API
     */
    createEditorModal(data) {
        console.log('[DocumentEditor] Creating editor modal');

        // Remove existing modal if any
        const existingModal = document.getElementById('document-editor-modal');
        if (existingModal) {
            existingModal.remove();
        }

        // Create modal structure
        const modal = document.createElement('div');
        modal.id = 'document-editor-modal';
        modal.className = 'document-editor-modal';
        modal.innerHTML = `
            <div class="document-editor-header">
                <div class="editor-header-left">
                    <h3 class="editor-document-title">${this.escapeHtml(data.file_info.name)}</h3>
                    <div class="editor-save-status" id="editorSaveStatus">
                        <svg class="save-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/>
                            <polyline points="7 3 7 8 15 8"/>
                        </svg>
                        <span class="save-status-text">Salvato</span>
                    </div>
                </div>
                <div class="editor-header-right">
                    ${this.renderCollaborators(data.collaborators || [])}
                    <button class="editor-close-btn" id="editorCloseBtn" title="Chiudi editor (ESC)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                        <span>Chiudi</span>
                    </button>
                </div>
            </div>
            <div class="document-editor-container" id="onlyoffice-editor"></div>
        `;

        document.body.appendChild(modal);

        // Add event listeners
        document.getElementById('editorCloseBtn').addEventListener('click', () => {
            this.closeEditor();
        });

        // Prevent modal close on container click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                // Optionally close on backdrop click (disabled for safety)
                // this.closeEditor();
            }
        });
    }

    /**
     * Render active collaborators
     * @param {Array} collaborators - List of active users editing the document
     */
    renderCollaborators(collaborators) {
        if (!collaborators || collaborators.length === 0) {
            return '';
        }

        const avatars = collaborators.slice(0, 3).map(user => {
            const initials = this.getInitials(user.name || user.user_name);
            return `
                <div class="collaborator-avatar" title="${this.escapeHtml(user.name || user.user_name)}">
                    ${initials}
                </div>
            `;
        }).join('');

        const moreCount = collaborators.length > 3 ? `+${collaborators.length - 3}` : '';

        return `
            <div class="active-collaborators" id="activeCollaborators">
                ${avatars}
                ${moreCount ? `<div class="collaborator-more">${moreCount}</div>` : ''}
            </div>
        `;
    }

    /**
     * Initialize OnlyOffice editor instance
     * @param {Object} data - Editor configuration data
     */
    initializeEditor(data) {
        console.log('[DocumentEditor] Initializing OnlyOffice editor');

        // Check if DocsAPI is available
        if (!window.DocsAPI) {
            const error = 'OnlyOffice Document Server non disponibile. Verifica che il server sia in esecuzione.';
            console.error('[DocumentEditor] ' + error);
            this.showErrorWithFallback(error, '-1');
            throw new Error(error);
        }

        const config = {
            ...data.config,
            events: {
                onAppReady: () => {
                    console.log('[DocumentEditor] Editor app ready');
                },
                onDocumentReady: this.handleDocumentReady,
                onDocumentStateChange: (event) => {
                    console.log('[DocumentEditor] Document state changed:', event);
                    this.state.hasUnsavedChanges = event.data;
                    this.updateSaveStatus(event.data ? 'unsaved' : 'saved');
                },
                onError: this.handleError,
                onWarning: this.handleWarning,
                onInfo: (event) => {
                    console.log('[DocumentEditor] Info:', event);
                },
                onRequestClose: this.handleRequestClose,
                onRequestSaveAs: (event) => {
                    console.log('[DocumentEditor] Save as requested:', event);
                },
                onDownloadAs: (event) => {
                    console.log('[DocumentEditor] Download requested:', event);
                },
                onRequestHistory: () => {
                    console.log('[DocumentEditor] History requested');
                },
                onRequestHistoryData: (event) => {
                    console.log('[DocumentEditor] History data requested:', event);
                },
                onRequestHistoryClose: () => {
                    console.log('[DocumentEditor] History close requested');
                }
            },
            token: data.token
        };

        // Initialize editor with error recovery
        try {
            this.state.editorInstance = new DocsAPI.DocEditor("onlyoffice-editor", config);
            console.log('[DocumentEditor] Editor instance created successfully');

            // Set a timeout to detect if editor fails to load
            setTimeout(() => {
                if (this.state.isEditorOpen && !this.state.editorInstance) {
                    console.error('[DocumentEditor] Editor failed to initialize within timeout');
                    this.showErrorWithFallback(
                        'L\'editor non risponde. Il server OnlyOffice potrebbe essere non disponibile.',
                        '-1'
                    );
                }
            }, 30000); // 30 second timeout

        } catch (error) {
            console.error('[DocumentEditor] Error creating editor instance:', error);
            this.showErrorWithFallback('Impossibile inizializzare l\'editor: ' + error.message, '-1');
            throw new Error('Impossibile inizializzare l\'editor: ' + error.message);
        }
    }

    /**
     * Handle document ready event
     */
    handleDocumentReady() {
        console.log('[DocumentEditor] Document is ready for editing');
        this.updateSaveStatus('saved');
        this.showToast('Documento caricato con successo', 'success');
    }

    /**
     * Handle editor errors
     * @param {Object} event - Error event
     */
    handleError(event) {
        console.error('[DocumentEditor] Editor error:', event);

        // Enhanced logging to capture exact error details
        console.error('[DocumentEditor] Error event.data:', event.data);
        console.error('[DocumentEditor] Error event type:', typeof event.data);

        // Log the entire event object for debugging
        if (event.data && typeof event.data === 'object') {
            console.error('[DocumentEditor] Error details:', JSON.stringify(event.data, null, 2));
            if (event.data.errorCode !== undefined) {
                console.error('[DocumentEditor] Error code from object:', event.data.errorCode);
            }
        }

        const errorMessages = {
            '-1': 'Errore sconosciuto durante il caricamento dell\'editor',
            '-2': 'Timeout durante la conversione del documento',
            '-3': 'Errore di conversione del documento',
            '-4': 'Errore durante il download del documento per la modifica',
            '-5': 'Formato file non supportato',
            '-6': 'Errore nel caricamento del file',
            '-8': 'Formato file non corretto o documento corrotto'
        };

        // Try to extract error code from different possible locations
        let errorCode = event.data;
        if (event.data && typeof event.data === 'object') {
            errorCode = event.data.errorCode || event.data.error || event.data.code || event.data;
        }

        const errorMessage = errorMessages[String(errorCode)] || `Errore nell'editor (codice: ${JSON.stringify(errorCode)})`;

        // Show error with graceful degradation option
        this.showErrorWithFallback(errorMessage, String(errorCode));

        // Close editor on critical errors
        if (['-4', '-5', '-6', '-8'].includes(String(errorCode))) {
            setTimeout(() => {
                this.closeEditor(true);
            }, 3000);
        }
    }

    /**
     * Show error message with download fallback option
     * @param {string} message - Error message
     * @param {string} errorCode - Error code
     */
    showErrorWithFallback(message, errorCode) {
        const isCriticalError = ['-4', '-5', '-6', '-8'].includes(String(errorCode));

        if (isCriticalError && this.state.currentFileId) {
            // Offer download as fallback
            const fallbackMessage = `${message}\n\nVuoi scaricare il file invece?`;

            if (confirm(fallbackMessage)) {
                this.downloadFile(this.state.currentFileId);
            }
        } else {
            this.showToast(message, 'error');
        }
    }

    /**
     * Download file as fallback when editor fails
     * @param {number} fileId - File ID to download
     */
    downloadFile(fileId) {
        const downloadUrl = `${this.options.apiBaseUrl.replace('/documents', '/files')}/download.php?id=${fileId}`;
        window.location.href = downloadUrl;
    }

    /**
     * Handle editor warnings
     * @param {Object} event - Warning event
     */
    handleWarning(event) {
        console.warn('[DocumentEditor] Editor warning:', event);
        // Don't show warnings to user unless critical
    }

    /**
     * Handle close request from editor
     */
    handleRequestClose() {
        console.log('[DocumentEditor] Close requested by editor');
        this.closeEditor();
    }

    /**
     * Close the editor and cleanup
     * @param {boolean} force - Force close without confirmation
     */
    async closeEditor(force = false) {
        console.log('[DocumentEditor] Closing editor');

        // Check for unsaved changes
        if (!force && this.state.hasUnsavedChanges) {
            const confirmed = confirm(
                'Ci sono modifiche non salvate. Sei sicuro di voler chiudere l\'editor?\n\n' +
                'Le modifiche non salvate andranno perse.'
            );
            if (!confirmed) {
                return;
            }
        }

        try {
            // Destroy editor instance
            if (this.state.editorInstance) {
                try {
                    this.state.editorInstance.destroyEditor();
                } catch (error) {
                    console.warn('[DocumentEditor] Error destroying editor:', error);
                }
            }

            // Close session on server
            if (this.state.currentSessionToken) {
                await this.closeSession(this.state.currentSessionToken, !this.state.hasUnsavedChanges);
            }

            // Remove modal from DOM
            const modal = document.getElementById('document-editor-modal');
            if (modal) {
                modal.classList.add('closing');
                setTimeout(() => {
                    modal.remove();
                }, 300);
            }

            // Reset state
            this.state = {
                isEditorOpen: false,
                currentFileId: null,
                currentSessionToken: null,
                editorInstance: null,
                editorConfig: null,
                isLoading: false,
                isSaving: false,
                lastSavedAt: null,
                hasUnsavedChanges: false,
                activeCollaborators: []
            };

            console.log('[DocumentEditor] Editor closed successfully');

            // Reload file list if fileManager is available
            if (window.fileManager && typeof window.fileManager.loadFiles === 'function') {
                window.fileManager.loadFiles();
            }

        } catch (error) {
            console.error('[DocumentEditor] Error closing editor:', error);
        }
    }

    /**
     * Close editing session on server
     * @param {string} sessionToken - Session token
     * @param {boolean} changesSaved - Whether changes were saved
     */
    async closeSession(sessionToken, changesSaved = false) {
        try {
            const response = await fetch(`${this.options.apiBaseUrl}/close_session.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.options.csrfToken
                },
                body: JSON.stringify({
                    session_token: sessionToken,
                    file_id: this.state.currentFileId,
                    changes_saved: changesSaved,
                    force_close: false
                })
            });

            const result = await response.json();

            if (result.success) {
                console.log('[DocumentEditor] Session closed successfully');
            } else {
                console.warn('[DocumentEditor] Failed to close session:', result.error);
            }

        } catch (error) {
            console.error('[DocumentEditor] Error closing session:', error);
        }
    }

    /**
     * Update save status indicator
     * @param {string} status - Status: 'saved', 'saving', 'unsaved', 'error'
     */
    updateSaveStatus(status) {
        const statusElement = document.getElementById('editorSaveStatus');
        if (!statusElement) return;

        const statusTexts = {
            'saved': 'Salvato',
            'saving': 'Salvataggio...',
            'unsaved': 'Modifiche non salvate',
            'error': 'Errore salvataggio'
        };

        const statusClasses = {
            'saved': 'status-saved',
            'saving': 'status-saving',
            'unsaved': 'status-unsaved',
            'error': 'status-error'
        };

        // Remove all status classes
        Object.values(statusClasses).forEach(cls => {
            statusElement.classList.remove(cls);
        });

        // Add current status class
        statusElement.classList.add(statusClasses[status] || statusClasses.saved);

        // Update text
        const textElement = statusElement.querySelector('.save-status-text');
        if (textElement) {
            textElement.textContent = statusTexts[status] || statusTexts.saved;
        }

        // Update timestamp for saved status
        if (status === 'saved') {
            this.state.lastSavedAt = new Date();
        }
    }

    /**
     * Show loading overlay
     */
    showLoadingOverlay() {
        let overlay = document.getElementById('editor-loading-overlay');

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'editor-loading-overlay';
            overlay.className = 'editor-loading-overlay';
            overlay.innerHTML = `
                <div class="editor-loading-content">
                    <div class="editor-spinner"></div>
                    <p class="editor-loading-text">Caricamento editor...</p>
                </div>
            `;
            document.body.appendChild(overlay);
        }

        overlay.style.display = 'flex';
    }

    /**
     * Hide loading overlay
     */
    hideLoadingOverlay() {
        const overlay = document.getElementById('editor-loading-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }

    /**
     * Show toast notification
     * @param {string} message - Message to display
     * @param {string} type - Type: 'success', 'error', 'warning', 'info'
     */
    showToast(message, type = 'info') {
        // Check if fileManager toast exists
        if (window.fileManager && typeof window.fileManager.showToast === 'function') {
            window.fileManager.showToast(message, type);
            return;
        }

        // Fallback: create simple toast
        const toast = document.createElement('div');
        toast.className = `editor-toast editor-toast-${type}`;
        toast.innerHTML = `
            <div class="editor-toast-content">
                <span class="editor-toast-message">${this.escapeHtml(message)}</span>
                <button class="editor-toast-close">&times;</button>
            </div>
        `;

        document.body.appendChild(toast);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            toast.classList.add('hiding');
            setTimeout(() => toast.remove(), 300);
        }, 5000);

        // Close button
        toast.querySelector('.editor-toast-close').addEventListener('click', () => {
            toast.classList.add('hiding');
            setTimeout(() => toast.remove(), 300);
        });
    }

    /**
     * Handle beforeunload event to warn about unsaved changes
     * @param {Event} event - Beforeunload event
     */
    handleBeforeUnload(event) {
        if (this.state.isEditorOpen && this.state.hasUnsavedChanges) {
            const message = 'Ci sono modifiche non salvate. Sei sicuro di voler uscire?';
            event.preventDefault();
            event.returnValue = message;
            return message;
        }
    }

    /**
     * Get initials from name
     * @param {string} name - Full name
     * @returns {string} Initials (max 2 characters)
     */
    getInitials(name) {
        if (!name) return '??';
        const parts = name.trim().split(' ');
        if (parts.length === 1) {
            return parts[0].substring(0, 2).toUpperCase();
        }
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text - Text to escape
     * @returns {string} Escaped text
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Check if a file is editable based on extension
     * @param {string} filename - Filename
     * @returns {boolean} True if editable
     */
    isFileEditable(filename) {
        const editableExtensions = [
            'docx', 'doc', 'docm', 'dot', 'dotx', 'dotm',
            'xlsx', 'xls', 'xlsm', 'xlt', 'xltx', 'xltm',
            'pptx', 'ppt', 'pptm', 'pot', 'potx', 'potm',
            'odt', 'ods', 'odp', 'fodt', 'fods', 'fodp',
            'rtf', 'txt', 'csv'
        ];

        const extension = filename.split('.').pop().toLowerCase();
        return editableExtensions.includes(extension);
    }

    /**
     * Get document type from filename
     * @param {string} filename - Filename
     * @returns {string} Document type: 'word', 'cell', or 'slide'
     */
    getDocumentType(filename) {
        const extension = filename.split('.').pop().toLowerCase();

        const wordExtensions = ['doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'odt', 'fodt', 'rtf', 'txt'];
        const cellExtensions = ['xls', 'xlsx', 'xlsm', 'xlt', 'xltx', 'xltm', 'ods', 'fods', 'csv'];
        const slideExtensions = ['ppt', 'pptx', 'pptm', 'pot', 'potx', 'potm', 'odp', 'fodp'];

        if (wordExtensions.includes(extension)) return 'word';
        if (cellExtensions.includes(extension)) return 'cell';
        if (slideExtensions.includes(extension)) return 'slide';

        return 'word'; // Default
    }
}

/**
 * Initialize global document editor instance
 */
(function() {
    'use strict';

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDocumentEditor);
    } else {
        initDocumentEditor();
    }

    function initDocumentEditor() {
        console.log('[DocumentEditor] Initializing global document editor');

        // Create global instance
        window.documentEditor = new DocumentEditor({
            csrfToken: document.getElementById('csrfToken')?.value,
            userRole: document.getElementById('userRole')?.value
        });

        // Add edit buttons to existing file cards
        addEditButtonsToFileCards();

        console.log('[DocumentEditor] Global initialization complete');
    }

    /**
     * Add edit buttons to file cards in the file manager
     */
    function addEditButtonsToFileCards() {
        // This will be called by fileManager.js after files are loaded
        // We expose a method for integration
        window.addDocumentEditorButtons = function(fileElement, fileData) {
            if (!fileData || fileData.type !== 'file') return;

            // Check if file is editable
            if (!window.documentEditor.isFileEditable(fileData.name)) {
                return;
            }

            // Check user permissions
            const userRole = document.getElementById('userRole')?.value || 'user';
            const canEdit = ['manager', 'admin', 'super_admin'].includes(userRole) ||
                           (userRole === 'user' && fileData.uploaded_by === fileData.current_user_id);

            if (!canEdit) {
                return; // User doesn't have edit permission
            }

            // Find actions container
            const actionsContainer = fileElement.querySelector('.file-card-actions') ||
                                    fileElement.querySelector('.file-actions') ||
                                    fileElement.querySelector('.actions');

            if (!actionsContainer) return;

            // Check if edit button already exists
            if (actionsContainer.querySelector('.edit-document-btn')) {
                return;
            }

            // Create edit button
            const editButton = document.createElement('button');
            editButton.className = 'btn btn-sm btn-primary edit-document-btn';
            editButton.title = 'Modifica documento';
            editButton.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                </svg>
                <span>Modifica</span>
            `;

            editButton.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                window.documentEditor.openDocument(fileData.id, 'edit');
            });

            // Insert edit button (prepend to show first)
            actionsContainer.insertBefore(editButton, actionsContainer.firstChild);
        };
    }
})();
