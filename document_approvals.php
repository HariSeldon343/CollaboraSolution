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

// Require active tenant access (super_admins bypass this check)
require_once __DIR__ . '/includes/tenant_access_check.php';
requireTenantAccess($currentUser['id'], $currentUser['role']);

// Get user role
$user_role = $currentUser['role'] ?? 'user';
$user_id = $currentUser['id'];
$tenant_id = $_SESSION['selected_tenant_id'] ?? $_SESSION['tenant_id'];

// Only managers, admins and super_admins can access this page
if (!in_array($user_role, ['manager', 'admin', 'super_admin'])) {
    header('Location: dashboard.php');
    exit;
}

// Generate CSRF token
$csrf_token = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approvazione Documenti - CollaboraNexio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        .main-container {
            padding: 20px;
            margin-top: 80px;
        }

        .approval-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            padding: 25px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }

        .approval-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #ffeaa7;
            color: #fdcb6e;
        }

        .status-approved {
            background: #55efc4;
            color: #00b894;
        }

        .status-rejected {
            background: #fab1a0;
            color: #e17055;
        }

        .status-draft {
            background: #dfe6e9;
            color: #636e72;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-approve {
            background: linear-gradient(135deg, #55efc4 0%, #00b894 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-approve:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(0, 184, 148, 0.3);
            color: white;
        }

        .btn-reject {
            background: linear-gradient(135deg, #fab1a0 0%, #e17055 100%);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-reject:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(225, 112, 85, 0.3);
            color: white;
        }

        .filter-tabs {
            background: white;
            border-radius: 15px;
            padding: 10px;
            margin-bottom: 30px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 10px;
            background: #f1f2f6;
            color: #2c3e50;
            border: none;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .filter-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .filter-tab:hover:not(.active) {
            background: #dfe6e9;
        }

        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        .stat-item {
            text-align: center;
            padding: 15px;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            color: #95a5a6;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 5px;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .file-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .file-icon.pdf { background: #ff6b6b; color: white; }
        .file-icon.doc { background: #4ecdc4; color: white; }
        .file-icon.xls { background: #45b7d1; color: white; }
        .file-icon.img { background: #f7b731; color: white; }
        .file-icon.other { background: #95a5a6; color: white; }

        .search-bar {
            background: white;
            border-radius: 50px;
            padding: 15px 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .search-bar input {
            border: none;
            outline: none;
            flex: 1;
            font-size: 16px;
        }

        .search-bar i {
            color: #95a5a6;
            font-size: 20px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: white;
        }

        .empty-state i {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.8;
        }

        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .empty-state p {
            font-size: 16px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <?php
    // Include tenant switcher for admin/super_admin
    if (in_array($user_role, ['admin', 'super_admin'])) {
        include 'includes/tenant_switcher.php';
    }
    ?>

    <div class="container main-container">
        <h1 class="text-white mb-4">
            <i class="fas fa-check-circle"></i> Approvazione Documenti
        </h1>

        <!-- Statistics -->
        <div class="stats-card">
            <div class="row">
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number" id="pendingCount">0</div>
                        <div class="stat-label">In Attesa</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number" id="approvedCount">0</div>
                        <div class="stat-label">Approvati</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number" id="rejectedCount">0</div>
                        <div class="stat-label">Rifiutati</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stat-item">
                        <div class="stat-number" id="totalCount">0</div>
                        <div class="stat-label">Totale</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Cerca documenti per nome, proprietario..." />
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <button class="filter-tab active" data-status="in_approvazione">
                <i class="fas fa-clock"></i> In Attesa
            </button>
            <button class="filter-tab" data-status="approvato">
                <i class="fas fa-check"></i> Approvati
            </button>
            <button class="filter-tab" data-status="rifiutato">
                <i class="fas fa-times"></i> Rifiutati
            </button>
            <button class="filter-tab" data-status="all">
                <i class="fas fa-list"></i> Tutti
            </button>
        </div>

        <!-- Documents List -->
        <div id="documentsList">
            <!-- Documents will be loaded here -->
        </div>

        <!-- Empty State -->
        <div id="emptyState" class="empty-state" style="display: none;">
            <i class="fas fa-inbox"></i>
            <h3>Nessun documento trovato</h3>
            <p>Non ci sono documenti da visualizzare con i filtri selezionati</p>
        </div>
    </div>

    <!-- Approval Modal -->
    <div class="modal fade" id="approvalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Conferma Approvazione</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Sei sicuro di voler approvare questo documento?</p>
                    <div class="mb-3">
                        <label for="approvalComments" class="form-label">Commenti (opzionale)</label>
                        <textarea class="form-control" id="approvalComments" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-approve" onclick="confirmApproval()">
                        <i class="fas fa-check"></i> Approva
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Rejection Modal -->
    <div class="modal fade" id="rejectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Rifiuta Documento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="rejectionReason" class="form-label">Motivo del rifiuto *</label>
                        <textarea class="form-control" id="rejectionReason" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="rejectionComments" class="form-label">Commenti aggiuntivi</label>
                        <textarea class="form-control" id="rejectionComments" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-reject" onclick="confirmRejection()">
                        <i class="fas fa-times"></i> Rifiuta
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentFileId = null;
        let currentStatus = 'in_approvazione';
        let searchTerm = '';

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadDocuments();

            // Filter tabs
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    currentStatus = this.dataset.status;
                    loadDocuments();
                });
            });

            // Search
            let searchTimeout;
            document.getElementById('searchInput').addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                searchTerm = e.target.value;
                searchTimeout = setTimeout(() => loadDocuments(), 300);
            });
        });

        function loadDocuments() {
            const params = new URLSearchParams({
                status: currentStatus,
                search: searchTerm,
                tenant_id: '<?php echo $tenant_id; ?>'
            });

            fetch(`api/documents/pending.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayDocuments(data.data);
                        updateStatistics(data.stats);
                    } else {
                        console.error('Error loading documents:', data.error);
                    }
                })
                .catch(error => console.error('Error:', error));
        }

        function displayDocuments(documents) {
            const container = document.getElementById('documentsList');
            const emptyState = document.getElementById('emptyState');

            if (documents.length === 0) {
                container.innerHTML = '';
                emptyState.style.display = 'block';
                return;
            }

            emptyState.style.display = 'none';
            container.innerHTML = documents.map(doc => createDocumentCard(doc)).join('');
        }

        function createDocumentCard(doc) {
            const fileIcon = getFileIcon(doc.mime_type);
            const statusBadge = getStatusBadge(doc.status);

            return `
                <div class="approval-card">
                    <div class="file-info">
                        <div class="file-icon ${fileIcon.class}">
                            <i class="${fileIcon.icon}"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="mb-1">${escapeHtml(doc.name)}</h5>
                            <p class="text-muted mb-0">
                                <i class="fas fa-user"></i> ${escapeHtml(doc.owner_name)} •
                                <i class="fas fa-building"></i> ${escapeHtml(doc.tenant_name)} •
                                <i class="fas fa-calendar"></i> ${doc.created_at_formatted}
                            </p>
                        </div>
                        <div>
                            <span class="status-badge ${statusBadge.class}">${statusBadge.text}</span>
                        </div>
                    </div>
                    ${doc.status === 'rifiutato' && doc.rejection_reason ? `
                        <div class="alert alert-danger mt-3">
                            <strong>Motivo rifiuto:</strong> ${escapeHtml(doc.rejection_reason)}
                        </div>
                    ` : ''}
                    ${doc.status === 'approvato' && doc.approver_name ? `
                        <div class="alert alert-success mt-3">
                            <strong>Approvato da:</strong> ${escapeHtml(doc.approver_name)} • ${doc.approved_at_formatted}
                        </div>
                    ` : ''}
                    ${doc.can_approve ? `
                        <div class="action-buttons mt-3">
                            <button class="btn btn-approve" onclick="approveDocument(${doc.id})">
                                <i class="fas fa-check"></i> Approva
                            </button>
                            <button class="btn btn-reject" onclick="rejectDocument(${doc.id})">
                                <i class="fas fa-times"></i> Rifiuta
                            </button>
                        </div>
                    ` : ''}
                </div>
            `;
        }

        function getFileIcon(mimeType) {
            if (mimeType.includes('pdf')) return { icon: 'fas fa-file-pdf', class: 'pdf' };
            if (mimeType.includes('word') || mimeType.includes('document')) return { icon: 'fas fa-file-word', class: 'doc' };
            if (mimeType.includes('sheet') || mimeType.includes('excel')) return { icon: 'fas fa-file-excel', class: 'xls' };
            if (mimeType.includes('image')) return { icon: 'fas fa-file-image', class: 'img' };
            return { icon: 'fas fa-file', class: 'other' };
        }

        function getStatusBadge(status) {
            switch(status) {
                case 'in_approvazione': return { text: 'In Attesa', class: 'status-pending' };
                case 'approvato': return { text: 'Approvato', class: 'status-approved' };
                case 'rifiutato': return { text: 'Rifiutato', class: 'status-rejected' };
                case 'bozza': return { text: 'Bozza', class: 'status-draft' };
                default: return { text: status, class: 'status-pending' };
            }
        }

        function updateStatistics(stats) {
            if (stats) {
                document.getElementById('pendingCount').textContent = stats.pending || 0;
                document.getElementById('approvedCount').textContent = stats.approved || 0;
                document.getElementById('rejectedCount').textContent = stats.rejected || 0;
                document.getElementById('totalCount').textContent = stats.total || 0;
            }
        }

        function approveDocument(fileId) {
            currentFileId = fileId;
            const modal = new bootstrap.Modal(document.getElementById('approvalModal'));
            modal.show();
        }

        function rejectDocument(fileId) {
            currentFileId = fileId;
            const modal = new bootstrap.Modal(document.getElementById('rejectionModal'));
            modal.show();
        }

        function confirmApproval() {
            const comments = document.getElementById('approvalComments').value;

            fetch('api/documents/approve.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo $csrf_token; ?>'
                },
                body: JSON.stringify({
                    file_id: currentFileId,
                    comments: comments
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('approvalModal')).hide();
                    loadDocuments();
                    showNotification('success', 'Documento approvato con successo');
                } else {
                    showNotification('error', data.error || 'Errore durante l\'approvazione');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('error', 'Errore di connessione');
            });
        }

        function confirmRejection() {
            const reason = document.getElementById('rejectionReason').value;
            const comments = document.getElementById('rejectionComments').value;

            if (!reason) {
                showNotification('error', 'Il motivo del rifiuto è obbligatorio');
                return;
            }

            fetch('api/documents/reject.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?php echo $csrf_token; ?>'
                },
                body: JSON.stringify({
                    file_id: currentFileId,
                    reason: reason,
                    comments: comments
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('rejectionModal')).hide();
                    loadDocuments();
                    showNotification('success', 'Documento rifiutato');
                } else {
                    showNotification('error', data.error || 'Errore durante il rifiuto');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('error', 'Errore di connessione');
            });
        }

        function showNotification(type, message) {
            // You can implement a toast notification system here
            alert(message);
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, m => map[m]);
        }
    </script>
</body>
</html>