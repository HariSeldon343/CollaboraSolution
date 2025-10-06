/**
 * FileManager - Comprehensive file management system
 * Features: Grid/List view, Drag & Drop, Context Menu, Multi-select, Search, etc.
 */

// Main FileManager Class
class FileManager {
    constructor(container, options = {}) {
        this.container = typeof container === 'string' ?
            document.querySelector(container) : container;

        this.config = {
            apiBase: '/api/',
            viewMode: localStorage.getItem('fm_viewMode') || 'grid',
            sortBy: localStorage.getItem('fm_sortBy') || 'name',
            sortOrder: localStorage.getItem('fm_sortOrder') || 'asc',
            thumbnailSize: 150,
            chunkSize: 1024 * 1024 * 2, // 2MB chunks for upload
            maxConcurrentUploads: 3,
            ...options
        };

        this.state = {
            currentFolder: null,
            files: [],
            folders: [],
            selectedItems: new Set(),
            clipboard: { items: [], operation: null },
            uploadQueue: [],
            searchQuery: '',
            loading: false,
            history: [],
            historyIndex: -1,
            folderTree: []
        };

        this.init();
    }

    init() {
        this.renderLayout();
        this.initializeModules();
        this.bindEvents();
        this.loadInitialData();
        this.setupKeyboardShortcuts();
    }

    renderLayout() {
        this.container.innerHTML = `
            <div class="fm-container">
                <!-- Sidebar -->
                <aside class="fm-sidebar" id="fm-sidebar">
                    <div class="fm-sidebar-header">
                        <h3>Cartelle</h3>
                        <button class="fm-btn-icon" id="fm-toggle-sidebar">
                            <i class="icon-chevron-left"></i>
                        </button>
                    </div>
                    <div class="fm-folder-tree" id="fm-folder-tree">
                        <!-- Folder tree will be rendered here -->
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="fm-main">
                    <!-- Toolbar -->
                    <div class="fm-toolbar">
                        <div class="fm-toolbar-left">
                            <button class="fm-btn" id="fm-btn-upload">
                                <i class="icon-upload"></i> Upload
                            </button>
                            <button class="fm-btn" id="fm-btn-new-folder">
                                <i class="icon-folder-plus"></i> Nuova Cartella
                            </button>
                            <div class="fm-btn-group">
                                <button class="fm-btn-icon" id="fm-btn-cut" disabled>
                                    <i class="icon-cut"></i>
                                </button>
                                <button class="fm-btn-icon" id="fm-btn-copy" disabled>
                                    <i class="icon-copy"></i>
                                </button>
                                <button class="fm-btn-icon" id="fm-btn-paste" disabled>
                                    <i class="icon-paste"></i>
                                </button>
                                <button class="fm-btn-icon" id="fm-btn-delete" disabled>
                                    <i class="icon-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="fm-toolbar-right">
                            <div class="fm-search">
                                <input type="text" id="fm-search-input" placeholder="Cerca file...">
                                <i class="icon-search"></i>
                            </div>
                            <div class="fm-view-toggle">
                                <button class="fm-btn-icon" id="fm-view-grid" data-view="grid">
                                    <i class="icon-grid"></i>
                                </button>
                                <button class="fm-btn-icon" id="fm-view-list" data-view="list">
                                    <i class="icon-list"></i>
                                </button>
                            </div>
                            <select class="fm-sort" id="fm-sort-select">
                                <option value="name">Nome</option>
                                <option value="size">Dimensione</option>
                                <option value="date">Data</option>
                                <option value="type">Tipo</option>
                            </select>
                        </div>
                    </div>

                    <!-- Breadcrumb -->
                    <nav class="fm-breadcrumb" id="fm-breadcrumb">
                        <span class="fm-breadcrumb-item" data-folder="root">
                            <i class="icon-home"></i>
                        </span>
                    </nav>

                    <!-- Content Area -->
                    <div class="fm-content" id="fm-content">
                        <!-- Drop Zone -->
                        <div class="fm-dropzone" id="fm-dropzone">
                            <div class="fm-dropzone-content">
                                <i class="icon-cloud-upload"></i>
                                <p>Trascina file o cartelle qui</p>
                                <p class="fm-dropzone-hint">o clicca per selezionare</p>
                            </div>
                        </div>

                        <!-- File Grid/List -->
                        <div class="fm-files-container ${this.config.viewMode}" id="fm-files-container">
                            <!-- Files will be rendered here -->
                        </div>

                        <!-- Empty State -->
                        <div class="fm-empty-state" id="fm-empty-state" style="display: none;">
                            <i class="icon-folder-open"></i>
                            <p>Questa cartella è vuota</p>
                            <button class="fm-btn-primary" id="fm-empty-upload">
                                Upload File
                            </button>
                        </div>

                        <!-- Loading State -->
                        <div class="fm-loading" id="fm-loading" style="display: none;">
                            <div class="fm-spinner"></div>
                            <p>Caricamento...</p>
                        </div>
                    </div>
                </main>

                <!-- Details Panel -->
                <aside class="fm-details" id="fm-details" style="display: none;">
                    <div class="fm-details-header">
                        <h3>Dettagli</h3>
                        <button class="fm-btn-icon" id="fm-close-details">
                            <i class="icon-x"></i>
                        </button>
                    </div>
                    <div class="fm-details-content" id="fm-details-content">
                        <!-- File details will be rendered here -->
                    </div>
                </aside>
            </div>

            <!-- Upload Progress -->
            <div class="fm-upload-progress" id="fm-upload-progress" style="display: none;">
                <div class="fm-upload-header">
                    <span>Upload in corso</span>
                    <button class="fm-btn-icon" id="fm-minimize-upload">
                        <i class="icon-minimize"></i>
                    </button>
                </div>
                <div class="fm-upload-list" id="fm-upload-list">
                    <!-- Upload items will be rendered here -->
                </div>
            </div>

            <!-- Context Menu -->
            <div class="fm-context-menu" id="fm-context-menu" style="display: none;">
                <ul class="fm-menu-items">
                    <li class="fm-menu-item" data-action="open">
                        <i class="icon-folder-open"></i> Apri
                    </li>
                    <li class="fm-menu-item" data-action="download">
                        <i class="icon-download"></i> Scarica
                    </li>
                    <li class="fm-menu-separator"></li>
                    <li class="fm-menu-item" data-action="cut">
                        <i class="icon-cut"></i> Taglia
                    </li>
                    <li class="fm-menu-item" data-action="copy">
                        <i class="icon-copy"></i> Copia
                    </li>
                    <li class="fm-menu-item" data-action="paste">
                        <i class="icon-paste"></i> Incolla
                    </li>
                    <li class="fm-menu-separator"></li>
                    <li class="fm-menu-item" data-action="rename">
                        <i class="icon-edit"></i> Rinomina
                    </li>
                    <li class="fm-menu-item" data-action="delete">
                        <i class="icon-trash"></i> Elimina
                    </li>
                    <li class="fm-menu-separator"></li>
                    <li class="fm-menu-item" data-action="properties">
                        <i class="icon-info"></i> Proprietà
                    </li>
                </ul>
            </div>

            <!-- Image Preview Modal -->
            <div class="fm-modal" id="fm-preview-modal" style="display: none;">
                <div class="fm-modal-overlay"></div>
                <div class="fm-modal-content">
                    <button class="fm-modal-close" id="fm-close-preview">
                        <i class="icon-x"></i>
                    </button>
                    <div class="fm-preview-container" id="fm-preview-container">
                        <!-- Preview content -->
                    </div>
                    <div class="fm-preview-controls">
                        <button class="fm-btn-icon" id="fm-preview-prev">
                            <i class="icon-chevron-left"></i>
                        </button>
                        <span class="fm-preview-filename" id="fm-preview-filename"></span>
                        <button class="fm-btn-icon" id="fm-preview-next">
                            <i class="icon-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Hidden file input -->
            <input type="file" id="fm-file-input" multiple style="display: none;">
            <input type="file" id="fm-folder-input" webkitdirectory directory multiple style="display: none;">
        `;
    }

