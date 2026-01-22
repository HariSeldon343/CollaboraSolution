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

// Enforce Page Visibility access rules (configurazioni.php -> Visibilità Pagine)
require_once __DIR__ . '/includes/page_access_check.php';
checkPageAccess('tasks');

// Track page access for audit logging
require_once __DIR__ . '/includes/audit_page_access.php';
trackPageAccess('tasks');

// Company filter (for admin/super_admin)
require_once __DIR__ . '/includes/company_filter.php';
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Task - Nexio';
    require __DIR__ . '/includes/layout_head.php';
?>

    <style>
        /* Sidebar CSS is centralized in assets/css/styles.css */

        /* Task specific styles */
        .tasks-filters {
            display: flex;
            gap: var(--space-2);
            margin-bottom: var(--space-6);
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: var(--space-2) var(--space-4);
            border: 1px solid var(--color-gray-300);
            background: var(--color-white);
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .filter-btn:hover {
            background: var(--color-gray-50);
            border-color: var(--color-gray-400);
        }

        .filter-btn.active {
            background: var(--color-primary);
            color: var(--color-white);
            border-color: var(--color-primary);
        }

        .tasks-board {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-6);
        }

        .task-column {
            background: var(--color-gray-50);
            border-radius: var(--radius-lg);
            padding: var(--space-4);
        }

        .column-header {
            font-weight: var(--font-semibold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-4);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .task-count {
            background: var(--color-gray-200);
            color: var(--color-gray-600);
            padding: 2px var(--space-2);
            border-radius: var(--radius-full);
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
        }

        .task-card {
            background: var(--color-white);
            border: 1px solid var(--color-gray-200);
            border-radius: var(--radius-md);
            padding: var(--space-4);
            margin-bottom: var(--space-3);
            cursor: move;
            transition: all var(--transition-fast);
        }

        .task-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .task-title {
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
            margin-bottom: var(--space-2);
            font-size: var(--text-sm);
        }

        .task-description {
            font-size: var(--text-xs);
            color: var(--color-gray-600);
            margin-bottom: var(--space-3);
            line-height: var(--leading-relaxed);
        }

        .task-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: var(--text-xs);
        }

        .task-priority {
            padding: 2px var(--space-2);
            border-radius: var(--radius-sm);
            font-weight: var(--font-medium);
        }

        .priority-high {
            background: #FEE2E2;
            color: var(--color-error);
        }

        .priority-medium {
            background: #FEF3C7;
            color: var(--color-warning);
        }

        .priority-low {
            background: #DBEAFE;
            color: var(--color-info);
        }

        .task-assignee {
            display: flex;
            align-items: center;
            gap: var(--space-1);
        }

        .assignee-avatar {
            width: 24px;
            height: 24px;
            border-radius: var(--radius-full);
            background: var(--color-primary);
            color: var(--color-white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: var(--font-semibold);
        }

        /* Modal Styles */
        .task-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
        }

        .modal-dialog {
            position: relative;
            background: var(--color-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-2xl);
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 1001;
            animation: modalSlideIn 0.3s ease-out;
        }

        .modal-dialog.modal-sm {
            max-width: 400px;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: var(--space-6);
            border-bottom: 1px solid var(--color-gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: var(--text-xl);
            font-weight: var(--font-semibold);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            line-height: 1;
            color: var(--color-gray-500);
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
        }

        .modal-close:hover {
            background: var(--color-gray-100);
            color: var(--color-gray-900);
        }

        .modal-body {
            padding: var(--space-6);
        }

        .modal-footer {
            padding: var(--space-6);
            border-top: 1px solid var(--color-gray-200);
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
        }

        /* Form Styles */
        #taskForm {
            padding: var(--space-6);
        }
        #assigneeForm {
            padding: var(--space-6);
        }

        .form-group {
            margin-bottom: var(--space-4);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-4);
        }

        @media (max-width: 560px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        /* Assignee progress UI */
        .assignee-progress-wrap {
            display: grid;
            gap: var(--space-3);
        }

        .assignee-progress-steps {
            display: flex;
            gap: var(--space-2);
            flex-wrap: wrap;
        }

        .assignee-step-btn {
            border: 1px solid var(--color-gray-300);
            background: var(--color-white);
            color: var(--color-gray-800);
            border-radius: var(--radius-md);
            padding: 8px 10px;
            font-size: var(--text-sm);
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .assignee-step-btn.active {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }

        .assignee-progress-bar {
            height: 12px;
            border-radius: 999px;
            background: var(--color-gray-200);
            overflow: hidden;
            position: relative;
            cursor: pointer;
        }

        .assignee-progress-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--color-primary), #60a5fa);
            border-radius: 999px;
            transition: width 160ms ease;
        }

        .assignee-progress-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: var(--space-3);
            font-size: var(--text-sm);
            color: var(--color-gray-700);
        }

        .form-group label {
            display: block;
            font-weight: var(--font-medium);
            margin-bottom: var(--space-2);
            color: var(--color-gray-700);
        }

        .form-control {
            width: 100%;
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-text {
            display: block;
            margin-top: var(--space-1);
            font-size: var(--text-xs);
            color: var(--color-gray-600);
        }

        /* Alert Styles */
        .alert {
            padding: var(--space-4);
            border-radius: var(--radius-md);
            border-left: 4px solid;
        }

        .alert-warning {
            background: #FEF3C7;
            border-left-color: #F59E0B;
            color: #92400E;
        }

        .alert-content {
            display: flex;
            align-items: flex-start;
            gap: var(--space-3);
        }

        .alert-icon {
            width: 24px;
            height: 24px;
            flex-shrink: 0;
        }

        .alert-link {
            color: #92400E;
            text-decoration: underline;
            font-weight: var(--font-medium);
        }

        .alert-link:hover {
            color: #78350F;
        }

        /* Task Card Enhancements */
        .task-card {
            position: relative;
        }

        .task-card-actions {
            position: absolute;
            top: var(--space-2);
            right: var(--space-2);
            display: none;
            gap: var(--space-1);
        }

        .task-card:hover .task-card-actions {
            display: flex;
        }

        .task-card-btn {
            width: 24px;
            height: 24px;
            padding: 0;
            border: none;
            background: rgba(255, 255, 255, 0.9);
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-fast);
        }

        .task-card-btn:hover {
            background: var(--color-white);
            box-shadow: var(--shadow-sm);
        }

        .task-card-btn.btn-edit {
            color: var(--color-primary);
        }

        .task-card-btn.btn-delete {
            color: var(--color-error);
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: var(--space-6);
            right: var(--space-6);
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
        }

        .toast {
            min-width: 300px;
            padding: var(--space-4);
            background: var(--color-white);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: var(--space-3);
            animation: toastSlideIn 0.3s ease-out;
        }

        @keyframes toastSlideIn {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .toast.toast-success {
            border-left: 4px solid #10B981;
        }

        .toast.toast-error {
            border-left: 4px solid #EF4444;
        }

        .toast.toast-info {
            border-left: 4px solid #3B82F6;
        }

        /* Drag and Drop Visual Feedback */
        .task-column.drag-over {
            background: var(--color-gray-100);
            border: 2px dashed var(--color-primary);
        }

        .task-card.dragging {
            opacity: 0.5;
            transform: rotate(3deg);
        }

        /* Loading State */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid var(--color-gray-200);
            border-top-color: var(--color-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: var(--space-8);
            color: var(--color-gray-500);
        }

        .empty-state-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto var(--space-4);
            opacity: 0.5;
        }

        /* Fix for text-muted */
        .text-muted {
            color: var(--color-gray-500);
        }

        /* Fix for toast icon */
        .toast-icon {
            font-size: 20px;
            font-weight: bold;
        }

        /* Fix for text-warning */
        .text-warning {
            color: var(--color-warning);
        }

        /* Fix for btn-secondary and btn-danger */
        .btn-secondary {
            background: var(--color-gray-200);
            color: var(--color-gray-900);
            border: 1px solid var(--color-gray-300);
        }

        .btn-secondary:hover {
            background: var(--color-gray-300);
        }

        .btn-danger {
            background: var(--color-error);
            color: var(--color-white);
            border: 1px solid var(--color-error);
        }

        .btn-danger:hover {
            background: #DC2626;
        }
    </style>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
            <div class="header">
                <h1 class="page-title">Gestione Task</h1>
                <div class="flex items-center gap-4">
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(['no_styles' => true, 'no_scripts' => false]); ?>
                    <?php endif; ?>
                    <button class="btn btn-success" id="newTaskBtn" onclick="taskManager.openNewTaskModal()">+ Nuovo Task</button>
                </div>
            </div>

            <div class="page-content">
                <!-- Super Admin: company selection required for create/assign -->
                <?php if (($currentUser['role'] ?? '') === 'super_admin'): ?>
                    <?php $activeCompanyId = $_SESSION['company_filter_id'] ?? null; ?>
                    <?php if ($activeCompanyId === null): ?>
                        <div class="alert alert-warning" style="margin-bottom: var(--space-4);">
                            <div class="alert-content">
                                <div class="alert-text">
                                    <strong>Sei in modalità multi-azienda.</strong>
                                    Per creare/modificare/assegnare task seleziona prima un’azienda dal filtro in alto.
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <!-- Orphaned Tasks Warning Banner -->
                <div id="orphanedWarning" class="alert alert-warning" style="display: none; margin-bottom: var(--space-4);">
                    <div class="alert-content">
                        <svg class="alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        <div class="alert-text">
                            <strong>Attenzione:</strong>
                            <span id="orphanedCount">0</span> task senza utente assegnato valido.
                            <a href="#" onclick="taskManager.showOrphanedTasks(event)" class="alert-link">Visualizza e correggi</a>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="tasks-filters">
                    <button class="filter-btn active">Tutti</button>
                    <button class="filter-btn">I Miei Task</button>
                    <button class="filter-btn">Alta Priorità</button>
                    <button class="filter-btn">Scadenza Oggi</button>
                    <button class="filter-btn">Completati</button>
                </div>

                <!-- Task Board -->
                <div class="tasks-board" id="taskBoard">
                    <!-- To Do Column -->
                    <div class="task-column" data-status="todo">
                        <div class="column-header">
                            <span>Da Fare</span>
                            <span class="task-count" id="count-todo">0</span>
                        </div>
                        <div class="task-list" id="tasks-todo">
                            <!-- Tasks loaded dynamically -->
                        </div>
                    </div>

                    <!-- In Progress Column -->
                    <div class="task-column" data-status="in_progress">
                        <div class="column-header">
                            <span>In Corso</span>
                            <span class="task-count" id="count-in_progress">0</span>
                        </div>
                        <div class="task-list" id="tasks-in_progress">
                            <!-- Tasks loaded dynamically -->
                        </div>
                    </div>

                    <!-- Review Column -->
                    <div class="task-column" data-status="review">
                        <div class="column-header">
                            <span>In Revisione</span>
                            <span class="task-count" id="count-review">0</span>
                        </div>
                        <div class="task-list" id="tasks-review">
                            <!-- Tasks loaded dynamically -->
                        </div>
                    </div>

                    <!-- Done Column -->
                    <div class="task-column" data-status="done">
                        <div class="column-header">
                            <span>Completati</span>
                            <span class="task-count" id="count-done">0</span>
                        </div>
                        <div class="task-list" id="tasks-done">
                            <!-- Tasks loaded dynamically -->
                        </div>
                    </div>
                </div>
            </div>

    <!-- Hidden CSRF token -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <script>
        // Expose role + active company filter to JS
        window.userRole = '<?php echo htmlspecialchars($currentUser['role'] ?? 'user'); ?>';
        window.currentUserId = <?php echo (int)($currentUser['id'] ?? 0); ?>;
        window.activeCompanyFilterId = <?php echo isset($_SESSION['company_filter_id']) && $_SESSION['company_filter_id'] !== null ? (int)$_SESSION['company_filter_id'] : 'null'; ?>;
        window.activeCompanyFilterName = '<?php echo htmlspecialchars($_SESSION['company_filter_name'] ?? 'Tutte le aziende'); ?>';
    </script>

    <!-- Task Create/Edit Modal -->
    <div id="taskModal" class="task-modal" style="display: none;">
        <div class="modal-overlay" onclick="taskManager.closeModal()"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h2 id="modalTitle">Nuovo Task</h2>
                <button class="modal-close" onclick="taskManager.closeModal()">&times;</button>
            </div>
            <form id="taskForm" onsubmit="taskManager.submitTask(event)">
                <input type="hidden" id="taskId" name="id">

                <div class="form-group">
                    <label for="taskTitle">Titolo*</label>
                    <input type="text" id="taskTitle" name="title" class="form-control" required maxlength="200">
                </div>

                <div class="form-group">
                    <label for="taskDescription">Descrizione</label>
                    <textarea id="taskDescription" name="description" class="form-control" rows="4" maxlength="2000"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="taskStatus">Stato</label>
                        <select id="taskStatus" name="status" class="form-control">
                            <option value="todo">Da Fare</option>
                            <option value="in_progress">In Corso</option>
                            <option value="review">In Revisione</option>
                            <option value="done">Completato</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="taskPriority">Priorità</label>
                        <select id="taskPriority" name="priority" class="form-control">
                            <option value="low">Bassa</option>
                            <option value="medium" selected>Media</option>
                            <option value="high">Alta</option>
                            <option value="critical">Critica</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="taskDueDate">Scadenza</label>
                        <input type="date" id="taskDueDate" name="due_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="taskProgress">Progresso (%)</label>
                        <input type="number" id="taskProgress" name="progress" class="form-control" min="0" max="100" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label for="taskAssignees">Assegna a</label>
                    <select id="taskAssignees" name="assignees[]" class="form-control" multiple size="5">
                        <!-- Populated dynamically -->
                    </select>
                    <small class="form-text">Tieni premuto Ctrl per selezionare più utenti</small>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="taskManager.closeModal()">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva Task</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assignee Update Modal (progress/comment/due date request only) -->
    <div id="assigneeModal" class="task-modal" style="display: none;">
        <div class="modal-overlay" onclick="taskManager.closeAssigneeModal()"></div>
        <div class="modal-dialog">
            <div class="modal-header">
                <h2>Aggiorna Task</h2>
                <button class="modal-close" onclick="taskManager.closeAssigneeModal()">&times;</button>
            </div>
            <form id="assigneeForm" onsubmit="taskManager.submitAssigneeUpdate(event)">
                <input type="hidden" id="assigneeTaskId" name="id">

                <div class="form-group">
                    <label for="assigneeProgress">Avanzamento (%)</label>
                    <input type="hidden" id="assigneeProgress" name="progress" value="0">
                    <div class="assignee-progress-wrap">
                        <div class="assignee-progress-meta">
                            <div>Progresso: <strong id="assigneeProgressLabel">0%</strong></div>
                            <div class="text-muted" style="font-size: 12px;">Step: 25/50/75/100</div>
                        </div>
                        <div class="assignee-progress-bar" id="assigneeProgressBar" title="Clicca per selezionare uno step">
                            <div class="assignee-progress-fill" id="assigneeProgressFill"></div>
                        </div>
                        <div class="assignee-progress-steps" role="group" aria-label="Step progresso">
                            <button type="button" class="assignee-step-btn" data-progress-step="25">25%</button>
                            <button type="button" class="assignee-step-btn" data-progress-step="50">50%</button>
                            <button type="button" class="assignee-step-btn" data-progress-step="75">75%</button>
                            <button type="button" class="assignee-step-btn" data-progress-step="100">100%</button>
                        </div>
                        <small class="form-text">Puoi aggiornare solo l’avanzamento e lasciare commenti. Non puoi cambiare titolo/stato/priorità/assegnatari. A 100% il task viene chiuso.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="assigneeComment">Commento</label>
                    <textarea id="assigneeComment" name="comment" class="form-control" rows="4" maxlength="2000" placeholder="Scrivi un commento..."></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="assigneeRequestedDueDate">Richiedi nuova scadenza</label>
                        <input type="date" id="assigneeRequestedDueDate" name="requested_due_date" class="form-control">
                        <small class="form-text">Questa richiesta non modifica automaticamente la scadenza: verrà notificata al creatore.</small>
                    </div>
                    <div class="form-group">
                        <label for="assigneeRequestReason">Motivo (opzionale)</label>
                        <input type="text" id="assigneeRequestReason" name="request_reason" class="form-control" maxlength="255" placeholder="Motivo richiesta...">
                    </div>
                </div>

                <div class="form-group" id="assigneeReopenReasonGroup" style="display:none;">
                    <label for="assigneeReopenReason">Motivazione riapertura*</label>
                    <input type="text" id="assigneeReopenReason" name="reopen_reason" class="form-control" maxlength="255" placeholder="Indica la motivazione per riaprire il task...">
                    <small class="form-text">Obbligatorio se riduci il progresso sotto 100% dopo aver chiuso il task.</small>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="taskManager.closeAssigneeModal()">Annulla</button>
                    <button type="submit" class="btn btn-primary">Invia</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="task-modal" style="display: none;">
        <div class="modal-overlay" onclick="taskManager.closeDeleteModal()"></div>
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h2>Conferma Eliminazione</h2>
                <button class="modal-close" onclick="taskManager.closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Sei sicuro di voler eliminare questo task?</p>
                <p class="text-warning"><strong>Questa azione non può essere annullata.</strong></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="taskManager.closeDeleteModal()">Annulla</button>
                <button class="btn btn-danger" onclick="taskManager.confirmDelete()">Elimina</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toastContainer" class="toast-container"></div>

    <script src="assets/js/tasks.js?v=<?php echo time(); ?>_v11"></script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>