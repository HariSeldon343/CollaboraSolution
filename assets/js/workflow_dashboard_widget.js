/**
 * Workflow Dashboard Widget
 * Adds workflow statistics and quick actions to the dashboard
 *
 * @version 1.0.0
 */

class WorkflowDashboardWidget {
    constructor() {
        this.config = {
            workflowApi: '/CollaboraNexio/api/documents/workflow/',
            filesPageUrl: '/CollaboraNexio/files.php'
        };

        this.state = {
            stats: {
                pendingValidation: 0,
                pendingApproval: 0,
                myDocuments: 0,
                recentActivity: []
            },
            loading: true
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
     * Initialize widget
     */
    async init() {
        // Create widget container
        this.createWidget();

        // Load initial data
        await this.loadStats();

        // Setup auto-refresh
        this.setupAutoRefresh();
    }

    /**
     * Create widget HTML structure
     */
    createWidget() {
        // Find dashboard container
        let container = document.querySelector('.dashboard-widgets');

        if (!container) {
            // If no widgets container, create one
            const mainContent = document.querySelector('.main-content, .dashboard-content');
            if (mainContent) {
                container = document.createElement('div');
                container.className = 'dashboard-widgets';
                container.style.cssText = 'display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;';
                mainContent.insertBefore(container, mainContent.firstChild);
            } else {
                console.warn('[WorkflowWidget] Dashboard container not found');
                return;
            }
        }

        // Create widget element
        const widget = document.createElement('div');
        widget.id = 'workflowWidget';
        widget.className = 'dashboard-widget workflow-widget';
        widget.innerHTML = `
            <div class="widget-card">
                <div class="widget-header">
                    <h3 class="widget-title">
                        <span class="widget-icon">📋</span>
                        Workflow Documenti
                    </h3>
                    <button class="widget-refresh" onclick="workflowWidget.refresh()" title="Aggiorna">
                        🔄
                    </button>
                </div>
                <div class="widget-body">
                    <div class="widget-loading" id="workflowLoading">
                        <div class="spinner"></div>
                        <span>Caricamento...</span>
                    </div>
                    <div class="workflow-stats" id="workflowStats" style="display: none;">
                        <div class="stat-card pending-validation" onclick="workflowWidget.navigateToFiles('in_validazione')">
                            <div class="stat-icon">🔍</div>
                            <div class="stat-content">
                                <div class="stat-value" id="statPendingValidation">0</div>
                                <div class="stat-label">In attesa di validazione</div>
                            </div>
                        </div>
                        <div class="stat-card pending-approval" onclick="workflowWidget.navigateToFiles('in_approvazione')">
                            <div class="stat-icon">⏳</div>
                            <div class="stat-content">
                                <div class="stat-value" id="statPendingApproval">0</div>
                                <div class="stat-label">In attesa di approvazione</div>
                            </div>
                        </div>
                        <div class="stat-card my-documents" onclick="workflowWidget.navigateToFiles('my_documents')">
                            <div class="stat-icon">📄</div>
                            <div class="stat-content">
                                <div class="stat-value" id="statMyDocuments">0</div>
                                <div class="stat-label">I miei documenti in workflow</div>
                            </div>
                        </div>
                    </div>
                    <div class="recent-activity" id="recentActivity" style="display: none;">
                        <h4 class="activity-title">Attività Recente</h4>
                        <ul class="activity-list" id="activityList">
                            <!-- Activity items will be rendered here -->
                        </ul>
                    </div>
                </div>
                <div class="widget-footer">
                    <a href="/CollaboraNexio/files.php" class="widget-link">
                        Vai al File Manager →
                    </a>
                </div>
            </div>
        `;

        container.appendChild(widget);

        // Add widget styles
        if (!document.getElementById('workflowWidgetStyles')) {
            const styles = document.createElement('style');
            styles.id = 'workflowWidgetStyles';
            styles.innerHTML = `
                .workflow-widget .widget-card {
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                    overflow: hidden;
                }

                .workflow-widget .widget-header {
                    padding: 16px 20px;
                    border-bottom: 1px solid #e5e7eb;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                }

                .workflow-widget .widget-title {
                    font-size: 18px;
                    font-weight: 600;
                    color: #111827;
                    margin: 0;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .workflow-widget .widget-icon {
                    font-size: 20px;
                }

                .workflow-widget .widget-refresh {
                    background: transparent;
                    border: none;
                    font-size: 18px;
                    cursor: pointer;
                    padding: 4px;
                    border-radius: 6px;
                    transition: all 0.2s ease;
                }

                .workflow-widget .widget-refresh:hover {
                    background: #f3f4f6;
                    transform: rotate(180deg);
                }

                .workflow-widget .widget-body {
                    padding: 20px;
                    min-height: 200px;
                }

                .workflow-widget .widget-loading {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    padding: 40px;
                    color: #6b7280;
                }

                .workflow-widget .spinner {
                    width: 40px;
                    height: 40px;
                    border: 3px solid #e5e7eb;
                    border-top-color: #3b82f6;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                }

                @keyframes spin {
                    to { transform: rotate(360deg); }
                }

                .workflow-widget .workflow-stats {
                    display: grid;
                    gap: 16px;
                }

                .workflow-widget .stat-card {
                    display: flex;
                    align-items: center;
                    gap: 16px;
                    padding: 16px;
                    background: #f9fafb;
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }

                .workflow-widget .stat-card:hover {
                    background: #f3f4f6;
                    transform: translateX(4px);
                }

                .workflow-widget .stat-card.pending-validation {
                    border-left: 4px solid #eab308;
                }

                .workflow-widget .stat-card.pending-approval {
                    border-left: 4px solid #f97316;
                }

                .workflow-widget .stat-card.my-documents {
                    border-left: 4px solid #3b82f6;
                }

                .workflow-widget .stat-icon {
                    font-size: 32px;
                }

                .workflow-widget .stat-content {
                    flex: 1;
                }

                .workflow-widget .stat-value {
                    font-size: 28px;
                    font-weight: 700;
                    color: #111827;
                }

                .workflow-widget .stat-label {
                    font-size: 13px;
                    color: #6b7280;
                    margin-top: 4px;
                }

                .workflow-widget .recent-activity {
                    margin-top: 24px;
                    padding-top: 20px;
                    border-top: 1px solid #e5e7eb;
                }

                .workflow-widget .activity-title {
                    font-size: 14px;
                    font-weight: 600;
                    color: #374151;
                    margin: 0 0 12px 0;
                }

                .workflow-widget .activity-list {
                    list-style: none;
                    margin: 0;
                    padding: 0;
                }

                .workflow-widget .activity-item {
                    padding: 8px 0;
                    border-bottom: 1px solid #f3f4f6;
                    font-size: 13px;
                }

                .workflow-widget .activity-item:last-child {
                    border-bottom: none;
                }

                .workflow-widget .activity-time {
                    color: #9ca3af;
                    font-size: 11px;
                }

                .workflow-widget .widget-footer {
                    padding: 12px 20px;
                    background: #f9fafb;
                    border-top: 1px solid #e5e7eb;
                }

                .workflow-widget .widget-link {
                    color: #3b82f6;
                    text-decoration: none;
                    font-size: 14px;
                    font-weight: 500;
                    display: inline-flex;
                    align-items: center;
                    gap: 4px;
                    transition: color 0.2s ease;
                }

                .workflow-widget .widget-link:hover {
                    color: #2563eb;
                }
            `;
            document.head.appendChild(styles);
        }
    }

    /**
     * Load workflow statistics
     */
    async loadStats() {
        this.state.loading = true;
        this.updateLoadingState();

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
                this.state.stats = {
                    pendingValidation: data.data?.stats?.pendingValidation || 0,
                    pendingApproval: data.data?.stats?.pendingApproval || 0,
                    myDocuments: data.data?.stats?.myDocuments || 0,
                    recentActivity: data.data?.recentActivity || []
                };

                this.updateUI();
            }
        } catch (error) {
            console.error('[WorkflowWidget] Failed to load stats:', error);
            this.showError();
        } finally {
            this.state.loading = false;
            this.updateLoadingState();
        }
    }