    initializeModules() {
        // Initialize sub-modules
        this.dragDropHandler = new DragDropHandler(this);
        this.contextMenu = new ContextMenu(this);
        this.fileUploader = new FileUploader(this);
        this.selectionHandler = new SelectionHandler(this);
    }

    bindEvents() {
        // View toggle
        document.getElementById('fm-view-grid').addEventListener('click', () => {
            this.setViewMode('grid');
        });

        document.getElementById('fm-view-list').addEventListener('click', () => {
            this.setViewMode('list');
        });

        // Upload button
        document.getElementById('fm-btn-upload').addEventListener('click', () => {
            document.getElementById('fm-file-input').click();
        });

        document.getElementById('fm-empty-upload').addEventListener('click', () => {
            document.getElementById('fm-file-input').click();
        });

        // File input change
        document.getElementById('fm-file-input').addEventListener('change', (e) => {
            this.handleFileSelect(e.target.files);
            e.target.value = '';
        });

        // New folder
        document.getElementById('fm-btn-new-folder').addEventListener('click', () => {
            this.createNewFolder();
        });

        // Toolbar buttons
        document.getElementById('fm-btn-cut').addEventListener('click', () => {
            this.cutSelectedItems();
        });

        document.getElementById('fm-btn-copy').addEventListener('click', () => {
            this.copySelectedItems();
        });

        document.getElementById('fm-btn-paste').addEventListener('click', () => {
            this.pasteItems();
        });

        document.getElementById('fm-btn-delete').addEventListener('click', () => {
            this.deleteSelectedItems();
        });

        // Search
        let searchTimeout;
        document.getElementById('fm-search-input').addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                this.handleSearch(e.target.value);
            }, 300);
        });

        // Sort
        document.getElementById('fm-sort-select').addEventListener('change', (e) => {
            this.setSortBy(e.target.value);
        });

        // Sidebar toggle
        document.getElementById('fm-toggle-sidebar').addEventListener('click', () => {
            this.toggleSidebar();
        });

        // File container events (delegation)
        const filesContainer = document.getElementById('fm-files-container');

        filesContainer.addEventListener('click', (e) => {
            const fileItem = e.target.closest('.fm-file-item');
            if (fileItem) {
                this.handleFileClick(e, fileItem);
            }
        });

        filesContainer.addEventListener('dblclick', (e) => {
            const fileItem = e.target.closest('.fm-file-item');
            if (fileItem) {
                this.handleFileDoubleClick(fileItem);
            }
        });

        // Breadcrumb navigation
        document.getElementById('fm-breadcrumb').addEventListener('click', (e) => {
            const item = e.target.closest('.fm-breadcrumb-item');
            if (item) {
                this.navigateToFolder(item.dataset.folder);
            }
        });

        // Preview modal
        document.getElementById('fm-close-preview').addEventListener('click', () => {
            this.closePreview();
        });

        document.getElementById('fm-preview-prev').addEventListener('click', () => {
            this.navigatePreview(-1);
        });

        document.getElementById('fm-preview-next').addEventListener('click', () => {
            this.navigatePreview(1);
        });

        // Details panel
        document.getElementById('fm-close-details').addEventListener('click', () => {
            this.closeDetailsPanel();
        });
    }

    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Skip if typing in input
            if (e.target.matches('input, textarea')) return;

            // Ctrl+A - Select all
            if (e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                this.selectAll();
            }

            // Delete - Delete selected
            if (e.key === 'Delete') {
                e.preventDefault();
                this.deleteSelectedItems();
            }

            // F2 - Rename
            if (e.key === 'F2') {
                e.preventDefault();
                this.renameSelectedItem();
            }

            // Ctrl+C - Copy
            if (e.ctrlKey && e.key === 'c') {
                e.preventDefault();
                this.copySelectedItems();
            }

            // Ctrl+X - Cut
            if (e.ctrlKey && e.key === 'x') {
                e.preventDefault();
                this.cutSelectedItems();
            }

            // Ctrl+V - Paste
            if (e.ctrlKey && e.key === 'v') {
                e.preventDefault();
                this.pasteItems();
            }

            // Ctrl+Z - Undo
            if (e.ctrlKey && e.key === 'z') {
                e.preventDefault();
                this.undo();
            }

            // Ctrl+Y - Redo
            if (e.ctrlKey && e.key === 'y') {
                e.preventDefault();
                this.redo();
            }

            // Escape - Clear selection
            if (e.key === 'Escape') {
                this.clearSelection();
            }
        });
    }

    async loadInitialData() {
        await this.loadFolderTree();
        await this.loadFiles(this.state.currentFolder || 'root');
    }

    async loadFolderTree() {
        try {
            const response = await this.apiCall('folders.php?action=tree');
            this.state.folderTree = response.data;
            this.renderFolderTree();
        } catch (error) {
            console.error('Failed to load folder tree:', error);
        }
    }

    renderFolderTree() {
        const container = document.getElementById('fm-folder-tree');
        container.innerHTML = this.renderFolderTreeItems(this.state.folderTree);

        // Bind folder tree events
        container.addEventListener('click', (e) => {
            const folder = e.target.closest('.fm-tree-item');
            if (folder) {
                const toggle = e.target.closest('.fm-tree-toggle');
                if (toggle) {
                    this.toggleFolderTree(folder);
                } else {
                    this.navigateToFolder(folder.dataset.folderId);
                }
            }
        });
    }

    renderFolderTreeItems(items, level = 0) {
        return items.map(item => `
            <div class="fm-tree-item" data-folder-id="${item.id}" style="padding-left: ${level * 20}px">
                ${item.children && item.children.length ? `
                    <span class="fm-tree-toggle">
                        <i class="icon-chevron-right"></i>
                    </span>
                ` : '<span class="fm-tree-spacer"></span>'}
                <i class="icon-folder"></i>
                <span class="fm-tree-name">${item.name}</span>
                ${item.children && item.children.length ? `
                    <div class="fm-tree-children" style="display: none;">
                        ${this.renderFolderTreeItems(item.children, level + 1)}
                    </div>
                ` : ''}
            </div>
        `).join('');
    }

    toggleFolderTree(folder) {
        const children = folder.querySelector('.fm-tree-children');
        const toggle = folder.querySelector('.fm-tree-toggle i');

        if (children) {
            if (children.style.display === 'none') {
                children.style.display = 'block';
                toggle.className = 'icon-chevron-down';
            } else {
                children.style.display = 'none';
                toggle.className = 'icon-chevron-right';
            }
        }
    }

    async loadFiles(folderId = 'root') {
        this.showLoading();

        try {
            const response = await this.apiCall(`files.php?folder=${folderId}`);
            this.state.files = response.files || [];
            this.state.folders = response.folders || [];
            this.state.currentFolder = folderId;

            this.renderFiles();
            this.updateBreadcrumb(response.path || []);
            this.hideLoading();

            // Add to history
            this.addToHistory(folderId);

        } catch (error) {
            console.error('Failed to load files:', error);
            this.hideLoading();
            this.showToast('Errore nel caricamento dei file', 'error');
        }
    }

    renderFiles() {
        const container = document.getElementById('fm-files-container');
        const items = [...this.state.folders, ...this.state.files];

        // Apply search filter
        const filteredItems = this.filterItems(items);

        // Apply sorting
        const sortedItems = this.sortItems(filteredItems);

        if (sortedItems.length === 0) {
            this.showEmptyState();
            return;
        }

        this.hideEmptyState();

        // Render based on view mode
        if (this.config.viewMode === 'grid') {
            container.innerHTML = this.renderGridView(sortedItems);
        } else {
            container.innerHTML = this.renderListView(sortedItems);
        }

        container.className = `fm-files-container fm-view-${this.config.viewMode}`;
    }

    renderGridView(items) {
        return items.map(item => `
            <div class="fm-file-item fm-grid-item ${item.type === 'folder' ? 'fm-folder' : 'fm-file'}"
                 data-id="${item.id}"
                 data-type="${item.type}"
                 data-name="${item.name}"
                 draggable="true">
                <div class="fm-file-icon">
                    ${this.getFileIcon(item)}
                </div>
                <div class="fm-file-name" title="${item.name}">
                    ${item.name}
                </div>
                <div class="fm-file-info">
                    ${item.type === 'file' ? this.formatFileSize(item.size) : `${item.itemCount || 0} elementi`}
                </div>
            </div>
        `).join('');
    }

    renderListView(items) {
        return `
            <div class="fm-list-header">
                <div class="fm-list-col fm-list-name">Nome</div>
                <div class="fm-list-col fm-list-size">Dimensione</div>
                <div class="fm-list-col fm-list-type">Tipo</div>
                <div class="fm-list-col fm-list-date">Modificato</div>
            </div>
            <div class="fm-list-body">
                ${items.map(item => `
                    <div class="fm-file-item fm-list-item ${item.type === 'folder' ? 'fm-folder' : 'fm-file'}"
                         data-id="${item.id}"
                         data-type="${item.type}"
                         data-name="${item.name}"
                         draggable="true">
                        <div class="fm-list-col fm-list-name">
                            ${this.getFileIcon(item)}
                            <span>${item.name}</span>
                        </div>
                        <div class="fm-list-col fm-list-size">
                            ${item.type === 'file' ? this.formatFileSize(item.size) : '-'}
                        </div>
                        <div class="fm-list-col fm-list-type">
                            ${item.type === 'file' ? item.extension || 'File' : 'Cartella'}
                        </div>
                        <div class="fm-list-col fm-list-date">
                            ${this.formatDate(item.modified)}
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
    }

    getFileIcon(item) {
        if (item.type === 'folder') {
            return '<i class="icon-folder"></i>';
        }

        // Check for image thumbnail
        if (item.thumbnail) {
            return `<img src="${item.thumbnail}" alt="${item.name}" class="fm-thumbnail">`;
        }

        // File type icons
        const ext = item.extension?.toLowerCase();
        const iconMap = {
            pdf: 'icon-file-pdf',
            doc: 'icon-file-doc',
            docx: 'icon-file-doc',
            xls: 'icon-file-xls',
            xlsx: 'icon-file-xls',
            ppt: 'icon-file-ppt',
            pptx: 'icon-file-ppt',
            zip: 'icon-file-zip',
            rar: 'icon-file-zip',
            mp3: 'icon-file-audio',
            mp4: 'icon-file-video',
            jpg: 'icon-file-image',
            jpeg: 'icon-file-image',
            png: 'icon-file-image',
            gif: 'icon-file-image',
            txt: 'icon-file-text',
            csv: 'icon-file-csv'
        };

        const iconClass = iconMap[ext] || 'icon-file';
        return `<i class="${iconClass}"></i>`;
    }

    filterItems(items) {
        if (!this.state.searchQuery) return items;

        const query = this.state.searchQuery.toLowerCase();
        return items.filter(item =>
            item.name.toLowerCase().includes(query)
        );
    }

    sortItems(items) {
        const { sortBy, sortOrder } = this.config;

        return items.sort((a, b) => {
            let compare = 0;

            // Folders first
            if (a.type === 'folder' && b.type !== 'folder') return -1;
            if (a.type !== 'folder' && b.type === 'folder') return 1;

            switch (sortBy) {
                case 'name':
                    compare = a.name.localeCompare(b.name);
                    break;
                case 'size':
                    compare = (a.size || 0) - (b.size || 0);
                    break;
                case 'date':
                    compare = new Date(a.modified) - new Date(b.modified);
                    break;
                case 'type':
                    compare = (a.extension || '').localeCompare(b.extension || '');
                    break;
            }

            return sortOrder === 'asc' ? compare : -compare;
        });
    }

    updateBreadcrumb(path) {
        const breadcrumb = document.getElementById('fm-breadcrumb');

        breadcrumb.innerHTML = `
            <span class="fm-breadcrumb-item" data-folder="root">
                <i class="icon-home"></i>
            </span>
            ${path.map(item => `
                <i class="icon-chevron-right fm-breadcrumb-separator"></i>
                <span class="fm-breadcrumb-item" data-folder="${item.id}">
                    ${item.name}
                </span>
            `).join('')}
        `;
    }

    handleFileClick(e, fileItem) {
        const id = fileItem.dataset.id;

        if (e.ctrlKey || e.metaKey) {
            // Toggle selection
            this.toggleItemSelection(id);
        } else if (e.shiftKey && this.state.selectedItems.size > 0) {
            // Range selection
            this.selectRange(id);
        } else {
            // Single selection
            this.clearSelection();
            this.selectItem(id);
        }

        this.updateSelectionUI();
    }

    handleFileDoubleClick(fileItem) {
        const id = fileItem.dataset.id;
        const type = fileItem.dataset.type;

        if (type === 'folder') {
            this.navigateToFolder(id);
        } else {
            const file = this.state.files.find(f => f.id === id);
            if (file && this.isImage(file)) {
                this.showPreview(file);
            } else {
                this.downloadFile(id);
            }
        }
    }

    toggleItemSelection(id) {
        if (this.state.selectedItems.has(id)) {
            this.state.selectedItems.delete(id);
        } else {
            this.state.selectedItems.add(id);
        }
    }

    selectItem(id) {
        this.state.selectedItems.add(id);
    }

    selectRange(endId) {
        const items = document.querySelectorAll('.fm-file-item');
        const startIdx = Array.from(items).findIndex(item =>
            this.state.selectedItems.has(item.dataset.id)
        );
        const endIdx = Array.from(items).findIndex(item =>
            item.dataset.id === endId
        );

        const [from, to] = [Math.min(startIdx, endIdx), Math.max(startIdx, endIdx)];

        for (let i = from; i <= to; i++) {
            this.state.selectedItems.add(items[i].dataset.id);
        }
    }

    selectAll() {
        const items = document.querySelectorAll('.fm-file-item');
        items.forEach(item => {
            this.state.selectedItems.add(item.dataset.id);
        });
        this.updateSelectionUI();
    }

    clearSelection() {
        this.state.selectedItems.clear();
        this.updateSelectionUI();
    }

    updateSelectionUI() {
        // Update item classes
        document.querySelectorAll('.fm-file-item').forEach(item => {
            if (this.state.selectedItems.has(item.dataset.id)) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
        });

        // Update toolbar buttons
        const hasSelection = this.state.selectedItems.size > 0;
        document.getElementById('fm-btn-cut').disabled = !hasSelection;
        document.getElementById('fm-btn-copy').disabled = !hasSelection;
        document.getElementById('fm-btn-delete').disabled = !hasSelection;
        document.getElementById('fm-btn-paste').disabled = !this.state.clipboard.items.length;
    }

    async createNewFolder() {
        const name = prompt('Nome della nuova cartella:');
        if (!name) return;

        try {
            const response = await this.apiCall('folders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create',
                    parent: this.state.currentFolder,
                    name: name
                })
            });

            if (response.success) {
                await this.loadFiles(this.state.currentFolder);
                this.showToast('Cartella creata con successo', 'success');
            }
        } catch (error) {
            this.showToast('Errore nella creazione della cartella', 'error');
        }
    }

    cutSelectedItems() {
        this.state.clipboard = {
            items: Array.from(this.state.selectedItems),
            operation: 'cut'
        };
        this.updateSelectionUI();
        this.showToast(`${this.state.selectedItems.size} elementi tagliati`, 'info');
    }

    copySelectedItems() {
        this.state.clipboard = {
            items: Array.from(this.state.selectedItems),
            operation: 'copy'
        };
        this.updateSelectionUI();
        this.showToast(`${this.state.selectedItems.size} elementi copiati`, 'info');
    }

    async pasteItems() {
        if (!this.state.clipboard.items.length) return;

        const { items, operation } = this.state.clipboard;

        try {
            const response = await this.apiCall('files.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: operation === 'cut' ? 'move' : 'copy',
                    items: items,
                    destination: this.state.currentFolder
                })
            });

            if (response.success) {
                if (operation === 'cut') {
                    this.state.clipboard = { items: [], operation: null };
                }
                await this.loadFiles(this.state.currentFolder);
                this.showToast('Elementi incollati con successo', 'success');
            }
        } catch (error) {
            this.showToast('Errore nell\'incollare gli elementi', 'error');
        }
    }

    async deleteSelectedItems() {
        if (!this.state.selectedItems.size) return;

        if (!confirm(`Eliminare ${this.state.selectedItems.size} elementi?`)) return;

        try {
            const response = await this.apiCall('files.php', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    items: Array.from(this.state.selectedItems)
                })
            });

            if (response.success) {
                this.clearSelection();
                await this.loadFiles(this.state.currentFolder);
                this.showToast('Elementi eliminati con successo', 'success');
            }
        } catch (error) {
            this.showToast('Errore nell\'eliminazione', 'error');
        }
    }

    async renameSelectedItem() {
        if (this.state.selectedItems.size !== 1) return;

        const id = Array.from(this.state.selectedItems)[0];
        const item = document.querySelector(`[data-id="${id}"]`);
        const oldName = item.dataset.name;

        const newName = prompt('Nuovo nome:', oldName);
        if (!newName || newName === oldName) return;

        try {
            const response = await this.apiCall('files.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'rename',
                    id: id,
                    name: newName
                })
            });

            if (response.success) {
                await this.loadFiles(this.state.currentFolder);
                this.showToast('Rinominato con successo', 'success');
            }
        } catch (error) {
            this.showToast('Errore nella rinomina', 'error');
        }
    }

    async downloadFile(id) {
        try {
            const response = await fetch(`${this.config.apiBase}files.php?action=download&id=${id}`);
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = response.headers.get('Content-Disposition')?.split('filename=')[1] || 'download';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        } catch (error) {
            this.showToast('Errore nel download', 'error');
        }
    }

    navigateToFolder(folderId) {
        this.loadFiles(folderId);
    }

    handleFileSelect(files) {
        if (files.length === 0) return;
        this.fileUploader.addFiles(files);
    }

    handleSearch(query) {
        this.state.searchQuery = query;
        this.renderFiles();
    }

    setViewMode(mode) {
        this.config.viewMode = mode;
        localStorage.setItem('fm_viewMode', mode);
        this.renderFiles();

        // Update button states
        document.querySelectorAll('.fm-view-toggle button').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === mode);
        });
    }

    setSortBy(sortBy) {
        this.config.sortBy = sortBy;
        localStorage.setItem('fm_sortBy', sortBy);
        this.renderFiles();
    }

    toggleSidebar() {
        const sidebar = document.getElementById('fm-sidebar');
        sidebar.classList.toggle('collapsed');
    }

    showPreview(file) {
        const modal = document.getElementById('fm-preview-modal');
        const container = document.getElementById('fm-preview-container');
        const filename = document.getElementById('fm-preview-filename');

        container.innerHTML = `<img src="${file.url}" alt="${file.name}">`;
        filename.textContent = file.name;
        modal.style.display = 'flex';

        this.state.previewFile = file;
    }

    closePreview() {
        document.getElementById('fm-preview-modal').style.display = 'none';
    }

    navigatePreview(direction) {
        const images = this.state.files.filter(f => this.isImage(f));
        const currentIndex = images.findIndex(f => f.id === this.state.previewFile.id);
        const newIndex = (currentIndex + direction + images.length) % images.length;
        this.showPreview(images[newIndex]);
    }

    closeDetailsPanel() {
        document.getElementById('fm-details').style.display = 'none';
    }

    showLoading() {
        document.getElementById('fm-loading').style.display = 'flex';
        document.getElementById('fm-files-container').style.display = 'none';
    }

    hideLoading() {
        document.getElementById('fm-loading').style.display = 'none';
        document.getElementById('fm-files-container').style.display = '';
    }

    showEmptyState() {
        document.getElementById('fm-empty-state').style.display = 'flex';
        document.getElementById('fm-files-container').style.display = 'none';
    }

    hideEmptyState() {
        document.getElementById('fm-empty-state').style.display = 'none';
        document.getElementById('fm-files-container').style.display = '';
    }

    addToHistory(folderId) {
        // Remove future history if navigating from middle
        if (this.state.historyIndex < this.state.history.length - 1) {
            this.state.history = this.state.history.slice(0, this.state.historyIndex + 1);
        }

        this.state.history.push(folderId);
        this.state.historyIndex = this.state.history.length - 1;
    }

    undo() {
        if (this.state.historyIndex > 0) {
            this.state.historyIndex--;
            this.loadFiles(this.state.history[this.state.historyIndex]);
        }
    }

    redo() {
        if (this.state.historyIndex < this.state.history.length - 1) {
            this.state.historyIndex++;
            this.loadFiles(this.state.history[this.state.historyIndex]);
        }
    }

    isImage(file) {
        const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'];
        return imageExtensions.includes(file.extension?.toLowerCase());
    }

    formatFileSize(bytes) {
        if (!bytes) return '0 B';
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
    }

    formatDate(date) {
        if (!date) return '-';
        const d = new Date(date);
        return d.toLocaleDateString('it-IT') + ' ' + d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    }

    showToast(message, type = 'info') {
        // Create toast element
        const toast = document.createElement('div');
        toast.className = `fm-toast fm-toast-${type}`;
        toast.textContent = message;
        document.body.appendChild(toast);

        // Animate in
        setTimeout(() => toast.classList.add('show'), 10);

        // Remove after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    async apiCall(endpoint, options = {}) {
        try {
            const response = await fetch(this.config.apiBase + endpoint, {
                credentials: 'same-origin',
                ...options
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    }
}

// Drag and Drop Handler
class DragDropHandler {
    constructor(fileManager) {
        this.fm = fileManager;
        this.draggedItems = null;
        this.dropTarget = null;
        this.init();
    }

    init() {
        const container = this.fm.container;
        const dropzone = container.querySelector('#fm-dropzone');
        const filesContainer = container.querySelector('#fm-files-container');

        // Dropzone events
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, this.preventDefaults, false);
            document.body.addEventListener(eventName, this.preventDefaults, false);
        });

        // Highlight drop zone
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => this.highlight(dropzone), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => this.unhighlight(dropzone), false);
        });

        // Handle drop
        dropzone.addEventListener('drop', (e) => this.handleDrop(e), false);

        // File items drag
        filesContainer.addEventListener('dragstart', (e) => this.handleDragStart(e), false);
        filesContainer.addEventListener('dragend', (e) => this.handleDragEnd(e), false);
        filesContainer.addEventListener('dragover', (e) => this.handleDragOver(e), false);
        filesContainer.addEventListener('drop', (e) => this.handleItemDrop(e), false);
    }

    preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }

    highlight(element) {
        element.classList.add('highlight');
    }

    unhighlight(element) {
        element.classList.remove('highlight');
    }

    handleDragStart(e) {
        const item = e.target.closest('.fm-file-item');
        if (!item) return;

        // Store dragged items
        if (this.fm.state.selectedItems.has(item.dataset.id)) {
            this.draggedItems = Array.from(this.fm.state.selectedItems);
        } else {
            this.draggedItems = [item.dataset.id];
        }

        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', JSON.stringify(this.draggedItems));

        item.classList.add('dragging');
    }

    handleDragEnd(e) {
        const item = e.target.closest('.fm-file-item');
        if (item) {
            item.classList.remove('dragging');
        }

        // Clean up
        document.querySelectorAll('.drop-target').forEach(el => {
            el.classList.remove('drop-target');
        });
    }

    handleDragOver(e) {
        if (e.preventDefault) {
            e.preventDefault();
        }

        e.dataTransfer.dropEffect = 'move';

        const item = e.target.closest('.fm-folder');
        if (item && !item.classList.contains('dragging')) {
            // Highlight folder as drop target
            document.querySelectorAll('.drop-target').forEach(el => {
                el.classList.remove('drop-target');
            });
            item.classList.add('drop-target');
            this.dropTarget = item.dataset.id;
        }
    }

    async handleItemDrop(e) {
        e.preventDefault();

        const item = e.target.closest('.fm-folder');
        if (!item || !this.draggedItems) return;

        const targetId = item.dataset.id;

        try {
            const response = await this.fm.apiCall('files.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'move',
                    items: this.draggedItems,
                    destination: targetId
                })
            });

            if (response.success) {
                await this.fm.loadFiles(this.fm.state.currentFolder);
                this.fm.showToast('File spostati con successo', 'success');
            }
        } catch (error) {
            this.fm.showToast('Errore nello spostamento dei file', 'error');
        }

        this.draggedItems = null;
        this.dropTarget = null;
    }

    handleDrop(e) {
        e.preventDefault();

        const dt = e.dataTransfer;
        const files = dt.files;

        if (files.length > 0) {
            this.fm.handleFileSelect(files);
        }
    }
}

