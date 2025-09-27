(function() {
    'use strict';

    /**
     * Professional File Manager Module
     * Modern cloud storage interface with advanced features
     */
    class FileManager {
    constructor() {
        this.config = {
            apiBase: '/CollaboraNexio/api/files.php',
            pollInterval: 5000,
            maxFileSize: 100 * 1024 * 1024, // 100MB
            allowedExtensions: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'mp4', 'mp3', 'zip', 'rar']
        };
        this.state = {
            currentPath: '/',
            currentView: 'grid',
            selectedFiles: new Set(),
            uploadQueue: [],
            sortBy: 'name',
            filterType: 'all',
            searchQuery: ''
        };
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadInitialData();
        this.setupDragAndDrop();
        this.initContextMenu();
        this.initKeyboardShortcuts();
    }

    bindEvents() {
        // Search functionality
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

        // Filter and Sort
        document.getElementById('filterBtn')?.addEventListener('click', () => {
            this.showFilterMenu();
        });

        document.getElementById('sortBtn')?.addEventListener('click', () => {
            this.showSortMenu();
        });

        // View toggle
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', (e) => this.handleViewChange(e));
        });

        // File selection and actions
        this.setupFileSelection();

        // Upload button
        document.getElementById('uploadBtn')?.addEventListener('click', () => {
            this.showUploadDialog();
        });

        // New folder button
        document.getElementById('newFolderBtn')?.addEventListener('click', () => {
            this.createNewFolder();
        });

        // Breadcrumb navigation
        document.querySelector('.breadcrumb-items')?.addEventListener('click', (e) => {
            const item = e.target.closest('.breadcrumb-item');
            if (item && item.dataset.path) {
                e.preventDefault();
                this.navigateToPath(item.dataset.path);
            }
        });

        // Details sidebar
        document.getElementById('closeDetails')?.addEventListener('click', () => {
            this.hideDetailsSidebar();
        });

        // Upload toast close
        document.getElementById('closeUpload')?.addEventListener('click', () => {
            this.hideUploadToast();
        });

        // Select all checkbox
        document.getElementById('selectAll')?.addEventListener('change', (e) => {
            this.handleSelectAll(e.target.checked);
        });

        // Sidebar toggle
        document.getElementById('sidebarToggle')?.addEventListener('click', () => {
            document.querySelector('.sidebar')?.classList.toggle('collapsed');
        });
    }

    setupFileSelection() {
        // Grid view file selection
        document.getElementById('filesGrid')?.addEventListener('click', (e) => {
            const fileCard = e.target.closest('.file-card');
            const actionBtn = e.target.closest('.action-btn');

            if (actionBtn) {
                e.stopPropagation();
                this.handleFileAction(actionBtn, fileCard);
            } else if (fileCard) {
                e.preventDefault(); // Prevent default behavior
                this.handleFileClick(fileCard, e);
            }
        });

        // List view file selection
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
        });

        filesWrapper.addEventListener('drop', (e) => {
            e.preventDefault();
            dragCounter = 0;
            if (dropZone) {
                dropZone.classList.remove('active');
            }
            this.handleFileDrop(e.dataTransfer.files);
        });

        // Click on drop zone to upload
        dropZone?.addEventListener('click', () => {
            this.showUploadDialog();
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
            // Ctrl/Cmd + A - Select all
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                this.handleSelectAll(true);
            }

            // Delete key - Delete selected
            if (e.key === 'Delete' && this.state.selectedFiles.size > 0) {
                e.preventDefault();
                this.deleteSelected();
            }

            // Escape - Clear selection
            if (e.key === 'Escape') {
                this.clearSelection();
                this.hideDetailsSidebar();
            }

            // Ctrl/Cmd + U - Upload
            if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
                e.preventDefault();
                this.showUploadDialog();
            }

            // Ctrl/Cmd + Shift + N - New folder
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'N') {
                e.preventDefault();
                this.createNewFolder();
            }
        });
    }

    handleViewChange(e) {
        const btn = e.currentTarget;
        const view = btn.dataset.view;

        // Update button states
        document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // Update view
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
            // Multi-select
            this.toggleFileSelection(element, fileName);
        } else if (e.shiftKey && this.state.selectedFiles.size > 0) {
            // Range select
            this.selectRange(element);
        } else {
            // Single select
            this.clearSelection();
            this.selectFile(element, fileName);

            // Show details sidebar for files (not folders)
            if (!isFolder) {
                console.log('Showing details sidebar for file:', fileName);
                this.showDetailsSidebar(element);
            }
        }
    }

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

    selectRange(endElement) {
        // Get all file elements
        const allElements = Array.from(document.querySelectorAll('.file-card, .file-row'));

        // Find the last selected element
        const lastSelected = Array.from(this.state.selectedFiles).pop();
        if (!lastSelected) return;

        const startElement = document.querySelector(`[data-name="${lastSelected}"]`);
        if (!startElement) return;

        const startIndex = allElements.indexOf(startElement);
        const endIndex = allElements.indexOf(endElement);

        if (startIndex === -1 || endIndex === -1) return;

        // Clear current selection
        this.clearSelection();

        // Select all elements in range
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

    clearSelection() {
        document.querySelectorAll('.file-card.selected, .file-row.selected').forEach(el => {
            el.classList.remove('selected');
        });
        this.state.selectedFiles.clear();
        this.updateSelectionCount();
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

    openFile(element) {
        const fileName = element.dataset.name;
        const isFolder = element.dataset.type === 'folder';

        if (isFolder) {
            this.navigateToFolder(fileName);
        } else {
            console.log('Apertura file:', fileName);
            this.showToast(`Apertura di ${fileName}...`, 'info');
            // In a real app, open file preview or download
        }
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
            default:
                console.log('Unknown action:', action);
        }
    }

    showContextMenu(x, y, fileElement) {
        const contextMenu = document.getElementById('contextMenu');
        if (!contextMenu) return;

        // Store the context file
        this.contextFile = fileElement;

        // Position the menu
        contextMenu.style.left = `${x}px`;
        contextMenu.style.top = `${y}px`;

        // Show the menu
        contextMenu.classList.add('active');

        // Adjust position if menu goes off-screen
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

    showDetailsSidebar(fileElement) {
        const sidebar = document.getElementById('fileDetailsSidebar');
        const mainContainer = document.querySelector('.file-main-container');

        if (!sidebar) {
            console.error('Sidebar element not found!');
            return;
        }

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
        if (ownerValue) ownerValue.textContent = 'You';

        // Update preview based on file type
        this.updateFilePreview(sidebar, fileElement);

        // Show sidebar and adjust main container
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

        // Show empty state if no files
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
        // Create filter dropdown
        const filterOptions = ['Tutti i File', 'Documenti', 'Immagini', 'Video', 'Cartelle'];
        this.showDropdownMenu('filter', filterOptions, (option) => {
            this.state.filterType = option.toLowerCase().replace(' files', '').replace('s', '');
            this.filterFiles();
        });
    }

    showSortMenu() {
        // Create sort dropdown
        const sortOptions = ['Nome', 'Data Modifica', 'Dimensione', 'Tipo'];
        this.showDropdownMenu('sort', sortOptions, (option) => {
            this.state.sortBy = option.toLowerCase().replace(' ', '-');
            this.sortFiles();
        });
    }

    showDropdownMenu(type, options, callback) {
        // Create dropdown menu (simplified for demo)
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

        // Remove menu on click outside
        setTimeout(() => {
            document.addEventListener('click', function removeMenu() {
                menu.remove();
                document.removeEventListener('click', removeMenu);
            }, { once: true });
        }, 0);
    }

    showFileMenu(btn, fileElement) {
        // Get button position for menu placement
        const rect = btn.getBoundingClientRect();
        const fileName = fileElement.dataset.name;
        const fileType = fileElement.dataset.type;

        // Create menu container
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

        // Define menu options based on file type
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

        // Create menu items
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

        // Add menu to body
        document.body.appendChild(menu);

        // Adjust position if menu goes off screen
        const menuRect = menu.getBoundingClientRect();
        if (menuRect.right > window.innerWidth) {
            menu.style.left = `${window.innerWidth - menuRect.width - 10}px`;
        }
        if (menuRect.bottom > window.innerHeight) {
            menu.style.top = `${rect.top - menuRect.height - 5}px`;
        }

        // Remove menu on click outside
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
                this.openFile(fileElement);
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
                // Implement move functionality
                break;
            case 'details':
                // Show details sidebar
                this.clearSelection();
                this.selectFile(fileElement, fileName);
                this.showDetailsSidebar(fileElement);
                break;
            case 'delete':
                this.deleteFile(fileName);
                break;
            default:
                console.log('Unknown action:', action);
        }
    }

    sortFiles() {
        console.log('Sorting files by:', this.state.sortBy);
        // Implement sorting logic here
    }

    navigateToFolder(folderName) {
        this.state.currentPath = this.state.currentPath + folderName + '/';
        this.loadFiles();
        this.updateBreadcrumb();
        this.showToast(`Aperta cartella: ${folderName}`, 'success');
    }

    navigateToPath(path) {
        this.state.currentPath = path;
        this.loadFiles();
        this.updateBreadcrumb();
    }

    updateBreadcrumb() {
        const breadcrumbItems = document.querySelector('.breadcrumb-items');
        if (!breadcrumbItems) return;

        const pathParts = this.state.currentPath.split('/').filter(p => p);

        // Clear current breadcrumb
        while (breadcrumbItems.children.length > 2) {
            breadcrumbItems.removeChild(breadcrumbItems.lastChild);
        }

        // Update current path display
        const current = document.querySelector('.breadcrumb-current');
        if (current) {
            current.textContent = pathParts.length > 0 ? pathParts[pathParts.length - 1] : 'I Miei File';
        }
    }

    async loadFiles() {
        console.log('Loading files for path:', this.state.currentPath);

        try {
            const params = new URLSearchParams({
                folder_id: this.state.currentFolderId || '',
                search: this.state.searchQuery || '',
                sort: this.state.sortBy || 'name',
                order: 'ASC'
            });

            const response = await fetch(`${this.config.apiBase}?${params}`, {
                method: 'GET',
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Failed to load files');
            }

            const result = await response.json();

            if (result.success) {
                this.renderFiles(result.data);
            } else {
                throw new Error(result.error || 'Failed to load files');
            }
        } catch (error) {
            console.error('Errore nel caricamento dei file:', error);
            this.showToast('Errore nel caricamento dei file', 'error');
        }

        // Maintain existing animation
        const filesGrid = document.getElementById('filesGrid');
        if (filesGrid) {
            filesGrid.style.opacity = '0.5';
            setTimeout(() => {
                filesGrid.style.opacity = '1';
            }, 300);
        }
    }

    async loadInitialData() {
        try {
            console.log('Caricamento file iniziali...');
            this.updateBreadcrumb();
            // Carica automaticamente i file dal database
            await this.loadFiles();
        } catch (error) {
            console.error('Errore nel caricamento dei file:', error);
            this.showToast('Errore nel caricamento dei file', 'error');
        }
    }

    showUploadDialog() {
        const input = document.createElement('input');
        input.type = 'file';
        input.multiple = true;
        input.accept = this.config.allowedExtensions.map(ext => `.${ext}`).join(',');
        input.onchange = (e) => {
            this.handleFileUpload(e.target.files);
        };
        input.click();
    }

    handleFileUpload(files) {
        if (files.length === 0) return;

        // Validate files
        const validFiles = Array.from(files).filter(file => {
            if (file.size > this.config.maxFileSize) {
                this.showToast(`${file.name} è troppo grande (max 100MB)`, 'error');
                return false;
            }
            return true;
        });

        if (validFiles.length === 0) return;

        this.showUploadToast(validFiles.length);

        validFiles.forEach(file => {
            this.uploadFile(file);
        });
    }

    handleFileDrop(files) {
        this.handleFileUpload(files);
    }

    uploadFile(file) {
        const uploadItems = document.getElementById('uploadItems');
        if (!uploadItems) return;

        const uploadItem = this.createUploadItem(file);
        uploadItems.appendChild(uploadItem);

        // Simulate upload progress
        let progress = 0;
        const progressBar = uploadItem.querySelector('.progress-fill');
        const interval = setInterval(() => {
            progress += Math.random() * 30;
            if (progress >= 100) {
                progress = 100;
                clearInterval(interval);
                uploadItem.classList.add('complete');
                this.showToast(`${file.name} caricato con successo`, 'success');

                // Add file to the grid
                this.addFileToGrid(file);
            }
            if (progressBar) {
                progressBar.style.width = `${progress}%`;
            }
        }, 500);
    }

    createUploadItem(file) {
        const div = document.createElement('div');
        div.className = 'upload-item';
        div.innerHTML = `
            <div class="upload-file-info">
                <span class="file-name">${file.name}</span>
                <span class="file-size">${this.formatFileSize(file.size)}</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill"></div>
            </div>
        `;
        return div;
    }

    addFileToGrid(file) {
        const filesGrid = document.getElementById('filesGrid');
        if (!filesGrid) return;

        const fileType = this.getFileType(file.name);
        const fileCard = document.createElement('div');
        fileCard.className = 'file-card';
        fileCard.dataset.name = file.name;
        fileCard.dataset.type = fileType;

        fileCard.innerHTML = `
            <div class="file-card-icon ${fileType}">
                ${this.getFileIcon(fileType)}
            </div>
            <div class="file-card-info">
                <h4 class="file-name">${file.name}</h4>
                <span class="file-meta">${this.formatFileSize(file.size)} · Modificato ora</span>
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
        fileCard.style.animation = 'fadeIn 0.3s';
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
            zip: 'archive', rar: 'archive'
        };
        return typeMap[ext] || 'file';
    }

    getFileIcon(type) {
        const icons = {
            pdf: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#EF4444"/></svg>',
            word: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#2563EB"/></svg>',
            excel: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#10B981"/></svg>',
            powerpoint: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#F59E0B"/></svg>',
            image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
            folder: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2h-8l-2-2z" fill="#F59E0B"/></svg>'
        };
        return icons[type] || '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>';
    }

    showUploadToast(fileCount) {
        const uploadToast = document.getElementById('uploadToast');
        if (uploadToast) {
            uploadToast.classList.remove('hidden');
            const header = uploadToast.querySelector('.upload-toast-header span');
            if (header) {
                header.textContent = `Caricamento di ${fileCount} file${fileCount > 1 ? '' : ''} in corso...`;
            }
        }
    }

    hideUploadToast() {
        const uploadToast = document.getElementById('uploadToast');
        if (uploadToast) {
            uploadToast.classList.add('hidden');
            // Clear upload items
            const uploadItems = document.getElementById('uploadItems');
            if (uploadItems) {
                uploadItems.innerHTML = '';
            }
        }
    }

    createNewFolder() {
        const folderName = prompt('Inserisci il nome della cartella:');
        if (folderName && folderName.trim()) {
            console.log('Creating folder:', folderName);
            this.showToast(`Cartella "${folderName}" creata`, 'success');

            // Add to grid
            const filesGrid = document.getElementById('filesGrid');
            if (filesGrid) {
                const newFolder = document.createElement('div');
                newFolder.className = 'file-card folder';
                newFolder.dataset.name = folderName;
                newFolder.dataset.type = 'folder';
                newFolder.innerHTML = `
                    <div class="file-card-icon">
                        ${this.getFileIcon('folder')}
                    </div>
                    <div class="file-card-info">
                        <h4 class="file-name">${folderName}</h4>
                        <span class="file-meta">0 elementi · Creato ora</span>
                    </div>
                    <div class="file-card-actions">
                        <button class="action-btn" title="Share">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
                                <polyline points="16 6 12 2 8 6"/>
                                <line x1="12" y1="2" x2="12" y2="15"/>
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

                filesGrid.insertBefore(newFolder, filesGrid.firstChild);
                newFolder.style.animation = 'fadeIn 0.3s';
            }
        }
    }

    renameFile(fileName) {
        const newName = prompt('Inserisci il nuovo nome:', fileName);
        if (newName && newName !== fileName) {
            console.log(`Renaming ${fileName} to ${newName}`);
            this.showToast(`Rinominato in ${newName}`, 'success');
            // Update UI
            const element = document.querySelector(`[data-name="${fileName}"]`);
            if (element) {
                element.dataset.name = newName;
                const nameElement = element.querySelector('.file-name');
                if (nameElement) {
                    nameElement.textContent = newName;
                }
            }
        }
    }

    copyFile(fileName) {
        console.log('Copying file:', fileName);
        this.showToast(`${fileName} copiato negli appunti`, 'info');
    }

    downloadFile(fileName) {
        console.log('Downloading file:', fileName);
        this.showToast(`Download di ${fileName}...`, 'info');
        // In a real app, trigger download
    }

    shareFile(fileName) {
        console.log('Sharing file:', fileName);
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
            console.log('Deleting file:', fileName);

            // Get file ID from element
            const element = document.querySelector(`[data-name="${fileName}"]`);
            const fileId = element?.dataset.fileId;

            if (!fileId) {
                this.showToast('Errore: ID file non trovato', 'error');
                return;
            }

            try {
                // Get CSRF token
                const csrfToken = document.getElementById('csrfToken')?.value;

                // Call API to delete file
                const response = await fetch(`${this.config.apiBase}?action=delete&id=${fileId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-Token': csrfToken || ''
                    },
                    credentials: 'same-origin'
                });

                const result = await response.json();

                if (result.success) {
                    // Remove from UI with animation
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
            const csrfToken = document.getElementById('csrfToken')?.value;
            let deletedCount = 0;

            for (const fileName of this.state.selectedFiles) {
                const element = document.querySelector(`[data-name="${fileName}"]`);
                const fileId = element?.dataset.fileId;

                if (fileId) {
                    try {
                        const response = await fetch(`${this.config.apiBase}?action=delete&id=${fileId}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-Token': csrfToken || ''
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

    formatFileSize(bytes) {
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        if (bytes === 0) return '0 Bytes';
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
    }

    getFileTypeLabel(type) {
        const typeLabels = {
            pdf: 'Documento PDF',
            word: 'Documento Word',
            excel: 'Foglio Excel',
            powerpoint: 'Presentazione PowerPoint',
            image: 'Immagine',
            video: 'Video',
            audio: 'Audio',
            archive: 'Archivio',
            folder: 'Cartella'
        };
        return typeLabels[type] || 'File';
    }

    updateFilePreview(sidebar, fileElement) {
        const previewContainer = sidebar.querySelector('.details-preview');
        if (!previewContainer) return;

        const fileType = fileElement.dataset.type;
        const fileName = fileElement.dataset.name;

        // Clear existing preview
        previewContainer.innerHTML = '';

        if (fileType === 'image') {
            // For images, try to get the preview from the card
            const existingPreview = fileElement.querySelector('.file-card-preview img');
            if (existingPreview) {
                const img = existingPreview.cloneNode(true);
                previewContainer.appendChild(img);
            } else {
                previewContainer.innerHTML = '<img src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 200 150\'%3E%3Crect fill=\'%23F3F4F6\' width=\'200\' height=\'150\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%236B7280\' font-family=\'sans-serif\' font-size=\'14\'%3EImage Preview%3C/text%3E%3C/svg%3E" alt="Preview">';
            }
        } else {
            // For other file types, show the icon
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

    // Helper method to get file ID by name
    async getFileIdByName(fileName) {
        // First check if we have it in a data attribute
        const element = document.querySelector(`[data-name="${fileName}"]`);
        if (element && element.dataset.fileId) {
            return element.dataset.fileId;
        }

        // If not found, we would need to make an API call
        // For now, return null
        return null;
    }

    // Render files from API response
    renderFiles(files) {
        const filesGrid = document.getElementById('filesGrid');
        const filesList = document.getElementById('filesList');
        const emptyState = document.getElementById('emptyState');

        if (!files || files.length === 0) {
            // Show empty state
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

        // Hide empty state
        if (emptyState) {
            emptyState.classList.add('hidden');
        }

        // Clear existing files
        if (filesGrid) filesGrid.innerHTML = '';
        if (filesList) {
            const tbody = filesList.querySelector('tbody');
            if (tbody) tbody.innerHTML = '';
        }

        // Render each file
        files.forEach(file => {
            if (this.state.currentView === 'grid') {
                this.renderGridItem(file);
            } else {
                this.renderListItem(file);
            }
        });
    }

    // Render a file in grid view
    renderGridItem(file) {
        const filesGrid = document.getElementById('filesGrid');
        if (!filesGrid) return;

        const fileCard = document.createElement('div');
        fileCard.className = file.is_folder ? 'file-card folder' : 'file-card';
        fileCard.dataset.name = file.name;
        fileCard.dataset.type = file.is_folder ? 'folder' : file.type;
        fileCard.dataset.fileId = file.id;

        const iconHtml = file.is_folder ?
            `<svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M10 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2h-8l-2-2z" fill="#F59E0B"/>
            </svg>` :
            this.getFileIcon(file.type);

        const formattedSize = file.is_folder ?
            `${file.item_count || 0} elementi` :
            this.formatFileSize(file.size);

        const modifiedDate = this.formatDate(file.updated_at);

        fileCard.innerHTML = `
            <div class="file-card-icon ${file.type}">
                ${iconHtml}
            </div>
            <div class="file-card-info">
                <h4 class="file-name">${file.name}</h4>
                <span class="file-meta">${formattedSize} · Modificato ${modifiedDate}</span>
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

    // Render a file in list view
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

    // Get file icon based on type
    getFileIcon(type) {
        const icons = {
            pdf: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#EF4444" stroke="#EF4444"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">PDF</text></svg>',
            doc: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#2563EB" stroke="#2563EB"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">DOC</text></svg>',
            docx: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#2563EB" stroke="#2563EB"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">DOC</text></svg>',
            xls: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#10B981" stroke="#10B981"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">XLS</text></svg>',
            xlsx: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#10B981" stroke="#10B981"/><text x="12" y="17" text-anchor="middle" fill="white" font-size="6" font-weight="bold">XLS</text></svg>',
            default: '<svg viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#6B7280" stroke="#6B7280"/></svg>'
        };
        return icons[type] || icons.default;
    }

    // Get file color based on type
    getFileColor(type) {
        const colors = {
            pdf: '#EF4444',
            doc: '#2563EB',
            docx: '#2563EB',
            xls: '#10B981',
            xlsx: '#10B981',
            ppt: '#F59E0B',
            pptx: '#F59E0B',
            default: '#6B7280'
        };
        return colors[type] || colors.default;
    }

    // Format date
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

    // Debug method to test sidebar directly
    testSidebar() {
        console.log('Testing sidebar functionality...');
        const sidebar = document.getElementById('fileDetailsSidebar');
        const firstFile = document.querySelector('.file-card:not(.folder)');

        console.log('Sidebar element:', sidebar);
        console.log('First file element:', firstFile);

        if (sidebar) {
            console.log('Current sidebar classes:', sidebar.className);
            console.log('Current transform:', window.getComputedStyle(sidebar).transform);

            // Try to show sidebar
            sidebar.classList.add('active');
            console.log('Added active class');
            console.log('New transform:', window.getComputedStyle(sidebar).transform);

            // If we have a file, show its details
            if (firstFile) {
                this.showDetailsSidebar(firstFile);
            }
        } else {
            console.error('Sidebar element not found in DOM!');
        }
    }
    }

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        window.fileManager = new FileManager();
    });

    // Add animations
    const fileManagerAnimationStyles = document.createElement('style');
    fileManagerAnimationStyles.textContent = `
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
    `;
    document.head.appendChild(fileManagerAnimationStyles);
})();