    /**
     * Update loading state
     */
    updateLoadingState() {
        const loadingEl = document.getElementById('workflowLoading');
        const statsEl = document.getElementById('workflowStats');
        const activityEl = document.getElementById('recentActivity');

        if (this.state.loading) {
            if (loadingEl) loadingEl.style.display = 'flex';
            if (statsEl) statsEl.style.display = 'none';
            if (activityEl) activityEl.style.display = 'none';
        } else {
            if (loadingEl) loadingEl.style.display = 'none';
            if (statsEl) statsEl.style.display = 'grid';
            if (activityEl && this.state.stats.recentActivity.length > 0) {
                activityEl.style.display = 'block';
            }
        }
    }

    /**
     * Update UI with current stats
     */
    updateUI() {
        // Update stat values
        document.getElementById('statPendingValidation').textContent = this.state.stats.pendingValidation;
        document.getElementById('statPendingApproval').textContent = this.state.stats.pendingApproval;
        document.getElementById('statMyDocuments').textContent = this.state.stats.myDocuments;

        // Update recent activity
        const activityList = document.getElementById('activityList');
        if (activityList && this.state.stats.recentActivity.length > 0) {
            activityList.innerHTML = this.state.stats.recentActivity.slice(0, 5).map(activity => {
                const timeAgo = this.getTimeAgo(new Date(activity.created_at));

                const stateColors = {
                    bozza: '#3b82f6',
                    in_validazione: '#eab308',
                    validato: '#22c55e',
                    in_approvazione: '#f97316',
                    approvato: '#10b981',
                    rifiutato: '#ef4444'
                };

                const actionLabels = {
                    submit: 'inviato in validazione',
                    validate: 'validato',
                    reject: 'rifiutato',
                    approve: 'approvato',
                    recall: 'richiamato'
                };

                return `
                    <li class="activity-item">
                        <div>
                            <strong>${activity.user_name}</strong>
                            ha ${actionLabels[activity.action] || activity.action}
                            <strong>${activity.file_name}</strong>
                        </div>
                        <div class="activity-time">${timeAgo}</div>
                    </li>
                `;
            }).join('');
        }
    }