// Context Menu Handler
class ContextMenu {
    constructor(fileManager) {
        this.fm = fileManager;
        this.menu = null;
        this.targetItem = null;
        this.init();
    }

    init() {
        this.menu = document.getElementById('fm-context-menu');

        // Bind context menu to file items
        this.fm.container.addEventListener('contextmenu', (e) => {
            const item = e.target.closest('.fm-file-item');
            if (item) {
                e.preventDefault();
                this.show(e, item);
            }
        });

        // Hide on click outside
        document.addEventListener('click', () => this.hide());

        // Handle menu actions
        this.menu.addEventListener('click', (e) => {
            const menuItem = e.target.closest('.fm-menu-item');
            if (menuItem) {
                this.handleAction(menuItem.dataset.action);
            }
        });
    }

    show(e, item) {
        this.targetItem = item;

        // Position menu
        this.menu.style.left = `${e.pageX}px`;
        this.menu.style.top = `${e.pageY}px`;
        this.menu.style.display = 'block';

        // Adjust position if menu goes outside viewport
        const rect = this.menu.getBoundingClientRect();
        if (rect.right > window.innerWidth) {
            this.menu.style.left = `${e.pageX - rect.width}px`;
        }
        if (rect.bottom > window.innerHeight) {
            this.menu.style.top = `${e.pageY - rect.height}px`;
        }

        // Update menu items based on context
        this.updateMenuItems();
    }

