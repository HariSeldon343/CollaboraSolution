/**
 * PDF Viewer for CollaboraNexio
 *
 * Integrated PDF viewer using PDF.js (Mozilla)
 * Features: zoom, navigation, download, mobile responsive
 *
 * @version 1.0.0
 * @requires PDF.js (loaded via CDN)
 */

(function() {
    'use strict';

    class PDFViewer {
        constructor() {
            this.config = {
                pdfJsLibrary: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',
                pdfJsWorker: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js',
                downloadEndpoint: '/CollaboraNexio/api/files_tenant.php'
            };

            this.state = {
                pdfDoc: null,
                pageNum: 1,
                pageCount: 0,
                scale: 1.0,
                rendering: false,
                pageRendering: false,
                pageNumPending: null,
                fileId: null,
                fileName: null,
                canvas: null,
                ctx: null
            };

            this.initialized = false;
            this.loadingPdfJs = false;
        }

        /**
         * Initialize PDF.js library
         */
        async initializePdfJs() {
            if (this.initialized) return true;
            if (this.loadingPdfJs) {
                // Wait for loading to complete
                return new Promise((resolve) => {
                    const checkInterval = setInterval(() => {
                        if (this.initialized || !this.loadingPdfJs) {
                            clearInterval(checkInterval);
                            resolve(this.initialized);
                        }
                    }, 100);
                });
            }

            this.loadingPdfJs = true;

            try {
                // Check if PDF.js is already loaded
                if (window.pdfjsLib) {
                    window.pdfjsLib.GlobalWorkerOptions.workerSrc = this.config.pdfJsWorker;
                    this.initialized = true;
                    this.loadingPdfJs = false;
                    return true;
                }

                // Load PDF.js library
                await this.loadScript(this.config.pdfJsLibrary);

                // Set worker path
                if (window.pdfjsLib) {
                    window.pdfjsLib.GlobalWorkerOptions.workerSrc = this.config.pdfJsWorker;
                    this.initialized = true;
                    this.loadingPdfJs = false;
                    return true;
                }

                throw new Error('PDF.js library failed to load');

            } catch (error) {
                console.error('Failed to initialize PDF.js:', error);
                this.loadingPdfJs = false;
                return false;
            }
        }

        /**
         * Load external script
         */
        loadScript(src) {
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = src;
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }

        /**
         * Open PDF file
         * @param {number} fileId - File ID
         * @param {string} fileName - File name
         */
        async openPDF(fileId, fileName) {
            // Initialize PDF.js if needed
            const ready = await this.initializePdfJs();
            if (!ready) {
                this.showError('Impossibile caricare il visualizzatore PDF');
                return;
            }

            this.state.fileId = fileId;
            this.state.fileName = fileName;

            // Create viewer UI
            this.createViewerUI();

            // Show loading state
            this.showLoading();

            try {
                // Build download URL
                const downloadUrl = `${this.config.downloadEndpoint}?action=download&id=${fileId}`;

                // Load PDF document
                const loadingTask = window.pdfjsLib.getDocument(downloadUrl);

                this.state.pdfDoc = await loadingTask.promise;
                this.state.pageCount = this.state.pdfDoc.numPages;
                this.state.pageNum = 1;

                // Update UI
                this.updatePageInfo();
                this.updateNavigationButtons();

                // Render first page
                await this.renderPage(this.state.pageNum);

                // Hide loading
                this.hideLoading();

            } catch (error) {
                console.error('Error loading PDF:', error);
                this.hideLoading();
                this.showError('Errore nel caricamento del PDF');
            }
        }

        /**
         * Create viewer UI
         */
        createViewerUI() {
            // Check if viewer already exists
            let viewer = document.getElementById('pdfViewerOverlay');

            if (!viewer) {
                viewer = document.createElement('div');
                viewer.id = 'pdfViewerOverlay';
                viewer.className = 'pdf-viewer-overlay';
                viewer.innerHTML = `
                    <div class="pdf-viewer-container">
                        <!-- Header -->
                        <div class="pdf-viewer-header">
                            <div class="pdf-viewer-title">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="12" y1="18" x2="12" y2="12"/>
                                    <line x1="9" y1="15" x2="15" y2="15"/>
                                </svg>
                                <span id="pdfViewerFileName">Documento PDF</span>
                            </div>
                            <div class="pdf-viewer-actions">
                                <button id="pdfDownloadBtn" class="pdf-viewer-btn" title="Scarica PDF">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                        <polyline points="7 10 12 15 17 10"/>
                                        <line x1="12" y1="15" x2="12" y2="3"/>
                                    </svg>
                                </button>
                                <button id="pdfCloseBtn" class="pdf-viewer-btn" title="Chiudi">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="18" y1="6" x2="6" y2="18"/>
                                        <line x1="6" y1="6" x2="18" y2="18"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Toolbar -->
                        <div class="pdf-viewer-toolbar">
                            <!-- Navigation -->
                            <div class="pdf-toolbar-group">
                                <button id="pdfPrevBtn" class="pdf-toolbar-btn" title="Pagina precedente" disabled>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="15 18 9 12 15 6"/>
                                    </svg>
                                </button>
                                <div class="pdf-page-info">
                                    <span id="pdfPageNum">1</span>
                                    <span class="pdf-page-separator">/</span>
                                    <span id="pdfPageCount">1</span>
                                </div>
                                <button id="pdfNextBtn" class="pdf-toolbar-btn" title="Pagina successiva" disabled>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="9 18 15 12 9 6"/>
                                    </svg>
                                </button>
                            </div>

                            <!-- Zoom controls -->
                            <div class="pdf-toolbar-group">
                                <button id="pdfZoomOutBtn" class="pdf-toolbar-btn" title="Rimpicciolisci">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="11" cy="11" r="8"/>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                                        <line x1="8" y1="11" x2="14" y2="11"/>
                                    </svg>
                                </button>
                                <span id="pdfZoomLevel" class="pdf-zoom-level">100%</span>
                                <button id="pdfZoomInBtn" class="pdf-toolbar-btn" title="Ingrandisci">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="11" cy="11" r="8"/>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                                        <line x1="11" y1="8" x2="11" y2="14"/>
                                        <line x1="8" y1="11" x2="14" y2="11"/>
                                    </svg>
                                </button>
                                <button id="pdfZoomResetBtn" class="pdf-toolbar-btn" title="Zoom 100%">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="16 12 12 8 8 12"/>
                                        <line x1="12" y1="16" x2="12" y2="8"/>
                                    </svg>
                                </button>
                            </div>

                            <!-- Fit controls -->
                            <div class="pdf-toolbar-group">
                                <button id="pdfFitWidthBtn" class="pdf-toolbar-btn" title="Adatta larghezza">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Canvas container -->
                        <div class="pdf-viewer-content" id="pdfViewerContent">
                            <div class="pdf-canvas-wrapper">
                                <canvas id="pdfCanvas"></canvas>
                            </div>

                            <!-- Loading indicator -->
                            <div class="pdf-loading" id="pdfLoading">
                                <div class="pdf-loading-spinner">
                                    <svg viewBox="0 0 50 50">
                                        <circle cx="25" cy="25" r="20" fill="none" stroke="#2563EB" stroke-width="4" stroke-dasharray="31.4 31.4" stroke-linecap="round">
                                            <animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="1s" repeatCount="indefinite"/>
                                        </circle>
                                    </svg>
                                </div>
                                <p>Caricamento PDF...</p>
                            </div>

                            <!-- Error message -->
                            <div class="pdf-error hidden" id="pdfError">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="15" y1="9" x2="9" y2="15"/>
                                    <line x1="9" y1="9" x2="15" y2="15"/>
                                </svg>
                                <p id="pdfErrorMessage">Errore nel caricamento del PDF</p>
                            </div>
                        </div>
                    </div>
                `;
                document.body.appendChild(viewer);

                // Bind events
                this.bindEvents();
            }

            // Update file name
            document.getElementById('pdfViewerFileName').textContent = this.state.fileName || 'Documento PDF';

            // Show viewer
            viewer.classList.add('active');

            // Initialize canvas
            this.state.canvas = document.getElementById('pdfCanvas');
            this.state.ctx = this.state.canvas.getContext('2d');
        }

        /**
         * Bind event handlers
         */
        bindEvents() {
            // Close button
            document.getElementById('pdfCloseBtn').addEventListener('click', () => {
                this.close();
            });

            // Download button
            document.getElementById('pdfDownloadBtn').addEventListener('click', () => {
                this.downloadPDF();
            });

            // Navigation buttons
            document.getElementById('pdfPrevBtn').addEventListener('click', () => {
                this.previousPage();
            });

            document.getElementById('pdfNextBtn').addEventListener('click', () => {
                this.nextPage();
            });

            // Zoom buttons
            document.getElementById('pdfZoomInBtn').addEventListener('click', () => {
                this.zoomIn();
            });

            document.getElementById('pdfZoomOutBtn').addEventListener('click', () => {
                this.zoomOut();
            });

            document.getElementById('pdfZoomResetBtn').addEventListener('click', () => {
                this.zoomReset();
            });

            document.getElementById('pdfFitWidthBtn').addEventListener('click', () => {
                this.fitToWidth();
            });

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                if (!document.getElementById('pdfViewerOverlay').classList.contains('active')) {
                    return;
                }

                switch (e.key) {
                    case 'Escape':
                        this.close();
                        break;
                    case 'ArrowLeft':
                        this.previousPage();
                        break;
                    case 'ArrowRight':
                        this.nextPage();
                        break;
                    case '+':
                    case '=':
                        if (e.ctrlKey || e.metaKey) {
                            e.preventDefault();
                            this.zoomIn();
                        }
                        break;
                    case '-':
                        if (e.ctrlKey || e.metaKey) {
                            e.preventDefault();
                            this.zoomOut();
                        }
                        break;
                    case '0':
                        if (e.ctrlKey || e.metaKey) {
                            e.preventDefault();
                            this.zoomReset();
                        }
                        break;
                }
            });

            // Close on overlay click
            document.getElementById('pdfViewerOverlay').addEventListener('click', (e) => {
                if (e.target.id === 'pdfViewerOverlay') {
                    this.close();
                }
            });

            // Mouse wheel zoom (Ctrl + wheel)
            document.getElementById('pdfViewerContent').addEventListener('wheel', (e) => {
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    if (e.deltaY < 0) {
                        this.zoomIn();
                    } else {
                        this.zoomOut();
                    }
                }
            }, { passive: false });
        }

        /**
         * Render page
         */
        async renderPage(pageNum) {
            if (this.state.pageRendering) {
                this.state.pageNumPending = pageNum;
                return;
            }

            this.state.pageRendering = true;
            this.showLoading();

            try {
                const page = await this.state.pdfDoc.getPage(pageNum);

                // Calculate scale based on container width
                const containerWidth = document.getElementById('pdfViewerContent').clientWidth - 48; // padding
                const viewport = page.getViewport({ scale: 1.0 });

                // Calculate scale to fit width if needed
                let scale = this.state.scale;
                const maxWidth = containerWidth;

                if (viewport.width > maxWidth) {
                    scale = (maxWidth / viewport.width) * this.state.scale;
                }

                const scaledViewport = page.getViewport({ scale: scale });

                // Set canvas dimensions
                this.state.canvas.width = scaledViewport.width;
                this.state.canvas.height = scaledViewport.height;

                // Render PDF page
                const renderContext = {
                    canvasContext: this.state.ctx,
                    viewport: scaledViewport
                };

                const renderTask = page.render(renderContext);
                await renderTask.promise;

                this.state.pageRendering = false;
                this.hideLoading();

                // If there's a pending page, render it
                if (this.state.pageNumPending !== null) {
                    const pending = this.state.pageNumPending;
                    this.state.pageNumPending = null;
                    await this.renderPage(pending);
                }

            } catch (error) {
                console.error('Error rendering page:', error);
                this.state.pageRendering = false;
                this.hideLoading();
                this.showError('Errore nel rendering della pagina');
            }
        }

        /**
         * Navigation methods
         */
        previousPage() {
            if (this.state.pageNum <= 1) return;
            this.state.pageNum--;
            this.updatePageInfo();
            this.updateNavigationButtons();
            this.renderPage(this.state.pageNum);
        }

        nextPage() {
            if (this.state.pageNum >= this.state.pageCount) return;
            this.state.pageNum++;
            this.updatePageInfo();
            this.updateNavigationButtons();
            this.renderPage(this.state.pageNum);
        }

        /**
         * Zoom methods
         */
        zoomIn() {
            this.state.scale = Math.min(this.state.scale + 0.25, 3.0);
            this.updateZoomLevel();
            this.renderPage(this.state.pageNum);
        }

        zoomOut() {
            this.state.scale = Math.max(this.state.scale - 0.25, 0.5);
            this.updateZoomLevel();
            this.renderPage(this.state.pageNum);
        }

        zoomReset() {
            this.state.scale = 1.0;
            this.updateZoomLevel();
            this.renderPage(this.state.pageNum);
        }

        fitToWidth() {
            // Calculate scale to fit width
            const containerWidth = document.getElementById('pdfViewerContent').clientWidth - 48;

            this.state.pdfDoc.getPage(this.state.pageNum).then(page => {
                const viewport = page.getViewport({ scale: 1.0 });
                this.state.scale = containerWidth / viewport.width;
                this.updateZoomLevel();
                this.renderPage(this.state.pageNum);
            });
        }

        /**
         * UI update methods
         */
        updatePageInfo() {
            document.getElementById('pdfPageNum').textContent = this.state.pageNum;
            document.getElementById('pdfPageCount').textContent = this.state.pageCount;
        }

        updateNavigationButtons() {
            const prevBtn = document.getElementById('pdfPrevBtn');
            const nextBtn = document.getElementById('pdfNextBtn');

            prevBtn.disabled = this.state.pageNum <= 1;
            nextBtn.disabled = this.state.pageNum >= this.state.pageCount;
        }

        updateZoomLevel() {
            const zoomPercent = Math.round(this.state.scale * 100);
            document.getElementById('pdfZoomLevel').textContent = `${zoomPercent}%`;
        }

        showLoading() {
            const loading = document.getElementById('pdfLoading');
            if (loading) {
                loading.classList.remove('hidden');
            }
        }

        hideLoading() {
            const loading = document.getElementById('pdfLoading');
            if (loading) {
                loading.classList.add('hidden');
            }
        }

        showError(message) {
            const error = document.getElementById('pdfError');
            const errorMessage = document.getElementById('pdfErrorMessage');

            if (error && errorMessage) {
                errorMessage.textContent = message;
                error.classList.remove('hidden');
            }
        }

        /**
         * Download PDF
         */
        downloadPDF() {
            if (!this.state.fileId) return;

            const downloadUrl = `${this.config.downloadEndpoint}?action=download&id=${this.state.fileId}`;
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = this.state.fileName || 'document.pdf';
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Show toast notification
            if (window.fileManager && typeof window.fileManager.showToast === 'function') {
                window.fileManager.showToast(`Download di ${this.state.fileName} avviato`, 'success');
            }
        }

        /**
         * Close viewer
         */
        close() {
            const viewer = document.getElementById('pdfViewerOverlay');
            if (viewer) {
                viewer.classList.remove('active');
            }

            // Clean up
            this.state.pdfDoc = null;
            this.state.pageNum = 1;
            this.state.pageCount = 0;
            this.state.scale = 1.0;
            this.state.fileId = null;
            this.state.fileName = null;

            // Clear canvas
            if (this.state.canvas && this.state.ctx) {
                this.state.ctx.clearRect(0, 0, this.state.canvas.width, this.state.canvas.height);
            }
        }

        /**
         * Check if file is PDF
         */
        isPDF(fileName) {
            return fileName.toLowerCase().endsWith('.pdf');
        }
    }

    // Initialize and expose globally
    window.pdfViewer = new PDFViewer();

    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            console.log('PDF Viewer initialized');
        });
    } else {
        console.log('PDF Viewer initialized');
    }

})();
