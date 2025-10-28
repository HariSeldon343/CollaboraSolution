(function() {
    'use strict';

    /**
     * Enhanced File Manager Module with Full API Integration
     * Includes upload, document creation, and multi-tenant support
     */
    class EnhancedFileManager {
        constructor() {
            this.config = {
                uploadApi: '/CollaboraNexio/api/files_tenant.php?action=upload',
                createDocumentApi: '/CollaboraNexio/api/files/create_document.php',
                filesApi: '/CollaboraNexio/api/files_tenant.php',
                maxFileSize: 100 * 1024 * 1024, // 100MB
                chunkSize: 5 * 1024 * 1024, // 5MB chunks for large files
                allowedExtensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'mp4', 'mp3', 'zip', 'rar'],
                pollInterval: 5000
            };

            this.state = {
                currentPath: '/',
                currentFolderId: null,
                currentView: 'grid',
                selectedFiles: new Set(),
                uploadQueue: [],
                activeUploads: new Map(),
                sortBy: 'name',
                filterType: 'all',
                searchQuery: '',
                isRoot: true,
                currentTenant: null
            };

            this.userRole = document.getElementById('userRole')?.value || 'user';
            this.currentTenantId = document.getElementById('currentTenantId')?.value || null;
            this.csrfToken = document.getElementById('csrfToken')?.value || '';

            this.init();
        }

        init() {
            this.bindEvents();
            this.loadInitialData();
            this.setupDragAndDrop();
            this.initContextMenu();
            this.initKeyboardShortcuts();
            this.createDocumentModal();
            this.setupUploadUI();
        }

        bindEvents() {
            // Enhanced upload button - now functional!
            document.getElementById('uploadBtn')?.addEventListener('click', () => {
                this.showUploadDialog();
            });

            // New folder button
            document.getElementById('newFolderBtn')?.addEventListener('click', () => {
                this.createNewFolder();
            });

            // Create Tenant Folder button (for Admin/Super Admin)
            const createRootFolderBtn = document.getElementById('createRootFolderBtn');
            if (createRootFolderBtn) {
                console.log('✓ Create Root Folder button found, binding event...');
                createRootFolderBtn.addEventListener('click', () => {
                    console.log('🔘 Create Root Folder button clicked!');
                    this.showCreateTenantFolderModal();
                });
            } else {
                console.warn('⚠ Create Root Folder button NOT found in DOM');
            }

            // New document button
            const createDocBtn = document.getElementById('createDocumentBtn');
            if (!createDocBtn) {
                // Add create document button if it doesn't exist
                const uploadBtn = document.getElementById('uploadBtn');
                if (uploadBtn) {
                    const createBtn = document.createElement('button');
                    createBtn.id = 'createDocumentBtn';
                    createBtn.className = 'btn btn-secondary';
                    createBtn.innerHTML = `
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="12" y1="18" x2="12" y2="12"/>
                            <line x1="9" y1="15" x2="15" y2="15"/>
                        </svg>
                        <span>Nuovo Documento</span>
                    `;
                    createBtn.addEventListener('click', () => this.showDocumentModal());
                    uploadBtn.parentNode.insertBefore(createBtn, uploadBtn.nextSibling);
                }
            } else {
                createDocBtn.addEventListener('click', () => this.showDocumentModal());
            }

            // Existing event bindings
            this.bindSearchEvents();
            this.bindFilterSortEvents();
            this.bindViewToggle();
            this.bindFileSelection();
            this.bindBreadcrumb();
            this.bindSidebar();
        }

        bindSearchEvents() {
            const searchInput = document.getElementById('fileSearch');
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', (e) => {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        this.handleSearch(e.target.value);
                    }, 300);
                });
            }
        }

        bindFilterSortEvents() {
            document.getElementById('filterBtn')?.addEventListener('click', () => {
                this.showFilterMenu();
            });

            document.getElementById('sortBtn')?.addEventListener('click', () => {
                this.showSortMenu();
            });
        }

        bindViewToggle() {
            document.querySelectorAll('.view-btn').forEach(btn => {
                btn.addEventListener('click', (e) => this.handleViewChange(e));
            });
        }

        bindFileSelection() {
            // Grid view
            document.getElementById('filesGrid')?.addEventListener('click', (e) => {
                const fileCard = e.target.closest('.file-card');
                const actionBtn = e.target.closest('.action-btn');

                if (actionBtn) {
                    e.stopPropagation();
                    this.handleFileAction(actionBtn, fileCard);
                } else if (fileCard) {
                    e.preventDefault();
                    this.handleFileClick(fileCard, e);
                }
            });

            // List view
            document.getElementById('filesList')?.addEventListener('click', (e) => {
                const fileRow = e.target.closest('.file-row');
                const actionBtn = e.target.closest('.action-btn');
                const checkbox = e.target.closest('.file-checkbox');

                if (actionBtn) {
                    e.stopPropagation();
                    this.handleFileAction(actionBtn, fileRow);
                } else if (checkbox) {
                    this.handleCheckboxChange(fileRow, checkbox.checked);
                } else if (fileRow) {
                    this.handleFileClick(fileRow, e);
                }
            });

            // Double click to open
            document.addEventListener('dblclick', (e) => {
                const fileElement = e.target.closest('.file-card, .file-row');
                if (fileElement) {
                    this.openFile(fileElement);
                }
            });

            // Select all
            document.getElementById('selectAll')?.addEventListener('change', (e) => {
                this.handleSelectAll(e.target.checked);
            });
        }

        bindBreadcrumb() {
            document.querySelector('.breadcrumb-items')?.addEventListener('click', (e) => {
                const item = e.target.closest('.breadcrumb-item');
                if (item && item.dataset.path) {
                    e.preventDefault();
                    this.navigateToPath(item.dataset.path);
                }
            });
        }

        bindSidebar() {
            document.getElementById('closeDetails')?.addEventListener('click', () => {
                this.hideDetailsSidebar();
            });

            document.getElementById('sidebarToggle')?.addEventListener('click', () => {
                document.querySelector('.sidebar')?.classList.toggle('collapsed');
            });
        }

        setupDragAndDrop() {
            const dropZone = document.getElementById('dropZone');
            const filesWrapper = document.getElementById('filesWrapper');

            if (!filesWrapper) return;

            let dragCounter = 0;

            filesWrapper.addEventListener('dragenter', (e) => {
                e.preventDefault();
                dragCounter++;
                if (dragCounter === 1 && dropZone) {
                    dropZone.classList.add('active');
                }
            });

            filesWrapper.addEventListener('dragleave', (e) => {
                e.preventDefault();
                dragCounter--;
                if (dragCounter === 0 && dropZone) {
                    dropZone.classList.remove('active');
                }
            });

            filesWrapper.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
            });

            filesWrapper.addEventListener('drop', (e) => {
                e.preventDefault();
                dragCounter = 0;
                if (dropZone) {
                    dropZone.classList.remove('active');
                }
                this.handleFileDrop(e.dataTransfer.files);
            });

            // Click drop zone to upload
            dropZone?.addEventListener('click', () => {
                this.showUploadDialog();
            });
        }

        setupUploadUI() {
            // Create advanced upload toast if it doesn't exist
            if (!document.getElementById('advancedUploadToast')) {
                const uploadToast = document.createElement('div');
                uploadToast.id = 'advancedUploadToast';
                uploadToast.className = 'advanced-upload-toast hidden';
                uploadToast.innerHTML = `
                    <div class="upload-toast-header">
                        <div class="upload-toast-title">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                <polyline points="17 8 12 3 7 8"/>
                                <line x1="12" y1="3" x2="12" y2="15"/>
                            </svg>
                            <span>Caricamento in corso</span>
                        </div>
                        <button class="upload-toast-minimize" id="minimizeUpload">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </button>
                        <button class="upload-toast-close" id="closeUploadToast">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <div class="upload-toast-body" id="uploadToastBody">
                        <!-- Upload items will be added here -->
                    </div>
                    <div class="upload-toast-footer">
                        <div class="upload-summary">
                            <span id="uploadSummaryText">0 di 0 completati</span>
                        </div>
                        <button class="btn btn-sm btn-ghost" id="cancelAllUploads">Annulla tutto</button>
                    </div>
                `;
                document.body.appendChild(uploadToast);

                // Bind upload toast events
                document.getElementById('closeUploadToast')?.addEventListener('click', () => {
                    this.hideUploadToast();
                });

                document.getElementById('minimizeUpload')?.addEventListener('click', () => {
                    uploadToast.classList.toggle('minimized');
                });

                document.getElementById('cancelAllUploads')?.addEventListener('click', () => {
                    this.cancelAllUploads();
                });
            }
        }

        createDocumentModal() {
            // Check if modal already exists
            if (document.getElementById('createDocumentModal')) return;

            const modal = document.createElement('div');
            modal.id = 'createDocumentModal';
            modal.className = 'modal-overlay';
            modal.style.display = 'none';
            modal.innerHTML = `
                <div class="modal-content document-modal">
                    <div class="modal-header">
                        <h2>Crea Nuovo Documento</h2>
                        <button class="modal-close" id="closeDocumentModal">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="document-types">
                            <div class="document-type-card" data-type="docx">
                                <div class="document-type-icon">
                                    <svg viewBox="0 0 48 48" fill="none">
                                        <rect x="8" y="4" width="32" height="40" rx="3" fill="#2563EB"/>
                                        <text x="24" y="28" text-anchor="middle" fill="white" font-size="12" font-weight="bold">W</text>
                                    </svg>
                                </div>
                                <div class="document-type-info">
                                    <h3>Documento Word</h3>
                                    <p>Crea un documento di testo</p>
                                </div>
                            </div>
                            <div class="document-type-card" data-type="xlsx">
                                <div class="document-type-icon">
                                    <svg viewBox="0 0 48 48" fill="none">
                                        <rect x="8" y="4" width="32" height="40" rx="3" fill="#10B981"/>
                                        <text x="24" y="28" text-anchor="middle" fill="white" font-size="12" font-weight="bold">X</text>
                                    </svg>
                                </div>
                                <div class="document-type-info">
                                    <h3>Foglio Excel</h3>
                                    <p>Crea un foglio di calcolo</p>
                                </div>
                            </div>
                            <div class="document-type-card" data-type="pptx">
                                <div class="document-type-icon">
                                    <svg viewBox="0 0 48 48" fill="none">
                                        <rect x="8" y="4" width="32" height="40" rx="3" fill="#F59E0B"/>
                                        <text x="24" y="28" text-anchor="middle" fill="white" font-size="12" font-weight="bold">P</text>
                                    </svg>
                                </div>
                                <div class="document-type-info">
                                    <h3>Presentazione PowerPoint</h3>
                                    <p>Crea una presentazione</p>
                                </div>
                            </div>
                            <div class="document-type-card" data-type="txt">
                                <div class="document-type-icon">
                                    <svg viewBox="0 0 48 48" fill="none">
                                        <rect x="8" y="4" width="32" height="40" rx="3" fill="#6B7280"/>
                                        <text x="24" y="28" text-anchor="middle" fill="white" font-size="10" font-weight="bold">TXT</text>
                                    </svg>
                                </div>
                                <div class="document-type-info">
                                    <h3>File di Testo</h3>
                                    <p>Crea un documento di testo semplice</p>
                                </div>
                            </div>
                        </div>
                        <div class="form-group mt-4">
                            <label for="documentName">Nome del documento</label>
                            <input type="text" id="documentName" class="form-control" placeholder="Es: Relazione Mensile">
                            <small class="form-text text-muted">L'estensione verrà aggiunta automaticamente</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" id="cancelDocumentCreate">Annulla</button>
                        <button class="btn btn-primary" id="confirmDocumentCreate" disabled>Crea Documento</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            // Bind modal events
            let selectedType = null;

            document.querySelectorAll('.document-type-card').forEach(card => {
                card.addEventListener('click', () => {
                    // Remove previous selection
                    document.querySelectorAll('.document-type-card').forEach(c => c.classList.remove('selected'));
                    // Add selection to clicked card
                    card.classList.add('selected');
                    selectedType = card.dataset.type;
                    this.validateDocumentForm();
                });
            });

            document.getElementById('documentName')?.addEventListener('input', () => {
                this.validateDocumentForm();
            });

            document.getElementById('closeDocumentModal')?.addEventListener('click', () => {
                this.hideDocumentModal();
            });

            document.getElementById('cancelDocumentCreate')?.addEventListener('click', () => {
                this.hideDocumentModal();
            });

            document.getElementById('confirmDocumentCreate')?.addEventListener('click', () => {
                const name = document.getElementById('documentName').value.trim();
                if (selectedType && name) {
                    this.createDocument(selectedType, name);
                }
            });

            // Close on outside click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.hideDocumentModal();
                }
            });
        }

        initContextMenu() {
            const contextMenu = document.getElementById('contextMenu');
            if (!contextMenu) return;

            // Hide context menu on click outside
            document.addEventListener('click', () => {
                contextMenu.classList.remove('active');
            });

            // Show context menu on right click
            document.addEventListener('contextmenu', (e) => {
                const fileElement = e.target.closest('.file-card, .file-row');
                if (fileElement) {
                    e.preventDefault();
                    this.showContextMenu(e.pageX, e.pageY, fileElement);
                }
            });

            // Context menu actions
            contextMenu.querySelectorAll('.context-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    const action = item.querySelector('span').textContent.toLowerCase();
                    this.handleContextAction(action);
                });
            });
        }

        initKeyboardShortcuts() {
            document.addEventListener('keydown', (e) => {
                // Ctrl/Cmd + U - Upload
                if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
                    e.preventDefault();
                    this.showUploadDialog();
                }

                // Ctrl/Cmd + N - New document
                if ((e.ctrlKey || e.metaKey) && e.key === 'n' && !e.shiftKey) {
                    e.preventDefault();
                    this.showDocumentModal();
                }

                // Ctrl/Cmd + Shift + N - New folder
                if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'N') {
                    e.preventDefault();
                    this.createNewFolder();
                }

                // Ctrl/Cmd + A - Select all
                if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                    e.preventDefault();
                    this.handleSelectAll(true);
                }

                // Delete - Delete selected
                if (e.key === 'Delete' && this.state.selectedFiles.size > 0) {
                    e.preventDefault();
                    this.deleteSelected();
                }

                // Escape - Clear selection
                if (e.key === 'Escape') {
                    this.clearSelection();
                    this.hideDetailsSidebar();
                    this.hideDocumentModal();
                }
            });
        }

        // ========================================
        // UPLOAD FUNCTIONALITY
        // ========================================

        showUploadDialog() {
            if (this.state.isRoot) {
                this.showToast('Seleziona una cartella prima di caricare file', 'error');
                return;
            }

            const input = document.createElement('input');
            input.type = 'file';
            input.multiple = true;
            input.accept = this.config.allowedExtensions.map(ext => `.${ext}`).join(',');
            input.onchange = (e) => {
                this.handleFileUpload(e.target.files);
            };
            input.click();
        }

        handleFileDrop(files) {
            if (this.state.isRoot) {
                this.showToast('Seleziona una cartella prima di caricare file', 'error');
                return;
            }
            this.handleFileUpload(files);
        }

        async handleFileUpload(files) {
            if (files.length === 0) return;

            // Validate files
            const validFiles = [];
            const errors = [];

            for (const file of files) {
                // Check file size
                if (file.size > this.config.maxFileSize) {
                    errors.push({
                        file: file.name,
                        error: `File troppo grande (max ${this.formatFileSize(this.config.maxFileSize)})`
                    });
                    continue;
                }

                // Check extension
                const extension = file.name.split('.').pop().toLowerCase();
                if (!this.config.allowedExtensions.includes(extension)) {
                    errors.push({
                        file: file.name,
                        error: 'Tipo di file non supportato'
                    });
                    continue;
                }

                validFiles.push(file);
            }

            // Show errors if any
            if (errors.length > 0) {
                errors.forEach(error => {
                    this.showToast(`${error.file}: ${error.error}`, 'error');
                });
            }

            if (validFiles.length === 0) return;

            // Show upload toast
            this.showUploadToast();

            // Process uploads
            for (const file of validFiles) {
                const uploadId = this.generateUploadId();
                this.addUploadToQueue(uploadId, file);

                // Determine if file needs chunking
                if (file.size > this.config.chunkSize) {
                    await this.uploadFileChunked(uploadId, file);
                } else {
                    await this.uploadFile(uploadId, file);
                }
            }
        }

        async uploadFile(uploadId, file) {
            try {
                const formData = new FormData();
                formData.append('file', file);
                formData.append('folder_id', this.state.currentFolderId || '');
                formData.append('csrf_token', this.csrfToken);

                const xhr = new XMLHttpRequest();
                this.state.activeUploads.set(uploadId, xhr);

                // Progress tracking
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percentComplete = (e.loaded / e.total) * 100;
                        this.updateUploadProgress(uploadId, percentComplete);
                    }
                });

                // Load event
                xhr.addEventListener('load', () => {
                    if (xhr.status === 200) {
                        try {
                            const result = JSON.parse(xhr.responseText);
                            if (result.success) {
                                this.completeUpload(uploadId, file.name);
                                this.loadFiles(); // Refresh file list
                            } else {
                                this.failUpload(uploadId, result.error || 'Errore durante il caricamento');
                            }
                        } catch (e) {
                            this.failUpload(uploadId, 'Errore nella risposta del server');
                        }
                    } else {
                        this.failUpload(uploadId, `Errore HTTP: ${xhr.status}`);
                    }
                    this.state.activeUploads.delete(uploadId);
                });

                // Error event
                xhr.addEventListener('error', () => {
                    this.failUpload(uploadId, 'Errore di rete');
                    this.state.activeUploads.delete(uploadId);
                });

                // Abort event
                xhr.addEventListener('abort', () => {
                    this.cancelUpload(uploadId);
                    this.state.activeUploads.delete(uploadId);
                });

                // Send request with cache busting (use '&' if URL already has query params)
                const sep = this.config.uploadApi.includes('?') ? '&' : '?';
                const cacheBustUrl = this.config.uploadApi + sep + '_t=' + (Date.now() + Math.floor(Math.random() * 1000000));
                xhr.open('POST', cacheBustUrl);
                // Force no-cache headers to bypass browser cache
                xhr.setRequestHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
                xhr.setRequestHeader('Pragma', 'no-cache');
                xhr.setRequestHeader('Expires', '0');
                xhr.send(formData);

            } catch (error) {
                console.error('Upload error:', error);
                this.failUpload(uploadId, 'Errore durante il caricamento');
            }
        }

        async uploadFileChunked(uploadId, file) {
            const totalChunks = Math.ceil(file.size / this.config.chunkSize);
            let fileId = null;

            for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                const start = chunkIndex * this.config.chunkSize;
                const end = Math.min(start + this.config.chunkSize, file.size);
                const chunk = file.slice(start, end);

                try {
                    const formData = new FormData();
                    formData.append('file', chunk, file.name);
                    formData.append('folder_id', this.state.currentFolderId || '');
                    formData.append('csrf_token', this.csrfToken);
                    formData.append('is_chunked', 'true');
                    formData.append('chunk_index', chunkIndex.toString());
                    formData.append('total_chunks', totalChunks.toString());

                    if (fileId) {
                        formData.append('file_id', fileId);
                    }

                    const xhr = new XMLHttpRequest();
                    this.state.activeUploads.set(uploadId, xhr);

                    // Create promise for chunk upload
                    const chunkPromise = new Promise((resolve, reject) => {
                        xhr.addEventListener('load', () => {
                            if (xhr.status === 200) {
                                try {
                                    const result = JSON.parse(xhr.responseText);
                                    if (result.success) {
                                        if (result.data && result.data.file_id) {
                                            fileId = result.data.file_id;
                                        }
                                        resolve(result);
                                    } else {
                                        reject(new Error(result.error || 'Chunk upload failed'));
                                    }
                                } catch (e) {
                                    reject(new Error('Invalid server response'));
                                }
                            } else {
                                reject(new Error(`HTTP Error: ${xhr.status}`));
                            }
                        });

                        xhr.addEventListener('error', () => {
                            reject(new Error('Network error'));
                        });

                        xhr.addEventListener('abort', () => {
                            reject(new Error('Upload cancelled'));
                        });
                    });

                    // Update progress
                    const overallProgress = ((chunkIndex + 1) / totalChunks) * 100;
                    this.updateUploadProgress(uploadId, overallProgress);

                    // Send chunk with cache busting (use '&' if URL already has query params)
                    const sep = this.config.uploadApi.includes('?') ? '&' : '?';
                    const cacheBustUrl = this.config.uploadApi + sep + '_t=' + (Date.now() + Math.floor(Math.random() * 1000000));
                    xhr.open('POST', cacheBustUrl);
                    // Force no-cache headers to bypass browser cache
                    xhr.setRequestHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
                    xhr.setRequestHeader('Pragma', 'no-cache');
                    xhr.setRequestHeader('Expires', '0');
                    xhr.send(formData);

                    // Wait for chunk to complete
                    await chunkPromise;

                    this.state.activeUploads.delete(uploadId);

                } catch (error) {
                    console.error('Chunk upload error:', error);
                    this.failUpload(uploadId, error.message);
                    this.state.activeUploads.delete(uploadId);
                    return;
                }
            }

            // All chunks uploaded successfully
            this.completeUpload(uploadId, file.name);
            this.loadFiles(); // Refresh file list
        }

        addUploadToQueue(uploadId, file) {
            const uploadBody = document.getElementById('uploadToastBody');
            if (!uploadBody) return;

            const uploadItem = document.createElement('div');
            uploadItem.id = `upload-${uploadId}`;
            uploadItem.className = 'upload-item';
            uploadItem.innerHTML = `
                <div class="upload-item-header">
                    <div class="upload-file-info">
                        <span class="upload-file-icon">${this.getFileIcon(this.getFileType(file.name))}</span>
                        <div class="upload-file-details">
                            <span class="upload-file-name">${file.name}</span>
                            <span class="upload-file-size">${this.formatFileSize(file.size)}</span>
                        </div>
                    </div>
                    <button class="upload-cancel-btn" data-upload-id="${uploadId}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="upload-progress">
                    <div class="upload-progress-bar">
                        <div class="upload-progress-fill" style="width: 0%"></div>
                    </div>
                    <span class="upload-progress-text">0%</span>
                </div>
                <div class="upload-status">
                    <span class="upload-status-text">Preparazione...</span>
                </div>
            `;

            uploadBody.appendChild(uploadItem);

            // Bind cancel button
            uploadItem.querySelector('.upload-cancel-btn')?.addEventListener('click', () => {
                this.cancelSingleUpload(uploadId);
            });

            this.state.uploadQueue.push({
                id: uploadId,
                file: file,
                status: 'pending'
            });

            this.updateUploadSummary();
        }

        updateUploadProgress(uploadId, percent) {
            const uploadItem = document.getElementById(`upload-${uploadId}`);
            if (!uploadItem) return;

            const progressFill = uploadItem.querySelector('.upload-progress-fill');
            const progressText = uploadItem.querySelector('.upload-progress-text');
            const statusText = uploadItem.querySelector('.upload-status-text');

            if (progressFill) progressFill.style.width = `${percent}%`;
            if (progressText) progressText.textContent = `${Math.round(percent)}%`;
            if (statusText) statusText.textContent = 'Caricamento in corso...';

            // Update queue status
            const queueItem = this.state.uploadQueue.find(item => item.id === uploadId);
            if (queueItem) {
                queueItem.status = 'uploading';
                queueItem.progress = percent;
            }
        }

        completeUpload(uploadId, fileName) {
            const uploadItem = document.getElementById(`upload-${uploadId}`);
            if (!uploadItem) return;

            uploadItem.classList.add('complete');
            const progressFill = uploadItem.querySelector('.upload-progress-fill');
            const progressText = uploadItem.querySelector('.upload-progress-text');
            const statusText = uploadItem.querySelector('.upload-status-text');

            if (progressFill) {
                progressFill.style.width = '100%';
                progressFill.style.background = '#10B981';
            }
            if (progressText) progressText.textContent = '100%';
            if (statusText) {
                statusText.textContent = 'Completato';
                statusText.style.color = '#10B981';
            }

            // Update queue
            const queueItem = this.state.uploadQueue.find(item => item.id === uploadId);
            if (queueItem) {
                queueItem.status = 'completed';
            }

            this.updateUploadSummary();
            this.showToast(`${fileName} caricato con successo`, 'success');

            // Auto-hide after all complete
            setTimeout(() => {
                this.checkAutoHideUploadToast();
            }, 2000);
        }

        failUpload(uploadId, error) {
            const uploadItem = document.getElementById(`upload-${uploadId}`);
            if (!uploadItem) return;

            uploadItem.classList.add('failed');
            const statusText = uploadItem.querySelector('.upload-status-text');
            if (statusText) {
                statusText.textContent = `Errore: ${error}`;
                statusText.style.color = '#EF4444';
            }

            // Update queue
            const queueItem = this.state.uploadQueue.find(item => item.id === uploadId);
            if (queueItem) {
                queueItem.status = 'failed';
                queueItem.error = error;
            }

            this.updateUploadSummary();
        }

        cancelUpload(uploadId) {
            const uploadItem = document.getElementById(`upload-${uploadId}`);
            if (uploadItem) {
                uploadItem.remove();
            }

            // Remove from queue
            this.state.uploadQueue = this.state.uploadQueue.filter(item => item.id !== uploadId);
            this.updateUploadSummary();
        }

        cancelSingleUpload(uploadId) {
            const xhr = this.state.activeUploads.get(uploadId);
            if (xhr) {
                xhr.abort();
            }
            this.cancelUpload(uploadId);
        }

        cancelAllUploads() {
            // Abort all active uploads
            this.state.activeUploads.forEach((xhr, uploadId) => {
                xhr.abort();
            });
            this.state.activeUploads.clear();

            // Clear upload queue
            this.state.uploadQueue = [];

            // Clear UI
            const uploadBody = document.getElementById('uploadToastBody');
            if (uploadBody) {
                uploadBody.innerHTML = '';
            }

            this.hideUploadToast();
        }

        updateUploadSummary() {
            const summaryText = document.getElementById('uploadSummaryText');
            if (!summaryText) return;

            const total = this.state.uploadQueue.length;
            const completed = this.state.uploadQueue.filter(item => item.status === 'completed').length;

            summaryText.textContent = `${completed} di ${total} completati`;
        }

        showUploadToast() {
            const toast = document.getElementById('advancedUploadToast');
            if (toast) {
                toast.classList.remove('hidden');
            }
        }

        hideUploadToast() {
            const toast = document.getElementById('advancedUploadToast');
            if (toast) {
                toast.classList.add('hidden');
            }

            // Clear upload queue
            this.state.uploadQueue = [];
            const uploadBody = document.getElementById('uploadToastBody');
            if (uploadBody) {
                uploadBody.innerHTML = '';
            }
        }

        checkAutoHideUploadToast() {
            const allCompleted = this.state.uploadQueue.every(item =>
                item.status === 'completed' || item.status === 'failed'
            );

            if (allCompleted && this.state.uploadQueue.length > 0) {
                const hasErrors = this.state.uploadQueue.some(item => item.status === 'failed');
                if (!hasErrors) {
                    setTimeout(() => {
                        this.hideUploadToast();
                    }, 1000);
                }
            }
        }

        // ========================================
        // DOCUMENT CREATION FUNCTIONALITY
        // ========================================

        showDocumentModal() {
            if (this.state.isRoot) {
                this.showToast('Seleziona una cartella prima di creare un documento', 'error');
                return;
            }

            const modal = document.getElementById('createDocumentModal');
            if (modal) {
                modal.style.display = 'flex';
                // Reset form
                document.querySelectorAll('.document-type-card').forEach(card => {
                    card.classList.remove('selected');
                });
                document.getElementById('documentName').value = '';
                document.getElementById('confirmDocumentCreate').disabled = true;
            }
        }

        hideDocumentModal() {
            const modal = document.getElementById('createDocumentModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        validateDocumentForm() {
            const selectedType = document.querySelector('.document-type-card.selected');
            const documentName = document.getElementById('documentName')?.value.trim();
            const confirmBtn = document.getElementById('confirmDocumentCreate');

            if (confirmBtn) {
                confirmBtn.disabled = !(selectedType && documentName);
            }
        }

        async createDocument(type, name) {
            try {
                // Show loading state
                const confirmBtn = document.getElementById('confirmDocumentCreate');
                const originalText = confirmBtn.innerHTML;
                confirmBtn.innerHTML = `
                    <svg class="spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" stroke-opacity="0.25"/>
                        <path d="M12 2a10 10 0 0 1 10 10" stroke-opacity="1">
                            <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite"/>
                        </path>
                    </svg>
                    Creazione in corso...
                `;
                confirmBtn.disabled = true;

                // Prefer router-style create to avoid 404 in some setups
                const response = await fetch('/CollaboraNexio/api/files_tenant.php?action=create_document', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: JSON.stringify({
                        type: type,
                        name: name,
                        folder_id: this.state.currentFolderId,
                        csrf_token: this.csrfToken
                    }),
                    credentials: 'same-origin'
                });

                const result = await response.json();

                if (result.success) {
                    this.showToast(`Documento "${name}" creato con successo`, 'success');
                    this.hideDocumentModal();
                    this.loadFiles(); // Refresh file list

                    // Optionally open the document in editor
                    if (result.data && result.data.file && result.data.file.is_editable) {
                        setTimeout(() => {
                            if (confirm('Vuoi aprire il documento appena creato?')) {
                                this.openDocumentEditor(result.data.file.id);
                            }
                        }, 500);
                    }
                } else {
                    this.showToast(result.error || 'Errore durante la creazione del documento', 'error');
                }

                // Restore button state
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;

            } catch (error) {
                console.error('Create document error:', error);
                this.showToast('Errore di rete durante la creazione del documento', 'error');

                // Restore button state
                const confirmBtn = document.getElementById('confirmDocumentCreate');
                if (confirmBtn) {
                    confirmBtn.innerHTML = 'Crea Documento';
                    confirmBtn.disabled = false;
                }
            }
        }

        openDocumentEditor(fileId) {
            // Check if documentEditor is available
            if (window.documentEditor && typeof window.documentEditor.openDocument === 'function') {
                window.documentEditor.openDocument(fileId);
            } else {
                console.log('Document editor not available, opening file details instead');
                // Fallback: show file details
                const fileElement = document.querySelector(`[data-file-id="${fileId}"]`);
                if (fileElement) {
                    this.showDetailsSidebar(fileElement);
                }
            }
        }

        // ========================================
        // EXISTING METHODS (preserved from original)
        // ========================================

        async loadFiles() {
            try {
                const params = new URLSearchParams({
                    action: 'list',
                    folder_id: this.state.currentFolderId || '',
                    search: this.state.searchQuery || ''
                });

                const response = await fetch(`${this.config.filesApi}?${params}`, {
                    method: 'GET',
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error('Failed to load files');
                }

                const result = await response.json();

                if (result.success) {
                    this.renderFiles(result.data);
                    this.updateUIForCurrentState(result.data);
                    this.updateBreadcrumb(result.data.breadcrumb);
                } else {
                    throw new Error(result.error || 'Failed to load files');
                }
            } catch (error) {
                console.error('Errore nel caricamento dei file:', error);
                this.showToast('Errore nel caricamento dei file', 'error');
            }
        }

        async loadInitialData() {
            try {
                console.log('Caricamento file iniziali...');
                this.updateBreadcrumb();
                await this.loadFiles();
            } catch (error) {
                console.error('Errore nel caricamento dei file:', error);
                this.showToast('Errore nel caricamento dei file', 'error');
            }
        }

        renderFiles(data) {
            const filesGrid = document.getElementById('filesGrid');
            const filesList = document.getElementById('filesList');
            const emptyState = document.getElementById('emptyState');

            const items = data.items || [];

            if (!items || items.length === 0) {
                if (emptyState) {
                    emptyState.classList.remove('hidden');
                }
                if (filesGrid) filesGrid.innerHTML = '';
                if (filesList) {
                    const tbody = filesList.querySelector('tbody');
                    if (tbody) tbody.innerHTML = '';
                }
                return;
            }

            if (emptyState) {
                emptyState.classList.add('hidden');
            }

            if (filesGrid) filesGrid.innerHTML = '';
            if (filesList) {
                const tbody = filesList.querySelector('tbody');
                if (tbody) tbody.innerHTML = '';
            }

            items.forEach(item => {
                if (this.state.currentView === 'grid') {
                    this.renderGridItem(item);
                } else {
                    this.renderListItem(item);
                }
            });
        }

        renderGridItem(item) {
            const filesGrid = document.getElementById('filesGrid');
            if (!filesGrid) return;

            const isFolder = item.type === 'folder';
            const fileCard = document.createElement('div');
            fileCard.className = isFolder ? 'file-card folder' : 'file-card';
            fileCard.dataset.name = item.name;
            fileCard.dataset.type = isFolder ? 'folder' : (item.type || 'file');
            fileCard.dataset.id = item.id;
            fileCard.dataset.fileId = item.id;

            const iconHtml = isFolder ?
                `<svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M10 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2h-8l-2-2z" fill="#F59E0B"/>
                </svg>` :
                this.getFileIcon(this.getFileTypeFromMime(item.mime_type));

            const formattedSize = isFolder ?
                `${(item.subfolder_count || 0) + (item.file_count || 0)} elementi` :
                this.formatFileSize(item.size);

            const modifiedDate = this.formatDate(item.updated_at);

            fileCard.innerHTML = `
                <div class="file-card-icon ${item.type}">
                    ${iconHtml}
                </div>
                <div class="file-card-info">
                    <h4 class="file-name">${item.name}</h4>
                    <span class="file-meta">${formattedSize} · ${modifiedDate}</span>
                </div>
                <div class="file-card-actions">
                    <button class="action-btn" title="Download">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                    </button>
                    <button class="action-btn" title="More">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="12" cy="12" r="1"/>
                            <circle cx="12" cy="5" r="1"/>
                            <circle cx="12" cy="19" r="1"/>
                        </svg>
                    </button>
                </div>
            `;

            filesGrid.appendChild(fileCard);
        }

        renderListItem(file) {
            const filesList = document.getElementById('filesList');
            if (!filesList) return;

            const tbody = filesList.querySelector('tbody');
            if (!tbody) return;

            const row = document.createElement('tr');
            row.className = file.is_folder ? 'file-row folder' : 'file-row';
            row.dataset.name = file.name;
            row.dataset.type = file.is_folder ? 'folder' : file.type;
            row.dataset.fileId = file.id;

            const iconColor = file.is_folder ? '#F59E0B' : this.getFileColor(file.type);
            const formattedSize = file.is_folder ? '—' : this.formatFileSize(file.size);
            const modifiedDate = this.formatDate(file.updated_at);

            row.innerHTML = `
                <td class="checkbox-col">
                    <input type="checkbox" class="file-checkbox">
                </td>
                <td class="name-col">
                    <div class="file-name-wrapper">
                        <svg class="file-icon" viewBox="0 0 24 24" fill="${iconColor}">
                            ${file.is_folder ?
                                '<path d="M10 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2h-8l-2-2z"/>' :
                                '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
                            }
                        </svg>
                        <span class="file-name">${file.name}</span>
                    </div>
                </td>
                <td>${file.uploaded_by?.name || 'Tu'}</td>
                <td>${modifiedDate}</td>
                <td>${formattedSize}</td>
                <td class="actions-col">
                    <button class="action-btn" title="More">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="12" cy="12" r="1"/>
                            <circle cx="12" cy="5" r="1"/>
                            <circle cx="12" cy="19" r="1"/>
                        </svg>
                    </button>
                </td>
            `;

            tbody.appendChild(row);
        }

        // Utility methods
        generateUploadId() {
            return 'upload_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        }

        getFileType(fileName) {
            const ext = fileName.split('.').pop().toLowerCase();
            const typeMap = {
                pdf: 'pdf',
                doc: 'word', docx: 'word',
                xls: 'excel', xlsx: 'excel',
                ppt: 'powerpoint', pptx: 'powerpoint',
                jpg: 'image', jpeg: 'image', png: 'image', gif: 'image',
                mp4: 'video', avi: 'video', mkv: 'video',
                mp3: 'audio', wav: 'audio',
                zip: 'archive', rar: 'archive',
                txt: 'text'
            };
            return typeMap[ext] || 'file';
        }

        getFileTypeFromMime(mimeType) {
            if (!mimeType) return 'file';

            if (mimeType.includes('pdf')) return 'pdf';
            if (mimeType.includes('word') || mimeType.includes('document')) return 'word';
            if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return 'excel';
            if (mimeType.includes('powerpoint') || mimeType.includes('presentation')) return 'powerpoint';
            if (mimeType.startsWith('image/')) return 'image';
            if (mimeType.startsWith('video/')) return 'video';
            if (mimeType.startsWith('audio/')) return 'audio';
            if (mimeType.includes('zip') || mimeType.includes('rar')) return 'archive';
            if (mimeType.includes('text')) return 'text';

            return 'file';
        }

        getFileIcon(type) {
            const icons = {
                pdf: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#EF4444" stroke="#EF4444"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">PDF</text></svg>',
                word: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#2563EB" stroke="#2563EB"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">DOC</text></svg>',
                excel: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#10B981" stroke="#10B981"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">XLS</text></svg>',
                powerpoint: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#F59E0B" stroke="#F59E0B"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">PPT</text></svg>',
                text: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#6B7280" stroke="#6B7280"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">TXT</text></svg>',
                image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
                folder: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2h-8l-2-2z" fill="#F59E0B"/></svg>',
                default: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>'
            };
            return icons[type] || icons.default;
        }

        getFileColor(type) {
            const colors = {
                pdf: '#EF4444',
                doc: '#2563EB',
                docx: '#2563EB',
                xls: '#10B981',
                xlsx: '#10B981',
                ppt: '#F59E0B',
                pptx: '#F59E0B',
                txt: '#6B7280',
                default: '#6B7280'
            };
            return colors[type] || colors.default;
        }

        formatFileSize(bytes) {
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            if (bytes === 0) return '0 Bytes';
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
        }

        formatDate(dateString) {
            if (!dateString) return 'Sconosciuto';

            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

            if (diffDays === 0) {
                const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
                if (diffHours === 0) {
                    const diffMins = Math.floor(diffMs / (1000 * 60));
                    if (diffMins === 0) return 'ora';
                    return `${diffMins} minut${diffMins > 1 ? 'i' : 'o'} fa`;
                }
                return `${diffHours} or${diffHours > 1 ? 'e' : 'a'} fa`;
            } else if (diffDays === 1) {
                return 'ieri';
            } else if (diffDays < 7) {
                return `${diffDays} giorni fa`;
            } else if (diffDays < 30) {
                const weeks = Math.floor(diffDays / 7);
                return `${weeks} settiman${weeks > 1 ? 'e' : 'a'} fa`;
            } else if (diffDays < 365) {
                const months = Math.floor(diffDays / 30);
                return `${months} mes${months > 1 ? 'i' : 'e'} fa`;
            } else {
                const years = Math.floor(diffDays / 365);
                return `${years} ann${years > 1 ? 'i' : 'o'} fa`;
            }
        }

        showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <div class="toast-content">
                    ${this.getToastIcon(type)}
                    <span>${message}</span>
                </div>
            `;
            toast.style.cssText = `
                position: fixed;
                bottom: 24px;
                left: 50%;
                transform: translateX(-50%);
                background: white;
                border: 1px solid #E5E7EB;
                border-radius: 8px;
                padding: 12px 16px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                display: flex;
                align-items: center;
                gap: 8px;
                z-index: 2000;
                animation: slideUp 0.3s;
            `;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'slideDown 0.3s';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        getToastIcon(type) {
            const icons = {
                success: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>',
                error: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#EF4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
                info: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3B82F6" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
            };
            return icons[type] || icons.info;
        }

        // Other existing methods preserved...
        handleViewChange(e) {
            const btn = e.currentTarget;
            const view = btn.dataset.view;

            document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            this.state.currentView = view;
            const grid = document.getElementById('filesGrid');
            const list = document.getElementById('filesList');

            if (view === 'grid') {
                grid?.classList.add('view-active');
                list?.classList.remove('view-active');
            } else {
                grid?.classList.remove('view-active');
                list?.classList.add('view-active');
            }
        }

        handleFileClick(element, e) {
            const fileName = element.dataset.name;
            const isFolder = element.dataset.type === 'folder';

            if (e.ctrlKey || e.metaKey) {
                this.toggleFileSelection(element, fileName);
            } else if (e.shiftKey && this.state.selectedFiles.size > 0) {
                this.selectRange(element);
            } else {
                this.clearSelection();
                this.selectFile(element, fileName);

                if (!isFolder) {
                    this.showDetailsSidebar(element);
                }
            }
        }

        openFile(element) {
            const fileName = element.dataset.name;
            const isFolder = element.dataset.type === 'folder';
            const itemId = element.dataset.id || element.dataset.fileId;

            if (isFolder) {
                this.navigateToFolder(itemId, fileName);
            } else {
                // Check if file is a PDF
                if (window.pdfViewer && typeof window.pdfViewer.isPDF === 'function' && window.pdfViewer.isPDF(fileName)) {
                    // Open in PDF viewer
                    console.log(`Opening PDF in viewer: ${fileName} (ID: ${itemId})`);
                    window.pdfViewer.openPDF(itemId, fileName);
                    return;
                }

                // Check if file is editable and document editor is available
                if (window.documentEditor && typeof window.documentEditor.openDocument === 'function') {
                    const isEditable = window.documentEditor.isFileEditable(fileName);

                    if (isEditable) {
                        // Open in document editor
                        console.log(`Opening editable document: ${fileName} (ID: ${itemId})`);
                        window.documentEditor.openDocument(itemId, 'edit');
                        return;
                    }
                }

                // For non-editable files or if editor is not available, download the file
                console.log(`Downloading non-editable file: ${fileName} (ID: ${itemId})`);
                this.showToast(`Download di ${fileName}...`, 'info');
                this.downloadFileById(itemId, fileName);
            }
        }

        navigateToFolder(folderId, folderName) {
            this.state.currentFolderId = folderId;
            this.state.isRoot = false;
            this.loadFiles();
            this.showToast(`Aperta cartella: ${folderName}`, 'success');
        }

        navigateToPath(path) {
            if (path === '/') {
                this.state.currentFolderId = null;
                this.state.isRoot = true;
            }
            this.loadFiles();
        }

        updateBreadcrumb(breadcrumb = []) {
            const breadcrumbItems = document.querySelector('.breadcrumb-items');
            if (!breadcrumbItems) return;

            breadcrumbItems.innerHTML = '';

            const homeItem = document.createElement('a');
            homeItem.href = '#';
            homeItem.className = 'breadcrumb-item';
            homeItem.dataset.path = '/';
            homeItem.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                </svg>
                <span>File Manager</span>
            `;
            homeItem.addEventListener('click', (e) => {
                e.preventDefault();
                this.navigateToPath('/');
            });
            breadcrumbItems.appendChild(homeItem);

            breadcrumb.forEach((item, index) => {
                const separator = document.createElement('svg');
                separator.className = 'breadcrumb-separator';
                separator.innerHTML = '<polyline points="9 18 15 12 9 6"/>';
                separator.setAttribute('viewBox', '0 0 24 24');
                separator.setAttribute('fill', 'none');
                separator.setAttribute('stroke', 'currentColor');
                separator.setAttribute('stroke-width', '2');
                breadcrumbItems.appendChild(separator);

                if (index === breadcrumb.length - 1) {
                    const span = document.createElement('span');
                    span.className = 'breadcrumb-current';
                    span.textContent = item.name;
                    breadcrumbItems.appendChild(span);
                } else {
                    const link = document.createElement('a');
                    link.href = '#';
                    link.className = 'breadcrumb-item';
                    link.textContent = item.name;
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        this.navigateToFolder(item.id, item.name);
                    });
                    breadcrumbItems.appendChild(link);
                }
            });
        }

        updateUIForCurrentState(data) {
            const uploadBtn = document.getElementById('uploadBtn');
            const newFolderBtn = document.getElementById('newFolderBtn');
            const createDocumentBtn = document.getElementById('createDocumentBtn');

            if (uploadBtn) {
                uploadBtn.style.display = 'inline-flex';
            }

            if (newFolderBtn) {
                newFolderBtn.style.display = this.state.isRoot ? 'none' : 'inline-flex';
            }

            if (createDocumentBtn) {
                createDocumentBtn.style.display = 'inline-flex';
            }

            if (this.state.isRoot) {
                document.body.classList.add('at-root');
            } else {
                document.body.classList.remove('at-root');
            }
        }

        // Selection methods
        toggleFileSelection(element, fileName) {
            if (element.classList.contains('selected')) {
                element.classList.remove('selected');
                this.state.selectedFiles.delete(fileName);
            } else {
                element.classList.add('selected');
                this.state.selectedFiles.add(fileName);
            }
            this.updateSelectionCount();
        }

        selectFile(element, fileName) {
            element.classList.add('selected');
            this.state.selectedFiles.add(fileName);
            this.updateSelectionCount();
        }

        clearSelection() {
            document.querySelectorAll('.file-card.selected, .file-row.selected').forEach(el => {
                el.classList.remove('selected');
            });
            this.state.selectedFiles.clear();
            this.updateSelectionCount();
        }

        selectRange(endElement) {
            const allElements = Array.from(document.querySelectorAll('.file-card, .file-row'));
            const lastSelected = Array.from(this.state.selectedFiles).pop();
            if (!lastSelected) return;

            const startElement = document.querySelector(`[data-name="${lastSelected}"]`);
            if (!startElement) return;

            const startIndex = allElements.indexOf(startElement);
            const endIndex = allElements.indexOf(endElement);

            if (startIndex === -1 || endIndex === -1) return;

            this.clearSelection();

            const minIndex = Math.min(startIndex, endIndex);
            const maxIndex = Math.max(startIndex, endIndex);

            for (let i = minIndex; i <= maxIndex; i++) {
                const element = allElements[i];
                const fileName = element.dataset.name;
                if (fileName) {
                    element.classList.add('selected');
                    this.state.selectedFiles.add(fileName);
                }
            }

            this.updateSelectionCount();
        }

        handleSelectAll(checked) {
            if (checked) {
                document.querySelectorAll('.file-card, .file-row').forEach(el => {
                    el.classList.add('selected');
                    const name = el.dataset.name;
                    if (name) {
                        this.state.selectedFiles.add(name);
                    }
                });
            } else {
                this.clearSelection();
            }
        }

        updateSelectionCount() {
            const count = this.state.selectedFiles.size;
            const selectAll = document.getElementById('selectAll');
            if (selectAll) {
                const totalFiles = document.querySelectorAll('.file-card, .file-row').length;
                selectAll.checked = count === totalFiles && totalFiles > 0;
                selectAll.indeterminate = count > 0 && count < totalFiles;
            }
        }

        // Details sidebar
        showDetailsSidebar(fileElement) {
            const sidebar = document.getElementById('fileDetailsSidebar');
            const mainContainer = document.querySelector('.file-main-container');

            if (!sidebar) return;

            const fileName = fileElement.dataset.name;
            const fileType = fileElement.dataset.type;

            // Update sidebar content
            const filename = sidebar.querySelector('.details-filename');
            const typeValue = sidebar.querySelector('.meta-item:nth-child(1) .meta-value');
            const sizeValue = sidebar.querySelector('.meta-item:nth-child(2) .meta-value');
            const modifiedValue = sidebar.querySelector('.meta-item:nth-child(3) .meta-value');
            const ownerValue = sidebar.querySelector('.meta-item:nth-child(4) .meta-value');

            if (filename) filename.textContent = fileName;
            if (typeValue) typeValue.textContent = this.getFileTypeLabel(fileType) || 'File';
            if (sizeValue) sizeValue.textContent = fileElement.querySelector('.file-meta')?.textContent.split('·')[0]?.trim() || '—';
            if (modifiedValue) modifiedValue.textContent = fileElement.querySelector('.file-meta')?.textContent.split('·')[1]?.trim() || '—';
            if (ownerValue) ownerValue.textContent = 'Tu';

            this.updateFilePreview(sidebar, fileElement);

            sidebar.classList.add('active');
            if (mainContainer) {
                mainContainer.classList.add('sidebar-open');
            }
        }

        hideDetailsSidebar() {
            const sidebar = document.getElementById('fileDetailsSidebar');
            const mainContainer = document.querySelector('.file-main-container');

            if (sidebar) {
                sidebar.classList.remove('active');
            }
            if (mainContainer) {
                mainContainer.classList.remove('sidebar-open');
            }
        }

        updateFilePreview(sidebar, fileElement) {
            const previewContainer = sidebar.querySelector('.details-preview');
            if (!previewContainer) return;

            const fileType = fileElement.dataset.type;
            const fileName = fileElement.dataset.name;

            previewContainer.innerHTML = '';

            if (fileType === 'image') {
                const existingPreview = fileElement.querySelector('.file-card-preview img');
                if (existingPreview) {
                    const img = existingPreview.cloneNode(true);
                    previewContainer.appendChild(img);
                } else {
                    previewContainer.innerHTML = '<img src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 200 150\'%3E%3Crect fill=\'%23F3F4F6\' width=\'200\' height=\'150\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%236B7280\' font-family=\'sans-serif\' font-size=\'14\'%3EImage Preview%3C/text%3E%3C/svg%3E" alt="Preview">';
                }
            } else {
                const icon = this.getFileIcon(fileType);
                previewContainer.innerHTML = `
                    <div style="display: flex; align-items: center; justify-content: center; height: 100%; padding: 40px;">
                        <div style="width: 80px; height: 80px; opacity: 0.5;">
                            ${icon}
                        </div>
                    </div>
                `;
            }
        }

        getFileTypeLabel(type) {
            const typeLabels = {
                pdf: 'Documento PDF',
                word: 'Documento Word',
                excel: 'Foglio Excel',
                powerpoint: 'Presentazione PowerPoint',
                text: 'File di Testo',
                image: 'Immagine',
                video: 'Video',
                audio: 'Audio',
                archive: 'Archivio',
                folder: 'Cartella'
            };
            return typeLabels[type] || 'File';
        }

        // Context menu and other existing methods...
        showContextMenu(x, y, fileElement) {
            const contextMenu = document.getElementById('contextMenu');
            if (!contextMenu) return;

            this.contextFile = fileElement;

            contextMenu.style.left = `${x}px`;
            contextMenu.style.top = `${y}px`;
            contextMenu.classList.add('active');

            const rect = contextMenu.getBoundingClientRect();
            if (rect.right > window.innerWidth) {
                contextMenu.style.left = `${x - rect.width}px`;
            }
            if (rect.bottom > window.innerHeight) {
                contextMenu.style.top = `${y - rect.height}px`;
            }
        }

        handleContextAction(action) {
            if (!this.contextFile) return;

            const fileName = this.contextFile.dataset.name;

            switch (action) {
                case 'rename':
                    this.renameFile(fileName);
                    break;
                case 'copy':
                    this.copyFile(fileName);
                    break;
                case 'download':
                    this.downloadFile(fileName);
                    break;
                case 'share':
                    this.shareFile(fileName);
                    break;
                case 'delete':
                    this.deleteFile(fileName);
                    break;
            }

            this.contextFile = null;
        }

        // File operations
        async createNewFolder() {
            if (this.state.isRoot) {
                this.showToast('Non puoi creare cartelle nella root. Seleziona prima una cartella tenant.', 'error');
                return;
            }

            const folderName = prompt('Inserisci il nome della cartella:');
            if (folderName && folderName.trim()) {
                try {
                    const response = await fetch(this.config.filesApi + '?action=create_folder', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            name: folderName.trim(),
                            parent_id: this.state.currentFolderId,
                            csrf_token: this.csrfToken
                        }),
                        credentials: 'same-origin'
                    });

                    const result = await response.json();

                    if (result.success) {
                        this.showToast(`Cartella "${folderName}" creata`, 'success');
                        this.loadFiles();
                    } else {
                        this.showToast(result.error || 'Errore nella creazione della cartella', 'error');
                    }
                } catch (error) {
                    console.error('Error creating folder:', error);
                    this.showToast('Errore di rete nella creazione della cartella', 'error');
                }
            }
        }

        renameFile(fileName) {
            const newName = prompt('Inserisci il nuovo nome:', fileName);
            if (newName && newName !== fileName) {
                this.showToast(`Rinominato in ${newName}`, 'success');
                // Implement rename API call
            }
        }

        copyFile(fileName) {
            this.showToast(`${fileName} copiato negli appunti`, 'info');
            // Implement copy functionality
        }

        downloadFile(fileName) {
            // Find file element by name to get the ID
            const fileElement = document.querySelector(`[data-name="${fileName}"]`);
            const fileId = fileElement?.dataset.fileId || fileElement?.dataset.id;

            if (fileId) {
                this.downloadFileById(fileId, fileName);
            } else {
                this.showToast('Errore: ID file non trovato', 'error');
            }
        }

        downloadFileById(fileId, fileName) {
            // Create download link and trigger download
            const downloadUrl = `${this.config.filesApi}?action=download&id=${fileId}`;

            // Create temporary link element
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = fileName || 'download';
            link.style.display = 'none';

            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            this.showToast(`Download di ${fileName || 'file'} avviato`, 'success');
        }

        shareFile(fileName) {
            const shareUrl = `${window.location.origin}/files/${encodeURIComponent(fileName)}`;

            if (navigator.share) {
                navigator.share({
                    title: fileName,
                    text: `Check out ${fileName}`,
                    url: shareUrl
                }).then(() => {
                    this.showToast('File condiviso con successo', 'success');
                }).catch((error) => {
                    console.log('Error sharing:', error);
                });
            } else if (navigator.clipboard) {
                navigator.clipboard.writeText(shareUrl).then(() => {
                    this.showToast('Link di condivisione copiato negli appunti', 'success');
                });
            }
        }

        async deleteFile(fileName) {
            if (confirm(`Sei sicuro di voler eliminare "${fileName}"?`)) {
                const element = document.querySelector(`[data-name="${fileName}"]`);
                const fileId = element?.dataset.fileId;

                if (!fileId) {
                    this.showToast('Errore: ID file non trovato', 'error');
                    return;
                }

                try {
                    const response = await fetch(`${this.config.filesApi}?action=delete&id=${fileId}`, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-Token': this.csrfToken
                        },
                        credentials: 'same-origin'
                    });

                    const result = await response.json();

                    if (result.success) {
                        if (element) {
                            element.style.animation = 'fadeOut 0.3s';
                            setTimeout(() => {
                                element.remove();
                                this.showToast(`${fileName} eliminato`, 'success');
                            }, 300);
                        }
                    } else {
                        this.showToast(result.error || 'Errore durante l\'eliminazione', 'error');
                    }
                } catch (error) {
                    console.error('Error deleting file:', error);
                    this.showToast('Errore di rete durante l\'eliminazione', 'error');
                }
            }
        }

        async deleteSelected() {
            const count = this.state.selectedFiles.size;
            if (count === 0) return;

            if (confirm(`Eliminare ${count} element${count > 1 ? 'i' : 'o'} selezionat${count > 1 ? 'i' : 'o'}?`)) {
                let deletedCount = 0;

                for (const fileName of this.state.selectedFiles) {
                    const element = document.querySelector(`[data-name="${fileName}"]`);
                    const fileId = element?.dataset.fileId;

                    if (fileId) {
                        try {
                            const response = await fetch(`${this.config.filesApi}?action=delete&id=${fileId}`, {
                                method: 'DELETE',
                                headers: {
                                    'X-CSRF-Token': this.csrfToken
                                },
                                credentials: 'same-origin'
                            });

                            const result = await response.json();
                            if (result.success) {
                                deletedCount++;
                                if (element) {
                                    element.style.animation = 'fadeOut 0.3s';
                                    setTimeout(() => element.remove(), 300);
                                }
                            }
                        } catch (error) {
                            console.error(`Error deleting ${fileName}:`, error);
                        }
                    }
                }

                this.showToast(`${deletedCount} element${deletedCount > 1 ? 'i' : 'o'} eliminat${deletedCount > 1 ? 'i' : 'o'}`, 'success');
                this.clearSelection();
            }
        }

        // Filter and sort methods
        handleSearch(query) {
            this.state.searchQuery = query.toLowerCase();
            this.filterFiles();
        }

        filterFiles() {
            const files = document.querySelectorAll('.file-card, .file-row');
            let visibleCount = 0;

            files.forEach(file => {
                const fileName = file.dataset.name.toLowerCase();
                const matchesSearch = !this.state.searchQuery || fileName.includes(this.state.searchQuery);
                const matchesFilter = this.state.filterType === 'all' || file.dataset.type === this.state.filterType;

                if (matchesSearch && matchesFilter) {
                    file.style.display = '';
                    visibleCount++;
                } else {
                    file.style.display = 'none';
                }
            });

            const emptyState = document.getElementById('emptyState');
            const filesGrid = document.getElementById('filesGrid');
            const filesList = document.getElementById('filesList');

            if (visibleCount === 0 && emptyState) {
                emptyState.classList.remove('hidden');
                if (filesGrid) filesGrid.style.display = 'none';
                if (filesList) filesList.style.display = 'none';
            } else if (emptyState) {
                emptyState.classList.add('hidden');
                if (filesGrid && this.state.currentView === 'grid') {
                    filesGrid.style.display = '';
                }
                if (filesList && this.state.currentView === 'list') {
                    filesList.style.display = '';
                }
            }
        }

        showFilterMenu() {
            const filterOptions = ['Tutti i File', 'Documenti', 'Immagini', 'Video', 'Cartelle'];
            this.showDropdownMenu('filter', filterOptions, (option) => {
                this.state.filterType = option.toLowerCase().replace(' files', '').replace('s', '');
                this.filterFiles();
            });
        }

        showSortMenu() {
            const sortOptions = ['Nome', 'Data Modifica', 'Dimensione', 'Tipo'];
            this.showDropdownMenu('sort', sortOptions, (option) => {
                this.state.sortBy = option.toLowerCase().replace(' ', '-');
                this.sortFiles();
            });
        }

        showDropdownMenu(type, options, callback) {
            const menu = document.createElement('div');
            menu.className = 'dropdown-menu';
            menu.style.cssText = `
                position: absolute;
                top: 120px;
                right: ${type === 'sort' ? '24px' : '100px'};
                background: white;
                border: 1px solid #E5E7EB;
                border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                padding: 8px;
                z-index: 100;
            `;

            options.forEach(option => {
                const item = document.createElement('button');
                item.textContent = option;
                item.style.cssText = `
                    display: block;
                    width: 100%;
                    padding: 8px 12px;
                    border: none;
                    background: none;
                    text-align: left;
                    cursor: pointer;
                    border-radius: 4px;
                    font-size: 14px;
                `;
                item.onmouseover = () => item.style.background = '#F3F4F6';
                item.onmouseout = () => item.style.background = 'none';
                item.onclick = () => {
                    callback(option);
                    menu.remove();
                };
                menu.appendChild(item);
            });

            document.body.appendChild(menu);

            setTimeout(() => {
                document.addEventListener('click', function removeMenu() {
                    menu.remove();
                    document.removeEventListener('click', removeMenu);
                }, { once: true });
            }, 0);
        }

        sortFiles() {
            console.log('Sorting files by:', this.state.sortBy);
            // Implement sorting logic
        }

        handleFileAction(btn, fileElement) {
            const action = btn.title.toLowerCase();
            const fileName = fileElement.dataset.name;

            switch (action) {
                case 'download':
                    this.downloadFile(fileName);
                    break;
                case 'share':
                    this.shareFile(fileName);
                    break;
                case 'more':
                    this.showFileMenu(btn, fileElement);
                    break;
            }
        }

        showFileMenu(btn, fileElement) {
            const rect = btn.getBoundingClientRect();
            const fileName = fileElement.dataset.name;

            const menu = document.createElement('div');
            menu.className = 'file-dropdown-menu';
            menu.style.cssText = `
                position: fixed;
                top: ${rect.bottom + 5}px;
                left: ${rect.left - 100}px;
                background: white;
                border: 1px solid #E5E7EB;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                padding: 4px;
                min-width: 160px;
                z-index: 1000;
            `;

            const menuOptions = [
                { label: 'Apri', icon: '📂', action: 'open' },
                { label: 'Scarica', icon: '⬇', action: 'download' },
                { label: 'Condividi', icon: '🔗', action: 'share' },
                { divider: true },
                { label: 'Rinomina', icon: '✏', action: 'rename' },
                { label: 'Copia', icon: '📋', action: 'copy' },
                { label: 'Sposta', icon: '➡', action: 'move' },
                { divider: true },
                { label: 'Dettagli', icon: 'ℹ', action: 'details' },
                { label: 'Elimina', icon: '🗑', action: 'delete', danger: true }
            ];

            menuOptions.forEach(option => {
                if (option.divider) {
                    const divider = document.createElement('div');
                    divider.style.cssText = `
                        height: 1px;
                        background: #E5E7EB;
                        margin: 4px 0;
                    `;
                    menu.appendChild(divider);
                } else {
                    const item = document.createElement('button');
                    item.innerHTML = `
                        <span style="margin-right: 8px; opacity: 0.6;">${option.icon}</span>
                        <span>${option.label}</span>
                    `;
                    item.style.cssText = `
                        display: flex;
                        align-items: center;
                        width: 100%;
                        padding: 8px 12px;
                        border: none;
                        background: none;
                        text-align: left;
                        cursor: pointer;
                        border-radius: 4px;
                        font-size: 14px;
                        color: ${option.danger ? '#EF4444' : '#374151'};
                        transition: background 0.2s;
                    `;

                    item.onmouseover = () => {
                        item.style.background = option.danger ? '#FEE2E2' : '#F3F4F6';
                    };
                    item.onmouseout = () => {
                        item.style.background = 'none';
                    };

                    item.onclick = (e) => {
                        e.stopPropagation();
                        menu.remove();
                        this.handleFileMenuAction(option.action, fileName, fileElement);
                    };

                    menu.appendChild(item);
                }
            });

            document.body.appendChild(menu);

            const menuRect = menu.getBoundingClientRect();
            if (menuRect.right > window.innerWidth) {
                menu.style.left = `${window.innerWidth - menuRect.width - 10}px`;
            }
            if (menuRect.bottom > window.innerHeight) {
                menu.style.top = `${rect.top - menuRect.height - 5}px`;
            }

            setTimeout(() => {
                const removeMenu = (e) => {
                    if (!menu.contains(e.target) && e.target !== btn) {
                        menu.remove();
                        document.removeEventListener('click', removeMenu);
                    }
                };
                document.addEventListener('click', removeMenu);
            }, 0);
        }

        handleFileMenuAction(action, fileName, fileElement) {
            switch (action) {
                case 'open':
                    // Double-click logic: check for PDF first
                    const itemId = fileElement.dataset.id || fileElement.dataset.fileId;

                    if (window.pdfViewer && typeof window.pdfViewer.isPDF === 'function' && window.pdfViewer.isPDF(fileName)) {
                        console.log(`Opening PDF from menu: ${fileName} (ID: ${itemId})`);
                        window.pdfViewer.openPDF(itemId, fileName);
                    } else {
                        this.openFile(fileElement);
                    }
                    break;
                case 'download':
                    this.downloadFile(fileName);
                    break;
                case 'share':
                    this.shareFile(fileName);
                    break;
                case 'rename':
                    this.renameFile(fileName);
                    break;
                case 'copy':
                    this.copyFile(fileName);
                    break;
                case 'move':
                    this.showToast(`Spostamento di ${fileName}...`, 'info');
                    break;
                case 'details':
                    this.clearSelection();
                    this.selectFile(fileElement, fileName);
                    this.showDetailsSidebar(fileElement);
                    break;
                case 'delete':
                    this.deleteFile(fileName);
                    break;
            }
        }

        handleCheckboxChange(fileRow, checked) {
            const fileName = fileRow.dataset.name;
            if (checked) {
                this.selectFile(fileRow, fileName);
            } else {
                fileRow.classList.remove('selected');
                this.state.selectedFiles.delete(fileName);
                this.updateSelectionCount();
            }
        }

        // ========================================
        // TENANT FOLDER CREATION FUNCTIONALITY
        // ========================================

        async showCreateTenantFolderModal() {
            const modal = document.getElementById('createTenantFolderModal');
            if (!modal) {
                console.error('Tenant folder modal not found');
                return;
            }

            // Load tenants and populate dropdown
            await this.loadTenantOptions();

            // Show modal
            modal.style.display = 'flex';

            // Reset form
            document.getElementById('tenantSelect').value = '';
            document.getElementById('folderName').value = '';
        }

        async loadTenantOptions() {
            try {
                const response = await fetch('/CollaboraNexio/api/tenants/list.php', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Cache-Control': 'no-cache, no-store, must-revalidate',
                        'Pragma': 'no-cache',
                        'Expires': '0'
                    }
                });

                if (!response.ok) {
                    throw new Error('Failed to load tenants');
                }

                const result = await response.json();

                if (result.success && result.data && result.data.tenants) {
                    const select = document.getElementById('tenantSelect');
                    if (!select) return;

                    // Clear existing options
                    select.innerHTML = '<option value="">-- Seleziona un tenant --</option>';

                    // Add tenant options
                    result.data.tenants.forEach(tenant => {
                        const option = document.createElement('option');
                        option.value = tenant.id;
                        option.textContent = tenant.denominazione; // API returns 'denominazione', not 'name'
                        select.appendChild(option);
                    });

                    console.log('Loaded', result.data.tenants.length, 'tenant(s)');
                } else {
                    this.showToast(result.error || 'Errore nel caricamento dei tenant', 'error');
                }
            } catch (error) {
                console.error('Error loading tenants:', error);
                this.showToast('Errore nel caricamento dei tenant', 'error');
            }
        }

        async createRootFolder(folderName, tenantId) {
            try {
                const response = await fetch(this.config.filesApi + '?action=create_root_folder', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: JSON.stringify({
                        name: folderName,
                        tenant_id: tenantId,
                        csrf_token: this.csrfToken
                    }),
                    credentials: 'same-origin'
                });

                const result = await response.json();

                if (result.success) {
                    this.showToast(result.message || 'Cartella tenant creata con successo', 'success');
                    return result;
                } else {
                    this.showToast(result.error || 'Errore nella creazione della cartella', 'error');
                    return null;
                }
            } catch (error) {
                console.error('Error creating root folder:', error);
                this.showToast('Errore di rete nella creazione della cartella', 'error');
                return null;
            }
        }

        createNewFolder() {
            if (this.state.isRoot) {
                this.showToast('Seleziona prima una cartella tenant', 'error');
                return;
            }

            const folderName = prompt('Inserisci il nome della nuova cartella:');
            if (folderName && folderName.trim()) {
                // Implement folder creation logic
                this.showToast(`Creazione cartella "${folderName}"...`, 'info');
                // TODO: Call API to create folder
            }
        }
    }

    // Initialize EnhancedFileManager
    const initEnhancedFileManager = () => {
        console.log('🚀 Initializing EnhancedFileManager...');
        window.fileManager = new EnhancedFileManager();
        console.log('✅ EnhancedFileManager initialized successfully');
    };

    // Initialize when DOM is ready, or immediately if already loaded
    if (document.readyState === 'loading') {
        // DOM is still loading
        console.log('⏳ DOM is loading, waiting for DOMContentLoaded event...');
        document.addEventListener('DOMContentLoaded', initEnhancedFileManager);
    } else {
        // DOM is already loaded (script loaded late)
        console.log('⚡ DOM already loaded, initializing immediately');
        initEnhancedFileManager();
    }

    // Add required animations
    const animationStyles = document.createElement('style');
    animationStyles.textContent = `
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: scale(1);
            }
            to {
                opacity: 0;
                transform: scale(0.95);
            }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translate(-50%, 20px);
            }
            to {
                opacity: 1;
                transform: translate(-50%, 0);
            }
        }

        @keyframes slideDown {
            from {
                opacity: 1;
                transform: translate(-50%, 0);
            }
            to {
                opacity: 0;
                transform: translate(-50%, 20px);
            }
        }

        .spinner {
            animation: spin 1s linear infinite;
            width: 16px;
            height: 16px;
            margin-right: 8px;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }
    `;
    document.head.appendChild(animationStyles);
})();