    hide() {
        this.menu.style.display = 'none';
        this.targetItem = null;
    }

    updateMenuItems() {
        const isFolder = this.targetItem.dataset.type === 'folder';
        const hasClipboard = this.fm.state.clipboard.items.length > 0;

        // Show/hide relevant menu items
        this.menu.querySelector('[data-action="open"]').style.display = isFolder ? '' : 'none';
        this.menu.querySelector('[data-action="download"]').style.display = isFolder ? 'none' : '';
        this.menu.querySelector('[data-action="paste"]').style.display = hasClipboard ? '' : 'none';
    }

    handleAction(action) {
        const id = this.targetItem.dataset.id;

        // Select item if not selected
        if (!this.fm.state.selectedItems.has(id)) {
            this.fm.clearSelection();
            this.fm.selectItem(id);
            this.fm.updateSelectionUI();
        }

        switch (action) {
            case 'open':
                if (this.targetItem.dataset.type === 'folder') {
                    this.fm.navigateToFolder(id);
                }
                break;
            case 'download':
                this.fm.downloadFile(id);
                break;
            case 'cut':
                this.fm.cutSelectedItems();
                break;
            case 'copy':
                this.fm.copySelectedItems();
                break;
            case 'paste':
                this.fm.pasteItems();
                break;
            case 'rename':
                this.fm.renameSelectedItem();
                break;
            case 'delete':
                this.fm.deleteSelectedItems();
                break;
            case 'properties':
                this.showProperties(id);
                break;
        }

        this.hide();
    }

