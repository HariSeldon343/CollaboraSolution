<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';
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
checkPageAccess('ticket');

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Ticket - Nexio';
    $pageCss = ['assets/css/dashboard.css'];
    require __DIR__ . '/includes/layout_head.php';
?>

    <style>
        /* Sidebar CSS is centralized in assets/css/styles.css */

        /* Page specific styles */
        .tickets-container {
            padding: var(--space-6);
        }

        .tickets-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-8);
        }

        .tickets-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-8);
        }

        .stat-card {
            background: var(--color-white);
            padding: var(--space-4);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .stat-label {
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--color-gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: var(--space-1);
        }

        .stat-value {
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            color: var(--color-gray-900);
        }

        .tickets-filters {
            display: flex;
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }

        .filter-group {
            display: flex;
            gap: var(--space-2);
        }

        .tickets-table {
            background: var(--color-white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: var(--space-4);
            background: var(--color-gray-50);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--color-gray-700);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        td {
            padding: var(--space-4);
            border-top: 1px solid var(--color-gray-200);
        }

        tr:hover {
            background: var(--color-gray-50);
        }

        .priority-badge {
            display: inline-block;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--radius-sm);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
        }

        .priority-alta { background: var(--color-error-100); color: var(--color-error); }
        .priority-media { background: var(--color-warning-100); color: var(--color-warning); }
        .priority-bassa { background: var(--color-success-100); color: var(--color-success); }

        .status-badge {
            display: inline-block;
            padding: var(--space-1) var(--space-2);
            border-radius: var(--radius-sm);
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
        }

        .status-aperto { background: var(--color-primary-100); color: var(--color-primary); }
        .status-in-corso { background: var(--color-warning-100); color: var(--color-warning); }
        .status-chiuso { background: var(--color-gray-200); color: var(--color-gray-600); }
        .status-risolto { background: var(--color-success-100); color: var(--color-success); }

        .ticket-actions {
            display: flex;
            gap: var(--space-2);
        }

        .action-btn {
            padding: var(--space-1) var(--space-2);
            border: none;
            background: transparent;
            color: var(--color-gray-600);
            cursor: pointer;
            border-radius: var(--radius-sm);
            transition: all var(--transition-fast);
        }

        .action-btn:hover {
            background: var(--color-gray-100);
            color: var(--color-primary);
        }

        /* ========================================
           MODAL STYLES FOR TICKET CREATION
           ======================================== */

        /* Modal Overlay */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            backdrop-filter: blur(4px);
            animation: modalFadeIn 0.2s ease-out;
        }

        /* Modal when hidden */
        .modal[style*="display: none"] {
            animation: none;
        }

        /* Modal Content Box */
        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: modalSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Modal Header */
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #E5E7EB;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #1F2937;
        }

        /* Close Button */
        .modal-close {
            background: none;
            border: none;
            padding: 8px;
            cursor: pointer;
            color: #6B7280;
            transition: all 0.2s;
            border-radius: 6px;
            font-size: 24px;
            line-height: 1;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            color: #1F2937;
            background: #F3F4F6;
        }

        /* Modal Body */
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }

        /* Form Groups in Modal */
        .modal-body .form-group {
            margin-bottom: 20px;
        }

        .modal-body .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
        }

        .modal-body .form-control {
            width: 100%;
            padding: 10px 12px;
            font-size: 14px;
            line-height: 1.5;
            color: #1F2937;
            background-color: white;
            border: 1px solid #D1D5DB;
            border-radius: 6px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            box-sizing: border-box;
        }

        .modal-body .form-control:focus {
            outline: 0;
            border-color: #2563EB;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .modal-body textarea.form-control {
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }

        .modal-body select.form-control {
            cursor: pointer;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 20px;
            padding-right: 40px;
        }

        .modal-body small.text-muted {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: #6B7280;
        }

        /* Alert Box in Modal */
        .modal-body .alert {
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 14px;
            margin-top: 16px;
        }

        .modal-body .alert-danger {
            background-color: #FEE2E2;
            border: 1px solid #FECACA;
            color: #991B1B;
        }

        /* Modal Footer */
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #E5E7EB;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-shrink: 0;
        }

        /* Buttons in Modal */
        .modal-footer .btn {
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .modal-footer .btn--primary {
            background-color: #2563EB;
            color: white;
            border-color: #2563EB;
        }

        .modal-footer .btn--primary:hover {
            background-color: #1D4ED8;
            border-color: #1D4ED8;
        }

        .modal-footer .btn--secondary {
            background-color: white;
            color: #374151;
            border-color: #D1D5DB;
        }

        .modal-footer .btn--secondary:hover {
            background-color: #F9FAFB;
            border-color: #9CA3AF;
        }

        /* Icon in button */
        .modal-footer .btn .icon {
            width: 16px;
            height: 16px;
        }

        .modal-footer .btn .icon--save::before {
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpath d='M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z'/%3E%3Cpolyline points='17 21 17 13 7 13 7 21'/%3E%3Cpolyline points='7 3 7 8 15 8'/%3E%3C/svg%3E");
            -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'%3E%3Cpath d='M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z'/%3E%3Cpolyline points='17 21 17 13 7 13 7 21'/%3E%3Cpolyline points='7 3 7 8 15 8'/%3E%3C/svg%3E");
        }

        /* Animations */
        @keyframes modalFadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
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

        /* Responsive Design */
        @media (max-width: 640px) {
            .modal-content {
                width: 95%;
                max-width: none;
                margin: 16px;
                max-height: calc(100vh - 32px);
            }

            .modal-header {
                padding: 16px 20px;
            }

            .modal-body {
                padding: 20px;
            }

            .modal-footer {
                padding: 12px 20px;
                flex-direction: column-reverse;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Dark overlay adjustment for better contrast */
        @media (prefers-color-scheme: dark) {
            .modal {
                background: rgba(0, 0, 0, 0.7);
            }
        }
    </style>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
            <div class="header">
                <h1 class="page-title">Sistema Ticket</h1>
                <div class="flex items-center gap-4">
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>
                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-content">
                <div class="tickets-container">
                    <!-- Header with actions -->
                    <div class="tickets-header">
                        <h2>Gestione Ticket</h2>
                        <button class="btn btn--primary" id="create-ticket-btn">
                            <i class="icon icon--plus"></i> Nuovo Ticket
                        </button>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="tickets-stats">
                        <div class="stat-card">
                            <div class="stat-label">Ticket Aperti</div>
                            <div class="stat-value">12</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">In Lavorazione</div>
                            <div class="stat-value">8</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Risolti Oggi</div>
                            <div class="stat-value">5</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Tempo Medio Risoluzione</div>
                            <div class="stat-value">2.5h</div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="tickets-filters">
                        <div class="filter-group">
                            <select id="assigned-filter" class="form-control">
                                <option value="">Tutti i ticket</option>
                                <option value="mine">I miei ticket</option>
                                <option value="assigned">Assegnati a me</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <select id="status-filter" class="form-control">
                                <option value="">Tutti gli stati</option>
                                <option value="open">Aperti</option>
                                <option value="in_progress">In Lavorazione</option>
                                <option value="waiting_customer,waiting_staff">In Attesa</option>
                                <option value="resolved">Risolti</option>
                                <option value="closed">Chiusi</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <select id="priority-filter" class="form-control">
                                <option value="">Tutte le urgenze</option>
                                <option value="critical">Critica</option>
                                <option value="high">Alta</option>
                                <option value="medium">Normale</option>
                                <option value="low">Bassa</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <input type="text" id="search-input" class="form-control" placeholder="Cerca ticket...">
                        </div>
                    </div>

                    <!-- Tickets Table -->
                    <div class="tickets-table">
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Oggetto</th>
                                        <th>Categoria</th>
                                        <th>Priorità</th>
                                        <th>Stato</th>
                                        <th>Assegnato a</th>
                                        <th>Creato</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>#1234</td>
                                        <td>Problema accesso sistema</td>
                                        <td>Tecnico</td>
                                        <td><span class="priority-badge priority-alta">Alta</span></td>
                                        <td><span class="status-badge status-aperto">Aperto</span></td>
                                        <td>Mario Rossi</td>
                                        <td>10 min fa</td>
                                        <td>
                                            <div class="ticket-actions">
                                                <button class="action-btn" title="Visualizza"><i class="icon icon--eye"></i></button>
                                                <button class="action-btn" title="Modifica"><i class="icon icon--edit"></i></button>
                                                <button class="action-btn" title="Assegna"><i class="icon icon--user-plus"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>#1233</td>
                                        <td>Richiesta nuovo accesso</td>
                                        <td>Amministrativo</td>
                                        <td><span class="priority-badge priority-media">Media</span></td>
                                        <td><span class="status-badge status-in-corso">In corso</span></td>
                                        <td>Laura Bianchi</td>
                                        <td>2 ore fa</td>
                                        <td>
                                            <div class="ticket-actions">
                                                <button class="action-btn" title="Visualizza"><i class="icon icon--eye"></i></button>
                                                <button class="action-btn" title="Modifica"><i class="icon icon--edit"></i></button>
                                                <button class="action-btn" title="Assegna"><i class="icon icon--user-plus"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>#1232</td>
                                        <td>Aggiornamento software</td>
                                        <td>IT</td>
                                        <td><span class="priority-badge priority-bassa">Bassa</span></td>
                                        <td><span class="status-badge status-risolto">Risolto</span></td>
                                        <td>Giuseppe Verdi</td>
                                        <td>1 giorno fa</td>
                                        <td>
                                            <div class="ticket-actions">
                                                <button class="action-btn" title="Visualizza"><i class="icon icon--eye"></i></button>
                                                <button class="action-btn" title="Riapri"><i class="icon icon--refresh"></i></button>
                                                <button class="action-btn" title="Chiudi"><i class="icon icon--x"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Ticket Modal -->
    <div id="create-ticket-modal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3>Nuovo Ticket di Supporto</h3>
                <button class="modal-close" onclick="window.ticketManager.closeCreateModal()">&times;</button>
            </div>
            <form id="create-ticket-form" onsubmit="window.ticketManager.handleCreateSubmit(event); return false;">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="ticket-subject">Oggetto *</label>
                        <input type="text" id="ticket-subject" name="subject" class="form-control" required maxlength="200" placeholder="Breve descrizione del problema">
                    </div>

                    <div class="form-group">
                        <label for="ticket-category">Categoria *</label>
                        <select id="ticket-category" name="category" class="form-control" required>
                            <option value="">Seleziona categoria...</option>
                            <option value="technical">Tecnico</option>
                            <option value="billing">Fatturazione</option>
                            <option value="feature_request">Richiesta Funzionalità</option>
                            <option value="bug_report">Segnalazione Bug</option>
                            <option value="general">Generale</option>
                            <option value="other">Altro</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="ticket-urgency">Urgenza *</label>
                        <!-- BUG-145b FIX: Uses 'medium' to match database ENUM (API fixed to accept 'medium') -->
                        <select id="ticket-urgency" name="urgency" class="form-control" required>
                            <option value="medium" selected>Normale</option>
                            <option value="low">Bassa</option>
                            <option value="high">Alta</option>
                            <option value="critical">Critica</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="ticket-description">Descrizione Dettagliata *</label>
                        <textarea id="ticket-description" name="description" class="form-control" rows="6" required placeholder="Descrivi il problema in dettaglio..."></textarea>
                        <small class="text-muted">Fornisci quante più informazioni possibili per aiutarci a risolvere il problema</small>
                    </div>

                    <!-- FEATURE: Ticket Attachment - Allow single file upload -->
                    <div class="form-group">
                        <label for="ticket-attachment">Allegato (opzionale)</label>
                        <input type="file" id="ticket-attachment" name="attachment" class="form-control"
                               accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt"
                               style="padding: 8px; border: 1px dashed #d1d5db; border-radius: 6px; background: #f9fafb;">
                        <small class="text-muted">Max 5MB. Formati: immagini (JPG, PNG, GIF), PDF, Word (DOC, DOCX), testo (TXT)</small>
                        <div id="attachment-preview" style="display: none; margin-top: 8px; padding: 8px; background: #e5e7eb; border-radius: 4px; font-size: 13px;">
                            <span id="attachment-filename"></span>
                            <span id="attachment-size" style="color: #6b7280; margin-left: 8px;"></span>
                            <button type="button" onclick="window.ticketManager.clearAttachment()" style="margin-left: 8px; background: none; border: none; color: #dc2626; cursor: pointer; font-weight: 600;">&times;</button>
                        </div>
                        <div id="attachment-error" class="text-danger" style="display: none; margin-top: 4px; font-size: 12px;"></div>
                    </div>

                    <div id="create-ticket-error" class="alert alert-danger" style="display: none;"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn--secondary" onclick="window.ticketManager.closeCreateModal()">Annulla</button>
                    <button type="submit" class="btn btn--primary" id="create-ticket-submit-btn">
                        <i class="icon icon--save"></i> Crea Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Ticket Detail Modal - Redesigned Compact Layout -->
    <div id="ticket-detail-modal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 1200px; width: 95%; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-header" style="flex-shrink: 0; padding: 16px 24px; border-bottom: 2px solid #E5E7EB;">
                <div style="flex: 1;">
                    <h3 id="detail-modal-title" style="margin: 0; font-size: 20px;">Dettaglio Ticket</h3>
                    <div id="detail-ticket-number" style="font-size: 13px; color: #6B7280; margin-top: 2px;"></div>
                </div>
                <button class="modal-close" onclick="window.ticketManager.closeTicketDetailModal()" style="font-size: 28px;">&times;</button>
            </div>

            <div class="modal-body" style="flex: 1; overflow: hidden; padding: 0; display: flex; gap: 0;">

                <!-- LEFT COLUMN: Ticket Info & Actions (35%) -->
                <div style="width: 35%; border-right: 2px solid #E5E7EB; padding: 20px; overflow-y: auto; background: #F9FAFB;">

                    <!-- Ticket Header -->
                    <div style="margin-bottom: 20px;">
                        <h2 id="detail-ticket-subject" style="margin: 0 0 10px 0; font-size: 20px; color: #1F2937; line-height: 1.3;"></h2>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px;">
                            <span id="detail-ticket-status-badge" class="status-badge"></span>
                            <span id="detail-ticket-urgency-badge" class="priority-badge"></span>
                            <span id="detail-ticket-category-badge" style="display: inline-block; padding: 3px 8px; background: #E5E7EB; color: #374151; border-radius: 4px; font-size: 11px; font-weight: 500;"></span>
                        </div>
                    </div>

                    <!-- Metadata Compact -->
                    <div style="margin-bottom: 20px; display: flex; flex-direction: column; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 8px; padding: 8px; background: white; border-radius: 6px;">
                            <span style="font-size: 11px; font-weight: 600; color: #6B7280; width: 80px;">Creato da:</span>
                            <span id="detail-ticket-creator" style="font-size: 13px; color: #1F2937; font-weight: 500;"></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; padding: 8px; background: white; border-radius: 6px;">
                            <span style="font-size: 11px; font-weight: 600; color: #6B7280; width: 80px;">Assegnato:</span>
                            <span id="detail-ticket-assigned" style="font-size: 13px; color: #1F2937; font-weight: 500;"></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; padding: 8px; background: white; border-radius: 6px;">
                            <span style="font-size: 11px; font-weight: 600; color: #6B7280; width: 80px;">Creato:</span>
                            <span id="detail-ticket-created" style="font-size: 13px; color: #1F2937;"></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; padding: 8px; background: white; border-radius: 6px;">
                            <span style="font-size: 11px; font-weight: 600; color: #6B7280; width: 80px;">Aggiornato:</span>
                            <span id="detail-ticket-updated" style="font-size: 13px; color: #1F2937;"></span>
                        </div>
                    </div>

                    <!-- Description -->
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin: 0 0 8px 0; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Descrizione</h4>
                        <div id="detail-ticket-description" style="padding: 12px; background: white; border-radius: 6px; font-size: 13px; line-height: 1.5; color: #1F2937; white-space: pre-wrap; max-height: 150px; overflow-y: auto;"></div>
                    </div>

                    <!-- FEATURE: Ticket Attachment Display -->
                    <div id="detail-ticket-attachment-section" style="display: none; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 8px 0; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase;">Allegato</h4>
                        <div id="detail-ticket-attachment" style="padding: 12px; background: white; border-radius: 6px; display: flex; align-items: center; gap: 10px;">
                            <span id="detail-attachment-icon" style="font-size: 24px;"></span>
                            <div style="flex: 1; overflow: hidden;">
                                <div id="detail-attachment-name" style="font-size: 13px; font-weight: 500; color: #1F2937; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></div>
                                <div id="detail-attachment-info" style="font-size: 11px; color: #6B7280;"></div>
                            </div>
                            <a id="detail-attachment-download" href="#" target="_blank" style="padding: 6px 12px; background: #3B82F6; color: white; border-radius: 4px; font-size: 12px; font-weight: 500; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                <i class="icon icon--download" style="font-size: 14px;"></i> Scarica
                            </a>
                        </div>
                    </div>

                    <!-- Admin Actions -->
                    <div id="detail-admin-actions" style="display: none;">
                        <h4 style="margin: 0 0 12px 0; font-size: 12px; font-weight: 600; color: #374151; text-transform: uppercase; padding-top: 16px; border-top: 2px solid #E5E7EB;">Azioni Admin</h4>

                        <!-- Change Status -->
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; margin-bottom: 4px; font-size: 11px; font-weight: 600; color: #6B7280;">Cambia Stato</label>
                            <select id="detail-change-status" class="form-control" style="font-size: 13px; padding: 8px;">
                                <option value="open">Aperto</option>
                                <option value="in_progress">In Lavorazione</option>
                                <option value="waiting_response">In Attesa di Risposta</option>
                                <option value="resolved">Risolto</option>
                                <option value="closed">Chiuso</option>
                            </select>
                        </div>

                        <!-- Assign Ticket -->
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; margin-bottom: 4px; font-size: 11px; font-weight: 600; color: #6B7280;">Assegna a</label>
                            <select id="detail-assign-to" class="form-control" style="font-size: 13px; padding: 8px;">
                                <option value="">Seleziona utente...</option>
                                <!-- Users will be populated dynamically -->
                            </select>
                        </div>

                        <div id="detail-pending-updates-hint" style="display:none; margin-top: 10px; padding: 10px; background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 6px; color: #1E3A8A; font-size: 12px;">
                            <strong>Modifiche in sospeso</strong>: verranno applicate quando clicchi “Invia Risposta” oppure “Salva modifiche”.
                        </div>

                        <div style="margin-top: 10px;">
                            <button type="button" class="btn btn--secondary" id="detail-apply-updates-btn" style="width:100%;">
                                Salva modifiche
                            </button>
                        </div>

                        <!-- Delete Ticket Button -->
                        <div id="detail-delete-section" style="display: none; margin-top: 16px; padding-top: 16px; border-top: 2px solid #FEE2E2;">
                            <div style="background: #FEF2F2; border-left: 3px solid #DC2626; padding: 12px; border-radius: 4px; margin-bottom: 10px;">
                                <h5 style="margin: 0 0 6px 0; font-size: 12px; font-weight: 600; color: #991B1B;">⚠️ ZONA PERICOLOSA</h5>
                                <p style="margin: 0 0 8px 0; font-size: 11px; color: #7F1D1D; line-height: 1.4;">Eliminazione permanente. Solo ticket chiusi.</p>
                                <button id="detail-delete-btn" onclick="window.ticketManager.deleteTicket()" style="background: #DC2626; color: white; border: none; padding: 8px 16px; border-radius: 4px; font-size: 12px; font-weight: 600; cursor: pointer; width: 100%;" onmouseover="this.style.background='#B91C1C'" onmouseout="this.style.background='#DC2626'">
                                    🗑️ Elimina Ticket
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Conversation & Reply (65%) -->
                <div style="width: 65%; display: flex; flex-direction: column;">

                    <!-- Conversation Thread -->
                    <div style="flex: 1; overflow-y: auto; padding: 20px; background: white;">
                        <h4 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 600; color: #374151; text-transform: uppercase; display: flex; align-items: center; gap: 8px;">
                            <span>💬 Conversazione</span>
                            <span id="detail-response-count" style="display: inline-block; padding: 2px 8px; background: #2563EB; color: white; border-radius: 12px; font-size: 11px;">0</span>
                        </h4>
                        <div id="detail-responses-container" style="min-height: 200px;">
                            <!-- Responses will be inserted here dynamically -->
                            <div id="detail-no-responses" style="padding: 40px 20px; text-align: center; color: #9CA3AF; font-size: 13px;">
                                💬 Nessuna risposta ancora. Sii il primo a rispondere!
                            </div>
                        </div>
                    </div>

                    <!-- BUG-153 FIX: Message shown when ticket is closed -->
                    <div id="detail-ticket-closed-notice" style="display: none; flex-shrink: 0; padding: 16px 20px; background: #FEF3C7; border-top: 2px solid #F59E0B; text-align: center;">
                        <div style="display: flex; align-items: center; justify-content: center; gap: 8px; color: #92400E; font-size: 14px; font-weight: 500;">
                            <span style="font-size: 18px;">&#128274;</span>
                            <span>Questo ticket e stato chiuso. Non e possibile aggiungere ulteriori risposte.</span>
                        </div>
                    </div>

                    <!-- Reply Form (Fixed at bottom) -->
                    <div id="detail-reply-section" style="flex-shrink: 0; padding: 16px 20px; background: #F9FAFB; border-top: 2px solid #E5E7EB;">
                        <form id="ticket-reply-form" onsubmit="window.ticketManager.submitReply(event); return false;">
                            <div class="form-group" style="margin-bottom: 10px;">
                                <textarea id="reply-message" name="message" class="form-control" rows="3" required placeholder="Scrivi la tua risposta..." style="resize: vertical; min-height: 80px; font-size: 13px;"></textarea>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div id="detail-internal-note-section" style="display: none;">
                                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12px; color: #374151;">
                                        <input type="checkbox" id="reply-is-internal" name="is_internal" style="width: 16px; height: 16px; cursor: pointer;">
                                        <span>Nota interna</span>
                                    </label>
                                </div>
                                <button type="submit" class="btn btn--primary" id="reply-submit-btn" style="margin-left: auto; padding: 8px 16px; font-size: 13px;">
                                    <i class="icon icon--save"></i> Invia Risposta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

    <!-- Scripts -->
    <script src="assets/js/app.js"></script>
    <script src="assets/js/tickets.js?v=<?php echo time(); ?>"></script>
    <script>
        // Initialize TicketManager when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            console.log('[Ticket Page] Initializing TicketManager...');

            // Initialize ticket manager with user context
            if (typeof TicketManager !== 'undefined') {
                window.ticketManager = new TicketManager({
                    userRole: '<?php echo htmlspecialchars($currentUser['role'], ENT_QUOTES); ?>',
                    userId: <?php echo (int)$currentUser['id']; ?>,
                    userName: '<?php echo htmlspecialchars($currentUser['name'], ENT_QUOTES); ?>'
                });
                console.log('[Ticket Page] TicketManager initialized successfully with role: <?php echo $currentUser['role']; ?>');
            } else {
                console.error('[Ticket Page] TicketManager class not found. Check tickets.js is loaded.');
            }

            // Initialize company filter if present
            <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'super_admin'): ?>
            const companySelector = document.getElementById('company-filter');
            if (companySelector) {
                companySelector.addEventListener('change', function() {
                    console.log('[Company Filter] Company changed:', this.value);
                    // Reload tickets for new company if needed
                    if (window.ticketManager) {
                        window.ticketManager.loadTickets();
                        window.ticketManager.loadStats();
                    }
                });
            }
            <?php endif; ?>
        });
    </script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>