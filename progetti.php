<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
$auth = new Auth();

if (!$auth->checkAuth()) {
    header('Location: index.php');
    exit;
}

// Get current user data
$currentUser = $auth->getCurrentUser();
if (!$currentUser) {
    header('Location: index.php');
    exit;
}

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progetti - CollaboraNexio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }

        .content-wrapper {
            margin-left: 250px;
            padding: 30px;
            transition: margin-left 0.3s ease;
        }

        .content-wrapper.expanded {
            margin-left: 0;
        }

        .page-header {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }

        .project-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }

        .project-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transform: translateY(-2px);
            border-color: #667eea;
        }

        .project-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active { background: #d4edda; color: #155724; }
        .status-planning { background: #cce5ff; color: #004085; }
        .status-on_hold { background: #fff3cd; color: #856404; }
        .status-completed { background: #e2e3e5; color: #383d41; }
        .status-cancelled { background: #f8d7da; color: #721c24; }

        .project-priority {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .priority-critical { background: #dc3545; }
        .priority-high { background: #ffc107; }
        .priority-medium { background: #17a2b8; }
        .priority-low { background: #6c757d; }

        .project-progress {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
            margin: 10px 0;
        }

        .project-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transition: width 0.5s ease;
        }

        .team-members {
            display: flex;
            margin-top: 15px;
        }

        .member-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: -10px;
            border: 2px solid white;
            position: relative;
            z-index: 1;
        }

        .member-avatar:hover {
            z-index: 10;
            transform: scale(1.1);
        }

        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .stats-value {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
        }

        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .filter-tab {
            padding: 8px 20px;
            border-radius: 25px;
            background: white;
            border: 2px solid #e9ecef;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .filter-tab:hover {
            border-color: #667eea;
            color: #667eea;
        }

        .filter-tab.active {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 20px;
        }

        .add-project-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(102,126,234,0.4);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 900;
        }

        .add-project-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(102,126,234,0.5);
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="content-wrapper" id="contentWrapper">
        <!-- Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="page-title">
                        <i class="bi bi-diagram-3"></i> I Miei Progetti
                    </h1>
                    <p class="text-muted mb-0">Gestisci e monitora tutti i tuoi progetti</p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="showNewProjectModal()">
                        <i class="bi bi-plus-lg"></i> Nuovo Progetto
                    </button>
                </div>
            </div>
        </div>

        <!-- Statistiche -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-value" id="totalProjects">0</div>
                    <div class="stats-label">Progetti Totali</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-value" id="activeProjects">0</div>
                    <div class="stats-label">Progetti Attivi</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-value" id="completedProjects">0</div>
                    <div class="stats-label">Completati</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-value" id="avgProgress">0%</div>
                    <div class="stats-label">Progresso Medio</div>
                </div>
            </div>
        </div>

        <!-- Filtri -->
        <div class="filter-tabs">
            <div class="filter-tab active" data-filter="all">
                Tutti i Progetti
            </div>
            <div class="filter-tab" data-filter="active">
                Attivi
            </div>
            <div class="filter-tab" data-filter="planning">
                In Pianificazione
            </div>
            <div class="filter-tab" data-filter="completed">
                Completati
            </div>
            <div class="filter-tab" data-filter="on_hold">
                In Pausa
            </div>
        </div>

        <!-- Lista Progetti -->
        <div class="row" id="projectsList">
            <!-- I progetti verranno caricati qui -->
        </div>

        <!-- Empty State -->
        <div class="empty-state" id="emptyState" style="display: none;">
            <i class="bi bi-folder-x"></i>
            <h4>Nessun progetto trovato</h4>
            <p>Crea il tuo primo progetto per iniziare a collaborare con il team</p>
            <button class="btn btn-primary" onclick="showNewProjectModal()">
                <i class="bi bi-plus-lg"></i> Crea Progetto
            </button>
        </div>
    </div>

    <!-- Bottone flottante -->
    <button class="add-project-btn" onclick="showNewProjectModal()">
        <i class="bi bi-plus"></i>
    </button>

    <!-- Modal Nuovo Progetto -->
    <div class="modal fade" id="newProjectModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Crea Nuovo Progetto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="newProjectForm">
                        <div class="mb-3">
                            <label class="form-label">Nome Progetto *</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Descrizione</label>
                            <textarea class="form-control" name="description" rows="3"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Data Inizio</label>
                                    <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Data Fine</label>
                                    <input type="date" class="form-control" name="end_date">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Stato</label>
                                    <select class="form-select" name="status">
                                        <option value="planning">In Pianificazione</option>
                                        <option value="active">Attivo</option>
                                        <option value="on_hold">In Pausa</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Priorità</label>
                                    <select class="form-select" name="priority">
                                        <option value="low">Bassa</option>
                                        <option value="medium" selected>Media</option>
                                        <option value="high">Alta</option>
                                        <option value="critical">Critica</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Budget (€)</label>
                            <input type="number" class="form-control" name="budget" min="0" step="100">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Tags (separati da virgola)</label>
                            <input type="text" class="form-control" name="tags" placeholder="web, design, marketing">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" onclick="createProject()">
                        <i class="bi bi-plus-lg"></i> Crea Progetto
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentFilter = 'all';
        let projects = [];

        // Inizializzazione
        document.addEventListener('DOMContentLoaded', function() {
            loadProjects();

            // Gestione filtri
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    document.querySelector('.filter-tab.active').classList.remove('active');
                    this.classList.add('active');
                    currentFilter = this.dataset.filter;
                    renderProjects();
                });
            });
        });

        // Carica progetti
        async function loadProjects() {
            try {
                const response = await fetch('/api/projects_complete.php?path=list', {
                    headers: {
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    }
                });

                if (response.ok) {
                    const data = await response.json();
                    projects = data.projects || [];

                    // Aggiorna statistiche
                    document.getElementById('totalProjects').textContent = data.statistics.total;
                    document.getElementById('activeProjects').textContent = data.statistics.active;
                    document.getElementById('completedProjects').textContent = data.statistics.completed;
                    document.getElementById('avgProgress').textContent = Math.round(data.statistics.avg_progress) + '%';

                    renderProjects();
                } else {
                    console.error('Errore caricamento progetti');
                }
            } catch (error) {
                console.error('Errore:', error);
            }
        }

        // Renderizza progetti
        function renderProjects() {
            const container = document.getElementById('projectsList');
            const emptyState = document.getElementById('emptyState');

            // Filtra progetti
            let filteredProjects = projects;
            if (currentFilter !== 'all') {
                filteredProjects = projects.filter(p => p.status === currentFilter);
            }

            if (filteredProjects.length === 0) {
                container.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';
            container.innerHTML = filteredProjects.map(project => `
                <div class="col-lg-6">
                    <div class="project-card" onclick="viewProject(${project.id})">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="mb-1">${escapeHtml(project.name)}</h5>
                                <p class="text-muted small mb-2">${escapeHtml(project.description || '')}</p>
                            </div>
                            <span class="project-status status-${project.status}">
                                ${getStatusLabel(project.status)}
                            </span>
                        </div>

                        <div class="d-flex align-items-center mb-2">
                            <span class="project-priority priority-${project.priority}"></span>
                            <span class="small text-muted">Priorità ${getPriorityLabel(project.priority)}</span>
                            <span class="ms-auto small text-muted">
                                <i class="bi bi-calendar3"></i>
                                ${project.end_date ? formatDate(project.end_date) : 'Nessuna scadenza'}
                            </span>
                        </div>

                        <div class="project-progress">
                            <div class="project-progress-bar" style="width: ${project.progress_percentage || 0}%"></div>
                        </div>
                        <div class="small text-muted mb-3">${project.progress_percentage || 0}% completato</div>

                        <div class="d-flex justify-content-between align-items-center">
                            <div class="team-members">
                                ${project.member_count > 0 ? generateMemberAvatars(project.member_count) : ''}
                                ${project.member_count > 3 ? `<span class="small text-muted ms-2">+${project.member_count - 3}</span>` : ''}
                            </div>
                            <div class="text-muted small">
                                <i class="bi bi-check2-square"></i> ${project.completed_tasks}/${project.task_count} tasks
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');
        }

        // Helper functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getStatusLabel(status) {
            const labels = {
                'active': 'Attivo',
                'planning': 'Pianificazione',
                'on_hold': 'In Pausa',
                'completed': 'Completato',
                'cancelled': 'Cancellato'
            };
            return labels[status] || status;
        }

        function getPriorityLabel(priority) {
            const labels = {
                'critical': 'Critica',
                'high': 'Alta',
                'medium': 'Media',
                'low': 'Bassa'
            };
            return labels[priority] || priority;
        }

        function formatDate(date) {
            return new Date(date).toLocaleDateString('it-IT', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        }

        function generateMemberAvatars(count) {
            let avatars = '';
            for (let i = 0; i < Math.min(3, count); i++) {
                avatars += `<div class="member-avatar">U${i+1}</div>`;
            }
            return avatars;
        }

        // Mostra modal nuovo progetto
        function showNewProjectModal() {
            const modal = new bootstrap.Modal(document.getElementById('newProjectModal'));
            modal.show();
        }

        // Crea progetto
        async function createProject() {
            const form = document.getElementById('newProjectForm');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);

            try {
                const response = await fetch('/api/projects_complete.php?path=create', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Authorization': 'Bearer ' + localStorage.getItem('token')
                    },
                    body: JSON.stringify(data)
                });

                if (response.ok) {
                    const result = await response.json();
                    bootstrap.Modal.getInstance(document.getElementById('newProjectModal')).hide();
                    form.reset();
                    loadProjects();
                    showNotification('success', 'Progetto creato con successo!');
                } else {
                    const error = await response.json();
                    showNotification('error', error.error || 'Errore nella creazione del progetto');
                }
            } catch (error) {
                console.error('Errore:', error);
                showNotification('error', 'Errore di connessione');
            }
        }

        // Visualizza progetto
        function viewProject(projectId) {
            window.location.href = `/project_detail.php?id=${projectId}`;
        }

        // Mostra notifica
        function showNotification(type, message) {
            // Implementa sistema di notifiche toast
            console.log(type, message);
        }
    </script>
</body>
</html>