    showProperties(id) {
        const details = document.getElementById('fm-details');
        const content = document.getElementById('fm-details-content');

        // Find item data
        const item = [...this.fm.state.files, ...this.fm.state.folders].find(i => i.id === id);
        if (!item) return;

        // Render details
        content.innerHTML = `
            <div class="fm-detail-item">
                <div class="fm-detail-preview">
                    ${this.fm.getFileIcon(item)}
                </div>
            </div>
            <div class="fm-detail-item">
                <label>Nome</label>
                <span>${item.name}</span>
            </div>
            <div class="fm-detail-item">
                <label>Tipo</label>
                <span>${item.type === 'folder' ? 'Cartella' : item.extension || 'File'}</span>
            </div>
            ${item.size ? `
                <div class="fm-detail-item">
                    <label>Dimensione</label>
                    <span>${this.fm.formatFileSize(item.size)}</span>
                </div>
            ` : ''}
            <div class="fm-detail-item">
                <label>Modificato</label>
                <span>${this.fm.formatDate(item.modified)}</span>
            </div>
            <div class="fm-detail-item">
                <label>Creato</label>
                <span>${this.fm.formatDate(item.created)}</span>
            </div>
        `;

        details.style.display = 'block';
    }
}

// File Uploader Handler
class FileUploader {
    constructor(fileManager) {
        this.fm = fileManager;
        this.uploadQueue = [];
        this.uploading = false;
        this.currentUpload = null;
        this.init();
    }