    /**
     * Get time ago string
     */
    getTimeAgo(date) {
        const seconds = Math.floor((new Date() - date) / 1000);

        if (seconds < 60) return 'ora';
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) return `${minutes} ${minutes === 1 ? 'minuto' : 'minuti'} fa`;
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return `${hours} ${hours === 1 ? 'ora' : 'ore'} fa`;
        const days = Math.floor(hours / 24);
        if (days < 30) return `${days} ${days === 1 ? 'giorno' : 'giorni'} fa`;
        const months = Math.floor(days / 30);
        return `${months} ${months === 1 ? 'mese' : 'mesi'} fa`;
    }

    /**
     * Show error state
     */
    showError() {
        const statsEl = document.getElementById('workflowStats');
        if (statsEl) {
            statsEl.innerHTML = `
                <div style="text-align: center; color: #ef4444; padding: 20px;">
                    <div style="font-size: 32px; margin-bottom: 8px;">⚠️</div>
                    <div>Errore caricamento dati</div>
                    <button onclick="workflowWidget.refresh()" style="margin-top: 12px; padding: 6px 12px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        Riprova
                    </button>
                </div>
            `;
        }
    }

    /**
     * Navigate to files page with filter
     */
    navigateToFiles(filter) {
        window.location.href = `${this.config.filesPageUrl}?workflow=${filter}`;
    }

    /**
     * Refresh widget data
     */
    async refresh() {
        const refreshBtn = document.querySelector('.widget-refresh');
        if (refreshBtn) {
            refreshBtn.style.animation = 'spin 1s linear';
        }

        await this.loadStats();

        if (refreshBtn) {
            setTimeout(() => {
                refreshBtn.style.animation = '';
            }, 1000);
        }
    }

    /**
     * Setup auto-refresh
     */
    setupAutoRefresh() {
        // Refresh every 60 seconds
        setInterval(() => {
            this.loadStats();
        }, 60000);
    }
}

// Initialize widget when document is ready
document.addEventListener('DOMContentLoaded', () => {
    window.workflowWidget = new WorkflowDashboardWidget();
});