    init() {
        this.progressContainer = document.getElementById('fm-upload-progress');
        this.progressList = document.getElementById('fm-upload-list');
    }

    addFiles(files) {
        Array.from(files).forEach(file => {
            const uploadItem = {
                id: Date.now() + Math.random(),
                file: file,
                progress: 0,
                status: 'pending',
                error: null
            };

            this.uploadQueue.push(uploadItem);
            this.renderUploadItem(uploadItem);
        });

        this.showProgress();
        this.processQueue();
    }

    async processQueue() {
        if (this.uploading || this.uploadQueue.length === 0) return;

        const pending = this.uploadQueue.filter(item => item.status === 'pending');
        if (pending.length === 0) return;

        const concurrent = Math.min(pending.length, this.fm.config.maxConcurrentUploads);

        for (let i = 0; i < concurrent; i++) {
            this.uploadFile(pending[i]);
        }
    }

    async uploadFile(uploadItem) {
        uploadItem.status = 'uploading';
        this.updateUploadItem(uploadItem);

        const formData = new FormData();
        formData.append('file', uploadItem.file);
        formData.append('folder', this.fm.state.currentFolder);

        try {
            const xhr = new XMLHttpRequest();

            // Progress tracking
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    uploadItem.progress = (e.loaded / e.total) * 100;
                    this.updateUploadItem(uploadItem);
                }
            });

            // Completion
            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    uploadItem.status = 'completed';
                    uploadItem.progress = 100;
                    this.updateUploadItem(uploadItem);

                    // Reload files when all uploads complete
                    const allComplete = this.uploadQueue.every(item =>
                        item.status === 'completed' || item.status === 'error'
                    );

                    if (allComplete) {
                        this.fm.loadFiles(this.fm.state.currentFolder);
                        setTimeout(() => this.hideProgress(), 2000);
                    }
                } else {
                    throw new Error(`Upload failed: ${xhr.status}`);
                }

                this.processQueue();
            });

            xhr.addEventListener('error', () => {
                uploadItem.status = 'error';
                uploadItem.error = 'Upload failed';
                this.updateUploadItem(uploadItem);
                this.processQueue();
            });

            xhr.open('POST', `${this.fm.config.apiBase}upload.php`);
            xhr.send(formData);

        } catch (error) {
            uploadItem.status = 'error';
            uploadItem.error = error.message;
            this.updateUploadItem(uploadItem);
            this.processQueue();
        }
    }

    renderUploadItem(uploadItem) {
        const itemHtml = `
            <div class="fm-upload-item" data-upload-id="${uploadItem.id}">
                <div class="fm-upload-info">
                    <span class="fm-upload-name">${uploadItem.file.name}</span>
                    <span class="fm-upload-size">${this.fm.formatFileSize(uploadItem.file.size)}</span>
                    <span class="fm-upload-status">${this.getStatusText(uploadItem.status)}</span>
                </div>
                <div class="fm-upload-progress">
                    <div class="fm-upload-bar" style="width: ${uploadItem.progress}%"></div>
                </div>
                ${uploadItem.error ? `<div class="fm-upload-error">${uploadItem.error}</div>` : ''}
            </div>
        `;

        this.progressList.insertAdjacentHTML('beforeend', itemHtml);
    }

    updateUploadItem(uploadItem) {
        const element = this.progressList.querySelector(`[data-upload-id="${uploadItem.id}"]`);
        if (!element) return;

        const bar = element.querySelector('.fm-upload-bar');
        const status = element.querySelector('.fm-upload-status');

        bar.style.width = `${uploadItem.progress}%`;
        status.textContent = this.getStatusText(uploadItem.status);

        if (uploadItem.status === 'completed') {
            element.classList.add('completed');
        } else if (uploadItem.status === 'error') {
            element.classList.add('error');
        }
    }

    getStatusText(status) {
        const texts = {
            pending: 'In attesa',
            uploading: 'Caricamento...',
            completed: 'Completato',
            error: 'Errore'
        };
        return texts[status] || status;
    }

    showProgress() {
        this.progressContainer.style.display = 'block';
    }

    hideProgress() {
        this.progressContainer.style.display = 'none';
        this.uploadQueue = [];
        this.progressList.innerHTML = '';
    }
}

// Selection Handler for advanced multi-select
class SelectionHandler {
    constructor(fileManager) {
        this.fm = fileManager;
        this.selectionBox = null;
        this.startPoint = null;
        this.isSelecting = false;
        this.init();
    }

    init() {
        const container = document.getElementById('fm-files-container');

        container.addEventListener('mousedown', (e) => {
            // Only start selection on empty space
            if (e.target === container || e.target.classList.contains('fm-list-body')) {
                this.startSelection(e);
            }
        });

        document.addEventListener('mousemove', (e) => {
            if (this.isSelecting) {
                this.updateSelection(e);
            }
        });

        document.addEventListener('mouseup', () => {
            if (this.isSelecting) {
                this.endSelection();
            }
        });
    }

    startSelection(e) {
        this.isSelecting = true;
        this.startPoint = { x: e.pageX, y: e.pageY };

        // Create selection box
        this.selectionBox = document.createElement('div');
        this.selectionBox.className = 'fm-selection-box';
        this.selectionBox.style.left = `${e.pageX}px`;
        this.selectionBox.style.top = `${e.pageY}px`;
        document.body.appendChild(this.selectionBox);

        // Clear previous selection if not holding Ctrl
        if (!e.ctrlKey && !e.metaKey) {
            this.fm.clearSelection();
        }
    }

    updateSelection(e) {
        const currentPoint = { x: e.pageX, y: e.pageY };

        // Update selection box
        const left = Math.min(this.startPoint.x, currentPoint.x);
        const top = Math.min(this.startPoint.y, currentPoint.y);
        const width = Math.abs(currentPoint.x - this.startPoint.x);
        const height = Math.abs(currentPoint.y - this.startPoint.y);

        this.selectionBox.style.left = `${left}px`;
        this.selectionBox.style.top = `${top}px`;
        this.selectionBox.style.width = `${width}px`;
        this.selectionBox.style.height = `${height}px`;

        // Check for intersecting items
        this.selectIntersectingItems();
    }

    endSelection() {
        this.isSelecting = false;

        if (this.selectionBox) {
            this.selectionBox.remove();
            this.selectionBox = null;
        }

        this.fm.updateSelectionUI();
    }

    selectIntersectingItems() {
        const boxRect = this.selectionBox.getBoundingClientRect();
        const items = document.querySelectorAll('.fm-file-item');

        items.forEach(item => {
            const itemRect = item.getBoundingClientRect();

            if (this.isIntersecting(boxRect, itemRect)) {
                this.fm.state.selectedItems.add(item.dataset.id);
                item.classList.add('selecting');
            } else if (!this.fm.state.selectedItems.has(item.dataset.id)) {
                item.classList.remove('selecting');
            }
        });
    }

    isIntersecting(rect1, rect2) {
        return !(rect1.right < rect2.left ||
                rect1.left > rect2.right ||
                rect1.bottom < rect2.top ||
                rect1.top > rect2.bottom);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Check if container exists
    const container = document.getElementById('filemanager-container');
    if (container) {
        window.fileManager = new FileManager('#filemanager-container');
    }
});