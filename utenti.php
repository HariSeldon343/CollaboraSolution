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
checkPageAccess('utenti');

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();

$currentUserRole = $currentUser['role'] ?? 'user';
$currentTenantId = (int)($currentUser['tenant_id'] ?? 0);

// Prevent HTML caching (debug/consistency across tunnel/prod)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Build marker to verify served version in "View Source"
$cnxBuildId = 'utenti.php@' . (string)@filemtime(__FILE__);
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Gestione Utenti - Nexio';
    $pageCss = ['assets/css/dashboard.css'];
    require __DIR__ . '/includes/layout_head.php';
?>

    <!-- CNX_BUILD_ID: <?php echo htmlspecialchars($cnxBuildId); ?> -->

    <style>
        /* Additional user management specific styles */
        .users-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-6);
        }

        /* Multi-select checkbox styles - Enhanced */
        .tenant-checkbox-list {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--color-gray-200);
            border-radius: var(--radius-lg);
            padding: var(--space-3);
            background: var(--color-gray-50);
        }

        .tenant-checkbox-list::-webkit-scrollbar {
            width: 8px;
        }

        .tenant-checkbox-list::-webkit-scrollbar-track {
            background: var(--color-gray-100);
            border-radius: var(--radius-full);
        }

        .tenant-checkbox-list::-webkit-scrollbar-thumb {
            background: var(--color-gray-400);
            border-radius: var(--radius-full);
        }

        .tenant-checkbox-list::-webkit-scrollbar-thumb:hover {
            background: var(--color-gray-500);
        }

        .tenant-checkbox-item {
            display: flex;
            align-items: center;
            padding: var(--space-3);
            margin-bottom: var(--space-2);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
            background: var(--color-white);
            border: 1px solid var(--color-gray-200);
            position: relative;
        }

        .tenant-checkbox-item:last-child {
            margin-bottom: 0;
        }

        .tenant-checkbox-item:hover {
            background: linear-gradient(to right, rgba(59, 130, 246, 0.05), rgba(59, 130, 246, 0.02));
            border-color: var(--color-primary);
            transform: translateX(2px);
        }

        .tenant-checkbox-item.checked {
            background: linear-gradient(to right, rgba(59, 130, 246, 0.1), rgba(59, 130, 246, 0.05));
            border-color: var(--color-primary);
        }

        .tenant-checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: var(--space-3);
            cursor: pointer;
            accent-color: var(--color-primary);
            flex-shrink: 0;
        }

        .tenant-checkbox-item label {
            cursor: pointer;
            flex: 1;
            margin: 0;
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-700);
            user-select: none;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .tenant-checkbox-item:hover label {
            color: var(--color-gray-900);
        }

        .tenant-checkbox-item.checked label {
            color: var(--color-primary);
            font-weight: var(--font-semibold);
        }

        .tenant-info {
            font-size: var(--text-xs);
            color: var(--color-gray-500);
            font-weight: var(--font-normal);
        }

        .tenant-checkbox-item.checked .tenant-info {
            color: var(--color-gray-600);
        }

        .tenant-checkbox-counter {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--color-primary);
            color: white;
            font-size: 11px;
            font-weight: var(--font-bold);
            padding: 2px 6px;
            border-radius: var(--radius-full);
            min-width: 20px;
            text-align: center;
            display: none;
        }

        .tenant-checkbox-list.has-selection .tenant-checkbox-counter {
            display: block;
        }

        .form-group-hidden {
            display: none !important;
        }

        .form-help-text {
            font-size: var(--text-xs);
            color: var(--color-gray-500);
            margin-top: var(--space-1);
        }

        .search-bar {
            position: relative;
            width: 300px;
        }

        .search-bar input {
            width: 100%;
            padding: var(--space-2) var(--space-10) var(--space-2) var(--space-3);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-icon {
            position: absolute;
            right: var(--space-3);
            top: 50%;
            transform: translateY(-50%);
            color: var(--color-gray-400);
            pointer-events: none;
        }

        .users-table {
            width: 100%;
            background: var(--color-white);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .users-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            padding: var(--space-3) var(--space-4);
            text-align: left;
            font-size: var(--text-xs);
            font-weight: var(--font-semibold);
            color: var(--color-gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: var(--color-gray-50);
            border-bottom: 1px solid var(--color-gray-200);
        }

        .users-table td {
            padding: var(--space-4);
            border-bottom: 1px solid var(--color-gray-200);
        }

        .users-table tbody tr:last-child td {
            border-bottom: none;
        }

        .users-table tbody tr:hover {
            background: var(--color-gray-50);
        }

        .user-info-cell {
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }

        .user-avatar-table {
            width: 36px;
            height: 36px;
            background: var(--color-primary);
            color: var(--color-white);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--text-sm);
            font-weight: var(--font-semibold);
            flex-shrink: 0;
        }

        .user-details-table {
            flex: 1;
        }

        .user-name-table {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
        }

        .user-email-table {
            font-size: var(--text-xs);
            color: var(--color-gray-600);
            margin-top: 2px;
        }

        .role-badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
            border-radius: var(--radius-sm);
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .role-badge.super-admin {
            background: #FEF3C7;
            color: #92400E;
        }

        .role-badge.admin {
            background: #DBEAFE;
            color: #1E3A8A;
        }

        .role-badge.user {
            background: #E0E7FF;
            color: #3730A3;
        }

        .role-badge.manager {
            background: #F3E8FF;
            color: #6B21A8;
        }

        /* Tenant Role Badge (Ruolo Aziendale) */
        .tenant-role-badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
            border-radius: var(--radius-sm);
            text-transform: none;
            letter-spacing: 0.02em;
            margin-left: 4px;
            /* Keep it visually aligned with .role-badge.user (avoid per-role rainbow colors) */
            background: #E0E7FF;
            color: #3730A3;
        }

        .tenant-role-badge.no-role {
            background: #F3F4F6;
            color: #6B7280;
            font-style: italic;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            font-size: var(--text-xs);
            font-weight: var(--font-medium);
            border-radius: var(--radius-sm);
        }

        .status-badge.active {
            background: #D1FAE5;
            color: #065F46;
        }

        .status-badge.inactive {
            background: #FEE2E2;
            color: #991B1B;
        }

        .status-indicator {
            width: 6px;
            height: 6px;
            border-radius: var(--radius-full);
            background: currentColor;
        }

        .action-buttons {
            display: flex;
            gap: var(--space-2);
        }

        .btn-icon {
            padding: var(--space-2);
            background: transparent;
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all var(--transition-fast);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-icon:hover {
            background: var(--color-gray-50);
            border-color: var(--color-gray-400);
        }

        .btn-icon.edit {
            color: var(--color-primary);
        }

        .btn-icon.delete {
            color: var(--color-error);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.2s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--space-4);
        }

        .modal-title {
            font-size: var(--text-xl);
            font-weight: var(--font-bold);
            color: var(--color-gray-900);
        }

        .modal-close {
            background: transparent;
            border: none;
            font-size: var(--text-2xl);
            color: var(--color-gray-400);
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
            color: var(--color-gray-600);
        }

        .modal-body {
            margin-bottom: var(--space-4);
        }

        .form-group {
            margin-bottom: var(--space-4);
        }

        .form-group label {
            display: block;
            margin-bottom: var(--space-2);
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-700);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--color-gray-300);
            border-radius: var(--radius-md);
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: var(--space-3);
        }

        .empty-state {
            text-align: center;
            padding: var(--space-12);
        }

        .empty-state-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto var(--space-4);
            opacity: 0.3;
        }

        .empty-state-text {
            color: var(--color-gray-600);
            font-size: var(--text-sm);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: var(--space-2);
            margin-top: var(--space-6);
        }

        .pagination button {
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--color-gray-300);
            background: var(--color-white);
            color: var(--color-gray-700);
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: var(--text-sm);
            transition: all var(--transition-fast);
        }

        .pagination button:hover:not(:disabled) {
            background: var(--color-gray-50);
            border-color: var(--color-gray-400);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination button.active {
            background: var(--color-primary);
            color: var(--color-white);
            border-color: var(--color-primary);
        }

        .toast {
            position: fixed;
            bottom: var(--space-6);
            right: var(--space-6);
            background: var(--color-gray-900);
            color: var(--color-white);
            padding: var(--space-3) var(--space-4);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            z-index: 2000;
            display: none;
            animation: slideInUp 0.3s ease-out;
        }

        .toast.show {
            display: block;
        }

        .toast.success {
            background: var(--color-success);
        }

        .toast.error {
            background: var(--color-error);
        }

        @keyframes slideInUp {
            from {
                transform: translateY(100px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
    </style>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
            <div class="header">
                <h1 class="page-title">Gestione Utenti</h1>
                <div class="flex items-center gap-4">
                    <?php if ($companyFilter->canUseCompanyFilter()): ?>
                        <?php echo $companyFilter->renderDropdown(); ?>
                    <?php endif; ?>
                    <span class="text-sm text-muted">Gestisci utenti e permessi</span>
                </div>
            </div>

            <div class="page-content">
                <!-- Header with search and add button -->
                <div class="users-header">
                    <div class="search-bar">
                        <input type="text" id="searchInput" placeholder="Cerca utenti..." />
                        <span class="search-icon">🔍</span>
                    </div>
                    <button class="btn btn-primary" onclick="openAddModal()">
                        + Nuovo Utente
                    </button>
                </div>

                <!-- Users table -->
                <div class="users-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Tipo Utente</th>
                                <th>Ruolo Aziendale</th>
                                <th>Azienda</th>
                                <th>Stato</th>
                                <th>Data Creazione</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <!-- Users will be loaded here via JavaScript -->
                        </tbody>
                    </table>
                    <div id="emptyState" class="empty-state" style="display: none;">
                        <div class="empty-state-icon">👥</div>
                        <div class="empty-state-text">Nessun utente trovato</div>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="pagination" id="pagination">
                    <!-- Pagination buttons will be loaded here via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Aggiungi Nuovo Utente</h2>
                <button class="modal-close" onclick="closeModal('addModal')">×</button>
            </div>
            <form id="addUserForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="addName">Nome Completo *</label>
                        <input type="text" id="addName" name="name" required placeholder="es. Mario Rossi" />
                    </div>
                    <div class="form-group">
                        <label for="addEmail">Email</label>
                        <input type="email" id="addEmail" name="email" required />
                        <div class="form-help-text">L'utente riceverà un'email con le istruzioni per impostare la password</div>
                    </div>
                    <div class="form-group">
                        <label for="addHomeCity">Città di residenza (opzionale)</label>
                        <input type="text" id="addHomeCity" name="home_city" placeholder="Es. Milano" />
                        <div class="form-help-text">Usata come default per “Città di partenza” nei piani (puoi sempre sovrascriverla nel piano).</div>
                    </div>
                    <div class="form-group">
                        <label for="addJobTitle">Titolo / Ruolo (opzionale)</label>
                        <input type="text" id="addJobTitle" name="job_title" placeholder="Es. Consulente Senior, Lead Auditor" />
                        <div class="form-help-text">Informazioni opzionali per migliorare l’allocazione dei consulenti nei piani.</div>
                    </div>
                    <div class="form-group">
                        <label for="addSkillsText">Competenze (opzionale)</label>
                        <textarea id="addSkillsText" name="skills_text" rows="3" placeholder="Es. ISO 9001, audit interni, sanità, formazione..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="addCertificationsText">Titoli / Certificazioni (opzionale)</label>
                        <textarea id="addCertificationsText" name="certifications_text" rows="3" placeholder="Es. Lead Auditor ISO 9001, Laurea..., ecc."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="addRole">Tipo Utente</label>
                        <select id="addRole" name="role" required>
                            <option value="user">Utente</option>
                            <?php if ($currentUserRole === 'manager'): ?>
                                <option value="manager">Manager</option>
                            <?php else: ?>
                                <option value="manager">Manager</option>
                                <option value="admin">Admin</option>
                                <option value="super_admin">Super Admin</option>
                            <?php endif; ?>
                        </select>
                        <?php if ($currentUserRole === 'manager'): ?>
                            <div class="form-help-text">I manager possono creare solo utenti o manager nella propria azienda (non admin/super admin).</div>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="addPasswordMaxAgeDays">Durata massima password (giorni)</label>
                        <input type="number" id="addPasswordMaxAgeDays" name="password_max_age_days" min="1" max="3650" value="90" />
                        <div class="form-help-text">Default 90. L’utente riceverà un avviso 1 giorno prima della scadenza.</div>
                    </div>
                    <div class="form-group" id="addTenantGroup">
                        <label for="addTenant" id="addTenantLabel">Azienda</label>
                        <div id="addTenantContainer">
                            <!-- Tenant selector will be dynamically generated here -->
                        </div>
                        <div class="form-help-text" id="addTenantHelp"></div>
                    </div>
                    <!-- Tenant Role (Ruolo Aziendale) - shown only when tenant has custom roles -->
                    <div class="form-group" id="addTenantRoleGroup" style="display: none;">
                        <label for="addTenantRole">Ruolo Aziendale</label>
                        <select id="addTenantRole" name="tenant_role_ids[]" multiple>
                            <!-- Options will be dynamically populated -->
                        </select>
                        <div class="form-help-text">Seleziona uno o più ruoli aziendali (Ctrl/Cmd per selezione multipla).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Annulla</button>
                    <button type="submit" class="btn btn-primary">Aggiungi Utente</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Modifica Utente</h2>
                <button class="modal-close" onclick="closeModal('editModal')">×</button>
            </div>
            <form id="editUserForm">
                <input type="hidden" id="editUserId" name="user_id" />
                <div class="modal-body">
                    <div class="form-group">
                        <label for="editName">Nome Completo *</label>
                        <input type="text" id="editName" name="name" required />
                    </div>
                    <div class="form-group">
                        <label for="editEmail">Email</label>
                        <input type="email" id="editEmail" name="email" required />
                    </div>
                    <div class="form-group">
                        <label for="editHomeCity">Città di residenza (opzionale)</label>
                        <input type="text" id="editHomeCity" name="home_city" placeholder="Es. Milano" />
                        <div class="form-help-text">Usata come default per “Città di partenza” nei piani (puoi sempre sovrascriverla nel piano).</div>
                    </div>
                    <div class="form-group">
                        <label for="editJobTitle">Titolo / Ruolo (opzionale)</label>
                        <input type="text" id="editJobTitle" name="job_title" placeholder="Es. Consulente Senior, Lead Auditor" />
                    </div>
                    <div class="form-group">
                        <label for="editSkillsText">Competenze (opzionale)</label>
                        <textarea id="editSkillsText" name="skills_text" rows="3" placeholder="Es. ISO 9001, audit interni, sanità, formazione..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="editCertificationsText">Titoli / Certificazioni (opzionale)</label>
                        <textarea id="editCertificationsText" name="certifications_text" rows="3" placeholder="Es. Lead Auditor ISO 9001, Laurea..., ecc."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="editPassword">Nuova Password (lascia vuoto per non cambiarla)</label>
                        <input type="password" id="editPassword" name="password" />
                    </div>
                    <div class="form-group">
                        <label for="editRole">Tipo Utente</label>
                        <select id="editRole" name="role" required <?php echo ($currentUserRole === 'manager') ? 'disabled' : ''; ?>>
                            <option value="user">Utente</option>
                            <?php if ($currentUserRole !== 'manager'): ?>
                                <option value="manager">Manager</option>
                                <option value="admin">Admin</option>
                                <option value="super_admin">Super Admin</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editPasswordMaxAgeDays">Durata massima password (giorni)</label>
                        <input type="number" id="editPasswordMaxAgeDays" name="password_max_age_days" min="1" max="3650" value="90" />
                        <div class="form-help-text">Default 90. Se modifichi questo valore, la nuova scadenza viene ricalcolata.</div>
                    </div>
                    <div class="form-group" id="editTenantGroup">
                        <label for="editTenant" id="editTenantLabel">Azienda</label>
                        <div id="editTenantContainer">
                            <!-- Tenant selector will be dynamically generated here -->
                        </div>
                        <div class="form-help-text" id="editTenantHelp"></div>
                    </div>
                    <!-- Tenant Role (Ruolo Aziendale) - shown only when tenant has custom roles -->
                    <div class="form-group" id="editTenantRoleGroup" style="display: none;">
                        <label for="editTenantRole">Ruolo Aziendale</label>
                        <select id="editTenantRole" name="tenant_role_ids[]" multiple>
                            <!-- Options will be dynamically populated -->
                        </select>
                        <div class="form-help-text">Seleziona uno o più ruoli aziendali (Ctrl/Cmd per selezione multipla).</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva Modifiche</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Conferma Eliminazione</h2>
                <button class="modal-close" onclick="closeModal('deleteModal')">×</button>
            </div>
            <div class="modal-body">
                <p>Sei sicuro di voler eliminare questo utente?</p>
                <p class="text-muted text-sm">Questa azione non può essere annullata.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Annulla</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Elimina</button>
            </div>
        </div>
    </div>

    <!-- Toast notification -->
    <div id="toast" class="toast"></div>

    <!-- Hidden CSRF token -->
    <input type="hidden" id="csrfToken" value="<?php echo htmlspecialchars($csrfToken); ?>">

    <script>
        // Build marker to confirm which file version is loaded
        window.CNX_BUILD_ID = <?php echo json_encode($cnxBuildId, JSON_UNESCAPED_UNICODE); ?>;

        // Expose current user role to JS (for super_admin-only actions)
        window.CNX_CURRENT_USER_ROLE = <?php echo json_encode($currentUser['role'] ?? 'user', JSON_UNESCAPED_UNICODE); ?>;
        // Expose current tenant id to JS (for manager-safe user creation)
        window.CNX_CURRENT_TENANT_ID = <?php echo json_encode((int)$currentTenantId, JSON_UNESCAPED_UNICODE); ?>;

        class UserManager {
            constructor() {
                console.log('=== INITIALIZING USER MANAGER ===');
                this.users = [];
                this.currentPage = 1;
                this.itemsPerPage = 10;
                this.searchQuery = '';
                this.deleteUserId = null;
                this.tenantsList = [];
                this.activeCompanyFilterId = this.getActiveCompanyFilterId();
                this.init();
                console.log('UserManager initialized successfully');
            }

            buildTenantSearchInput(formType, placeholder = 'Cerca azienda...') {
                const wrap = document.createElement('div');
                wrap.style.marginBottom = '8px';

                const input = document.createElement('input');
                input.type = 'text';
                input.id = `${formType}TenantSearch`;
                input.placeholder = placeholder;
                input.autocomplete = 'off';
                input.style.width = '100%';
                input.style.padding = '8px 10px';
                input.style.border = '1px solid var(--color-gray-300)';
                input.style.borderRadius = '8px';
                input.style.fontSize = '14px';

                wrap.appendChild(input);
                return { wrap, input };
            }

            getActiveCompanyFilterId() {
                // CompanyFilter helper renders: <select id="company_filter" value="all|{tenantId}">
                const el = document.getElementById('company_filter') || document.getElementById('companyFilter');
                if (!el) return null;
                const raw = (el.value ?? '').toString();
                if (!raw || raw === 'all') return null;
                const v = parseInt(raw, 10);
                return Number.isFinite(v) && v > 0 ? v : null;
            }

            init() {
                console.log('Binding events...');
                this.bindEvents();
                console.log('Loading users...');
                this.loadUsers();
                console.log('Loading tenants...');
                this.loadTenants();
            }

            bindEvents() {
                // Search functionality
                const searchInput = document.getElementById('searchInput');
                if (searchInput) {
                    searchInput.addEventListener('input', (e) => {
                        this.searchQuery = e.target.value;
                        this.currentPage = 1;
                        this.loadUsers();
                    });
                }

                // Add user form
                const addForm = document.getElementById('addUserForm');
                if (addForm) {
                    // Rimuovi la validazione HTML5 per gestirla manualmente
                    addForm.setAttribute('novalidate', 'novalidate');

                    addForm.addEventListener('submit', (e) => {
                        console.log('=== FORM SUBMIT EVENT TRIGGERED ===');
                        e.preventDefault();
                        e.stopPropagation();
                        this.addUser();
                    });

                    console.log('Add user form submit listener registered successfully');
                } else {
                    console.error('ERROR: addUserForm not found in DOM');
                }

                // Edit user form
                const editForm = document.getElementById('editUserForm');
                if (editForm) {
                    // Rimuovi la validazione HTML5 per gestirla manualmente
                    editForm.setAttribute('novalidate', 'novalidate');

                    editForm.addEventListener('submit', (e) => {
                        console.log('=== EDIT FORM SUBMIT EVENT TRIGGERED ===');
                        e.preventDefault();
                        e.stopPropagation();
                        this.updateUser();
                    });

                    console.log('Edit user form submit listener registered successfully');
                } else {
                    console.error('ERROR: editUserForm not found in DOM');
                }

                // Role change listeners for dynamic tenant field
                document.getElementById('addRole').addEventListener('change', (e) => {
                    this.handleRoleChange(e.target.value, 'add');
                });

                document.getElementById('editRole').addEventListener('change', (e) => {
                    this.handleRoleChange(e.target.value, 'edit');
                });

                // Company filter: when changed, reload users and refresh tenant-role options
                const companyFilter = document.getElementById('company_filter') || document.getElementById('companyFilter');
                if (companyFilter) {
                    companyFilter.addEventListener('change', async () => {
                        this.activeCompanyFilterId = this.getActiveCompanyFilterId();
                        await this.loadUsers();
                        // If modals are open, refresh tenant roles context
                        if (document.getElementById('addModal')?.classList.contains('show')) {
                            await this.refreshTenantRoleFromCompanyFilter('add');
                        }
                        if (document.getElementById('editModal')?.classList.contains('show')) {
                            const uid = parseInt(document.getElementById('editUserId')?.value || '0', 10) || 0;
                            if (uid > 0) {
                                await this.refreshTenantRoleFromCompanyFilter('edit', uid);
                            }
                        }
                    });
                }
            }

            handleRoleChange(role, formType) {
                // Manager safety: managers can only create/edit users within their tenant.
                // Allowed target roles for manager are: user, manager (no admin/super_admin).
                if (window.CNX_CURRENT_USER_ROLE === 'manager') {
                    if (role !== 'user' && role !== 'manager') {
                        role = 'user';
                        const roleEl = document.getElementById(`${formType}Role`);
                        if (roleEl) roleEl.value = 'user';
                    }
                }

                const tenantGroup = document.getElementById(`${formType}TenantGroup`);
                const tenantContainer = document.getElementById(`${formType}TenantContainer`);
                const tenantLabel = document.getElementById(`${formType}TenantLabel`);
                const tenantHelp = document.getElementById(`${formType}TenantHelp`);

                // Reset container
                tenantContainer.innerHTML = '';
                tenantHelp.textContent = '';

                switch(role) {
                    case 'super_admin':
                        // Hide tenant field for super admins
                        tenantGroup.classList.add('form-group-hidden');
                        // Tenant Role is assigned per-company via Company Filter (if selected)
                        this.refreshTenantRoleFromCompanyFilter(formType);
                        break;

                    case 'admin':
                        // Show multi-select checkboxes for admins
                        tenantGroup.classList.remove('form-group-hidden');
                        tenantLabel.innerHTML = 'Aziende Assegnate <span id="' + formType + 'SelectedCount" style="color: var(--color-primary); font-weight: normal;"></span>';
                        tenantHelp.textContent = 'Gli admin possono gestire più aziende. Seleziona almeno una azienda.';
                        // Tenant Role is assigned per-company via Company Filter (if selected)
                        this.refreshTenantRoleFromCompanyFilter(formType);

                        // Create wrapper with counter
                        const wrapperDiv = document.createElement('div');
                        wrapperDiv.style.position = 'relative';

                        // Search input for long tenant lists
                        const { wrap: searchWrap, input: searchInput } = this.buildTenantSearchInput(formType, 'Cerca azienda (nome / P.IVA / CF)...');
                        tenantContainer.appendChild(searchWrap);

                        // Create checkbox list
                        const checkboxList = document.createElement('div');
                        checkboxList.className = 'tenant-checkbox-list';
                        checkboxList.id = `${formType}TenantCheckboxList`;

                        // Add selection counter badge
                        const counterBadge = document.createElement('div');
                        counterBadge.className = 'tenant-checkbox-counter';
                        counterBadge.id = `${formType}TenantCounter`;
                        counterBadge.textContent = '0';

                        this.tenantsList.forEach((tenant, index) => {
                            const itemDiv = document.createElement('div');
                            itemDiv.className = 'tenant-checkbox-item';
                            itemDiv.dataset.searchText = `${tenant.denominazione || tenant.name || ''} ${(tenant.partita_iva || '')} ${(tenant.codice_fiscale || '')}`.toLowerCase();

                            const checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.id = `${formType}_tenant_${tenant.id}`;
                            checkbox.name = 'tenant_ids[]';
                            checkbox.value = tenant.id;

                            // Add change listener for visual feedback
                            checkbox.addEventListener('change', (e) => {
                                if (e.target.checked) {
                                    itemDiv.classList.add('checked');
                                } else {
                                    itemDiv.classList.remove('checked');
                                }
                                this.updateTenantSelectionCount(formType);
                            });

                            const label = document.createElement('label');
                            label.htmlFor = `${formType}_tenant_${tenant.id}`;

                            // Create label content with company name and additional info
                            const nameSpan = document.createElement('span');
                            nameSpan.textContent = tenant.denominazione || tenant.name;

                            // Add additional info if available
                            const infoSpan = document.createElement('span');
                            infoSpan.className = 'tenant-info';
                            const infoParts = [];
                            if (tenant.code) infoParts.push(`Codice: ${tenant.code}`);
                            if (tenant.numero_dipendenti) infoParts.push(`${tenant.numero_dipendenti} dipendenti`);
                            if (infoParts.length > 0) {
                                infoSpan.textContent = infoParts.join(' • ');
                            }

                            label.appendChild(nameSpan);
                            if (infoParts.length > 0) {
                                label.appendChild(infoSpan);
                            }

                            // Make entire item clickable
                            itemDiv.addEventListener('click', (e) => {
                                if (e.target !== checkbox) {
                                    checkbox.checked = !checkbox.checked;
                                    checkbox.dispatchEvent(new Event('change'));
                                }
                            });

                            itemDiv.appendChild(checkbox);
                            itemDiv.appendChild(label);
                            checkboxList.appendChild(itemDiv);
                        });

                        wrapperDiv.appendChild(checkboxList);
                        wrapperDiv.appendChild(counterBadge);
                        tenantContainer.appendChild(wrapperDiv);

                        // Filter handler
                        if (searchInput) {
                            searchInput.addEventListener('input', () => {
                                const q = (searchInput.value || '').trim().toLowerCase();
                                const items = checkboxList.querySelectorAll('.tenant-checkbox-item');
                                items.forEach(el => {
                                    const hay = (el.dataset.searchText || '').toLowerCase();
                                    el.style.display = (!q || hay.includes(q)) ? '' : 'none';
                                });
                            });
                        }

                        // Initialize count
                        this.updateTenantSelectionCount(formType);
                        break;

                    case 'manager':
                    case 'user':
                        // Show single select dropdown for managers and users
                        tenantGroup.classList.remove('form-group-hidden');
                        tenantLabel.textContent = 'Azienda';
                        tenantHelp.textContent = role === 'manager' ?
                            'I manager possono gestire una singola azienda' :
                            'Gli utenti appartengono a una singola azienda';

                        // Create single select dropdown
                        const select = document.createElement('select');
                        select.id = `${formType}Tenant`;
                        select.name = 'tenant_id';
                        select.required = true;

                        // Search input (rebuilds options on the fly)
                        const { wrap: selectSearchWrap, input: selectSearchInput } = this.buildTenantSearchInput(formType, 'Cerca azienda (nome / P.IVA / CF)...');
                        tenantContainer.appendChild(selectSearchWrap);

                        // Add empty option
                        const emptyOption = document.createElement('option');
                        emptyOption.value = '';
                        emptyOption.textContent = 'Seleziona un\'azienda';
                        select.appendChild(emptyOption);

                        const renderSelectOptions = (query = '') => {
                            const q = (query || '').trim().toLowerCase();
                            const current = (select.value || '').toString();

                            // Clear options, keep empty option
                            select.innerHTML = '';
                            select.appendChild(emptyOption.cloneNode(true));

                            this.tenantsList.forEach(tenant => {
                                const hay = `${tenant.denominazione || tenant.name || ''} ${(tenant.partita_iva || '')} ${(tenant.codice_fiscale || '')}`.toLowerCase();
                                if (q && !hay.includes(q)) return;
                                const option = document.createElement('option');
                                option.value = tenant.id;
                                option.textContent = tenant.denominazione || tenant.name;
                                select.appendChild(option);
                            });

                            // restore selection if still present
                            if (current) {
                                const exists = [...select.options].some(o => o.value === current);
                                if (exists) select.value = current;
                            }
                        };

                        renderSelectOptions('');

                        if (selectSearchInput) {
                            selectSearchInput.addEventListener('input', () => {
                                renderSelectOptions(selectSearchInput.value || '');
                            });
                        }

                        // Add change listener to load tenant roles when tenant is selected
                        select.addEventListener('change', (e) => {
                            this.loadTenantRoles(e.target.value, formType);
                        });

                        tenantContainer.appendChild(select);

                        // Hide tenant role group initially
                        this.hideTenantRoleGroup(formType);

                        // Manager safety: lock tenant to current tenant (cannot create users in other companies)
                        if (window.CNX_CURRENT_USER_ROLE === 'manager' && window.CNX_CURRENT_TENANT_ID) {
                            select.value = String(window.CNX_CURRENT_TENANT_ID);
                            select.disabled = true;
                            this.loadTenantRoles(window.CNX_CURRENT_TENANT_ID, formType);
                            break;
                        }

                        // If a company is selected in the Company Filter, default the tenant to it
                        // and load roles for that same tenant.
                        const cfTenantId = this.getActiveCompanyFilterId();
                        if (cfTenantId) {
                            select.value = String(cfTenantId);
                            this.loadTenantRoles(cfTenantId, formType);
                        }
                        break;

                    default:
                        // Default to single select
                        tenantGroup.classList.remove('form-group-hidden');
                        tenantLabel.textContent = 'Azienda';

                        const defaultSelect = document.createElement('select');
                        defaultSelect.id = `${formType}Tenant`;
                        defaultSelect.name = 'tenant_id';
                        defaultSelect.required = true;

                        const defaultEmpty = document.createElement('option');
                        defaultEmpty.value = '';
                        defaultEmpty.textContent = 'Seleziona un\'azienda';
                        defaultSelect.appendChild(defaultEmpty);

                        this.tenantsList.forEach(tenant => {
                            const option = document.createElement('option');
                            option.value = tenant.id;
                            option.textContent = tenant.name;
                            defaultSelect.appendChild(option);
                        });

                        tenantContainer.appendChild(defaultSelect);
                        break;
                }
            }

            async refreshTenantRoleFromCompanyFilter(formType, userId = null) {
                const cfTenantId = this.getActiveCompanyFilterId();
                const roleEl = document.getElementById(`${formType}Role`);
                const role = roleEl ? roleEl.value : '';

                // If Company Filter is NOT available/selected (e.g. Manager pages),
                // fall back to the selected tenant for manager/user.
                if (!cfTenantId) {
                    if (role === 'manager' || role === 'user') {
                        const tenantSelect = document.getElementById(`${formType}Tenant`);
                        const selectedTenant = tenantSelect && tenantSelect.value ? parseInt(tenantSelect.value, 10) : null;
                        if (!selectedTenant) {
                            this.hideTenantRoleGroup(formType);
                            return;
                        }

                        await this.loadTenantRoles(selectedTenant, formType);

                        const select = document.getElementById(`${formType}TenantRole`);
                        if (select) {
                            [...select.options].forEach(o => { o.selected = false; });
                        }

                        if (userId) {
                            const current = await this.getUserTenantRole(userId, selectedTenant);
                            const ids = (current && Array.isArray(current.tenant_role_ids)) ? current.tenant_role_ids : [];
                            if (select && ids.length) {
                                const wanted = ids.map(String);
                                [...select.options].forEach(o => { o.selected = wanted.includes(String(o.value)); });
                            }
                        }
                        return;
                    }

                    // For super_admin/admin assignment is per-company via Company Filter only
                    this.hideTenantRoleGroup(formType);
                    return;
                }

                // For manager/user with Company Filter selected: only allow if the selected tenant matches the filter
                if (role === 'manager' || role === 'user') {
                    const tenantSelect = document.getElementById(`${formType}Tenant`);
                    const selectedTenant = tenantSelect && tenantSelect.value ? parseInt(tenantSelect.value, 10) : null;
                    if (selectedTenant && selectedTenant !== cfTenantId) {
                        this.hideTenantRoleGroup(formType);
                        return;
                    }
                }

                await this.loadTenantRoles(cfTenantId, formType);

                const select = document.getElementById(`${formType}TenantRole`);
                if (select) {
                    [...select.options].forEach(o => { o.selected = false; });
                }

                if (userId) {
                    const current = await this.getUserTenantRole(userId, cfTenantId);
                    const ids = (current && Array.isArray(current.tenant_role_ids)) ? current.tenant_role_ids : [];
                    if (select && ids.length) {
                        const wanted = ids.map(String);
                        [...select.options].forEach(o => { o.selected = wanted.includes(String(o.value)); });
                    }
                }
            }

            async getUserTenantRole(userId, tenantId) {
                try {
                    const url = `api/users/tenant_role.php?user_id=${encodeURIComponent(userId)}&tenant_id=${encodeURIComponent(tenantId)}`;
                    const response = await fetch(url, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });
                    const data = await response.json();
                    if (data && data.success) {
                        return data.data || null;
                    }
                    return null;
                } catch (e) {
                    console.error('getUserTenantRole error:', e);
                    return null;
                }
            }

            async setUserTenantRole(userId, tenantId, tenantRoleIdsOrNull) {
                try {
                    const ids = Array.isArray(tenantRoleIdsOrNull)
                        ? tenantRoleIdsOrNull.map(v => parseInt(v, 10)).filter(v => Number.isFinite(v) && v > 0)
                        : [];
                    const response = await fetch('api/users/tenant_role.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify({
                            csrf_token: document.getElementById('csrfToken').value,
                            user_id: userId,
                            tenant_id: tenantId,
                            tenant_role_ids: ids
                        })
                    });
                    const data = await response.json();
                    if (!data || !data.success) {
                        const msg = (data && (data.error || data.message)) ? (data.error || data.message) : 'Errore aggiornamento ruolo aziendale';
                        this.showToast(msg, 'error');
                        return false;
                    }
                    return true;
                } catch (e) {
                    console.error('setUserTenantRole error:', e);
                    this.showToast('Errore di connessione (ruolo aziendale)', 'error');
                    return false;
                }
            }

            async loadUsers() {
                try {
                    // If a company is selected in the Company Filter, scope the list to that tenant.
                    // This enables per-company business role assignment.
                    const params = new URLSearchParams();
                    params.set('page', String(this.currentPage));
                    params.set('search', this.searchQuery);
                    if (this.activeCompanyFilterId) {
                        params.set('scope', 'tenant');
                    }

                    const response = await fetch(`api/users/list.php?${params.toString()}`, {
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    if (data.success) {
                        // Handle the nested data structure from API
                        this.users = data.data?.users || [];
                        this.renderUsers();
                        this.renderPagination(data.data?.total_pages || 1);
                    } else {
                        this.showToast(data.message || 'Errore nel caricamento utenti', 'error');
                    }
                } catch (error) {
                    console.error('Error loading users:', error);
                    this.showToast('Errore di connessione', 'error');
                    // Initialize empty users array to prevent undefined errors
                    this.users = [];
                    this.renderUsers();
                }
            }

            async loadTenants() {
                try {
                    // IMPORTANT:
                    // Do NOT use api/companies/list.php?page=1 here (it is paginated and would truncate the tenant list).
                    // For user assignment we need the full allowed tenant list (RBAC applied server-side).
                    const response = await fetch('api/tenants/list.php', {
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    const tenants = (data && data.success && data.data && Array.isArray(data.data.tenants))
                        ? data.data.tenants
                        : [];

                    this.tenantsList = tenants.map(t => ({
                        id: t.id,
                        // Keep both keys because handleRoleChange uses tenant.name and tenant.denominazione in different places
                        name: t.denominazione || t.name || 'Azienda',
                        denominazione: t.denominazione || t.name || 'Azienda',
                        numero_dipendenti: t.numero_dipendenti || null,
                        settore_merceologico: t.settore_merceologico || null,
                        codice_fiscale: t.codice_fiscale || null,
                        partita_iva: t.partita_iva || null,
                        status: t.status || null
                    }));

                    // Initialize tenant fields based on default role values
                    this.handleRoleChange(document.getElementById('addRole').value, 'add');
                    this.handleRoleChange(document.getElementById('editRole').value, 'edit');

                } catch (error) {
                    console.error('Error loading tenants:', error);
                    this.tenantsList = [];
                }
            }

            renderUsers() {
                const tbody = document.getElementById('usersTableBody');
                const emptyState = document.getElementById('emptyState');

                // Ensure users is always an array
                if (!this.users || this.users.length === 0) {
                    tbody.innerHTML = '';
                    emptyState.style.display = 'block';
                    return;
                }

                emptyState.style.display = 'none';
                tbody.innerHTML = this.users.map(user => {
                    // Handle both old format (first_name, last_name) and new format (name)
                    const userName = user.name || `${user.first_name || ''} ${user.last_name || ''}`.trim() || 'Unknown';
                    const initials = this.getInitialsFromName(userName);
                    const status = user.is_active ? 'active' : 'inactive';

                    // Get tenant role display (Ruolo Aziendale)
                    const tenantRoleDisplay = this.getTenantRoleDisplay(user);

                    const isSuperAdmin = (window.CNX_CURRENT_USER_ROLE === 'super_admin');
                    const isManager = (window.CNX_CURRENT_USER_ROLE === 'manager');
                    const canResendExpiryCode = isSuperAdmin && user.role !== 'super_admin';
                    const resendBtn = canResendExpiryCode ? `
                        <button class="btn-icon" onclick="userManager.sendOneTimePasswordReset(${user.id})" title="Invia password one-time + link reset">
                            🔑
                        </button>
                    ` : '';

                    const docsBtn = isSuperAdmin ? `
                        <button class="btn-icon" onclick="userManager.sendUserDocs(${user.id})" title="Invia PDF onboarding e documenti (docs/*.pdf)">
                            📄
                        </button>
                    ` : '';

                    // Managers cannot manage admin/super_admin users
                    const managerBlockedTarget = isManager && (user.role === 'admin' || user.role === 'super_admin');
                    const editBtn = managerBlockedTarget ? '' : `
                        <button class="btn-icon edit" onclick="userManager.openEditModal(${user.id})" title="Modifica">
                            ✏️
                        </button>
                    `;
                    const toggleBtn = managerBlockedTarget ? '' : `
                        <button class="btn-icon toggle" onclick="userManager.toggleStatus(${user.id})" title="Cambia stato">
                            ${status === 'active' ? '⏸️' : '▶️'}
                        </button>
                    `;
                    const deleteBtn = managerBlockedTarget ? '' : `
                        <button class="btn-icon delete" onclick="userManager.openDeleteModal(${user.id})" title="Elimina">
                            🗑️
                        </button>
                    `;

                    return `
                    <tr>
                        <td>
                            <div class="user-info-cell">
                                <div class="user-avatar-table">${initials}</div>
                                <div class="user-details-table">
                                    <div class="user-name-table">${userName}</div>
                                </div>
                            </div>
                        </td>
                        <td>${user.email}</td>
                        <td>
                            <span class="role-badge ${user.role.replace('_', '-')}">${this.getRoleLabel(user.role)}</span>
                        </td>
                        <td>${tenantRoleDisplay}</td>
                        <td>${this.getTenantDisplay(user)}</td>
                        <td>
                            <span class="status-badge ${status}">
                                <span class="status-indicator"></span>
                                ${status === 'active' ? 'Attivo' : 'Inattivo'}
                            </span>
                        </td>
                        <td>${user.created_at ? this.formatDate(user.created_at) : '-'}</td>
                        <td>
                            <div class="action-buttons">
                                ${editBtn}
                                ${toggleBtn}
                                ${resendBtn}
                                ${docsBtn}
                                ${deleteBtn}
                            </div>
                        </td>
                    </tr>
                `}).join('');
            }

            async sendUserDocs(userId) {
                if (!confirm('Inviare i PDF (onboarding + docs/*.pdf) via email a questo utente?')) {
                    return;
                }

                try {
                    const response = await fetch('api/users/send_docs.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify({
                            csrf_token: document.getElementById('csrfToken').value,
                            user_id: userId
                        })
                    });

                    const data = await response.json();
                    if (data && data.success) {
                        const n = data.data?.docs_count ?? 0;
                        const to = data.data?.to ? String(data.data.to) : '';
                        this.showToast(`Email inviata${to ? ` a ${to}` : ''} (${n} allegati)`, 'success');
                    } else {
                        this.showToast(data?.error || data?.message || 'Errore invio PDF', 'error');
                    }
                } catch (e) {
                    console.error('sendUserDocs error:', e);
                    this.showToast('Errore di connessione (invio PDF)', 'error');
                }
            }

            async sendOneTimePasswordReset(userId) {
                if (!confirm('Inviare una password one-time e un link per reimpostare la password a questo utente?')) {
                    return;
                }

                try {
                    const response = await fetch('api/users/send_one_time_password_reset.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        },
                        body: JSON.stringify({
                            csrf_token: document.getElementById('csrfToken').value,
                            user_id: userId
                        })
                    });

                    const data = await response.json();
                    if (data.success) {
                        this.showToast(data.message || 'Password one-time inviata', 'success');
                    } else {
                        this.showToast(data.error || data.message || 'Errore invio password one-time', 'error');
                    }
                } catch (e) {
                    console.error('Send one-time password reset error:', e);
                    this.showToast('Errore di connessione', 'error');
                }
            }

            renderPagination(totalPages) {
                const pagination = document.getElementById('pagination');

                if (totalPages <= 1) {
                    pagination.innerHTML = '';
                    return;
                }

                let html = '';

                // Previous button
                html += `<button onclick="userManager.goToPage(${this.currentPage - 1})" ${this.currentPage === 1 ? 'disabled' : ''}>←</button>`;

                // Page numbers
                for (let i = 1; i <= totalPages; i++) {
                    if (i === 1 || i === totalPages || (i >= this.currentPage - 2 && i <= this.currentPage + 2)) {
                        html += `<button onclick="userManager.goToPage(${i})" class="${i === this.currentPage ? 'active' : ''}">${i}</button>`;
                    } else if (i === this.currentPage - 3 || i === this.currentPage + 3) {
                        html += `<span>...</span>`;
                    }
                }

                // Next button
                html += `<button onclick="userManager.goToPage(${this.currentPage + 1})" ${this.currentPage === totalPages ? 'disabled' : ''}>→</button>`;

                pagination.innerHTML = html;
            }

            goToPage(page) {
                this.currentPage = page;
                this.loadUsers();
            }

            async addUser() {
                console.log('=== ADD USER FUNCTION CALLED ===');
                const form = document.getElementById('addUserForm');
                const role = document.getElementById('addRole').value;
                console.log('Form element:', form);
                console.log('Selected role:', role);

                // Validazione manuale dei campi required
                const name = form.name.value.trim();
                const email = form.email.value.trim();

                if (!name || name.length < 2) {
                    this.showToast('Inserisci il nome completo (almeno 2 caratteri)', 'error');
                    document.getElementById('addName').focus();
                    return;
                }

                if (!email) {
                    this.showToast('Inserisci l\'email', 'error');
                    document.getElementById('addEmail').focus();
                    return;
                }

                // Validazione formato email
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    this.showToast('Inserisci un\'email valida', 'error');
                    document.getElementById('addEmail').focus();
                    return;
                }

                // Optional: validate "Città di residenza" (must exist to allow travel optimization)
                let homeCity = '';
                try { homeCity = (document.getElementById('addHomeCity')?.value || '').trim(); } catch (e) {}
                if (homeCity) {
                    const res = await this.validateItalianMunicipality(homeCity);
                    if (res && res.ok && !res.skipped) {
                        // Normalize input to canonical municipality name
                        homeCity = String(res?.municipality?.name || this.normalizeCityInput(homeCity) || homeCity).trim();
                        const el = document.getElementById('addHomeCity');
                        if (el) el.value = homeCity;
                    } else if (res && res.ok && res.skipped) {
                        // Validation unavailable: do not block
                    } else {
                        const sugg = (res?.suggestions || []).slice(0, 5).map(s => `${s.name} (${s.province_code})`).join(', ');
                        this.showToast(`Città di residenza non riconosciuta: "${homeCity}". ${sugg ? 'Suggerimenti: ' + sugg : ''}`, 'error');
                        document.getElementById('addHomeCity')?.focus();
                        return;
                    }
                }

                const formData = new FormData();

                // Add basic fields
                formData.append('name', name);
                formData.append('email', email);
                if (homeCity) {
                    formData.append('home_city', homeCity);
                }
                // Optional professional profile fields (best-effort; stored only if DB columns exist)
                const jobTitle = (document.getElementById('addJobTitle')?.value || '').trim();
                const skillsText = (document.getElementById('addSkillsText')?.value || '').trim();
                const certText = (document.getElementById('addCertificationsText')?.value || '').trim();
                if (jobTitle) formData.append('job_title', jobTitle);
                if (skillsText) formData.append('skills_text', skillsText);
                if (certText) formData.append('certifications_text', certText);
                // Password non più necessaria - verrà inviata email all'utente
                formData.append('role', role);
                const addMaxAge = document.getElementById('addPasswordMaxAgeDays');
                if (addMaxAge && addMaxAge.value) {
                    formData.append('password_max_age_days', addMaxAge.value);
                }
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                // Handle tenant assignment based on role
                if (role === 'admin') {
                    // Get all checked tenants for admin role
                    const checkedTenants = document.querySelectorAll('#addTenantContainer input[type="checkbox"]:checked');
                    if (checkedTenants.length === 0) {
                        this.showToast('Seleziona almeno un\'azienda per l\'admin', 'error');
                        return;
                    }
                    checkedTenants.forEach(checkbox => {
                        formData.append('tenant_ids[]', checkbox.value);
                    });
                } else if (role !== 'super_admin') {
                    // For manager and user roles, get single tenant
                    const tenantSelect = document.getElementById('addTenant');
                    if (tenantSelect && tenantSelect.value) {
                        formData.append('tenant_id', tenantSelect.value);

                        // Add tenant_role_ids[] if selected (for manager/user roles only)
                        const tenantRoleSelect = document.getElementById('addTenantRole');
                        if (tenantRoleSelect) {
                            const selected = [...tenantRoleSelect.selectedOptions]
                                .map(o => parseInt(o.value, 10))
                                .filter(v => Number.isFinite(v) && v > 0);
                            selected.forEach(v => formData.append('tenant_role_ids[]', String(v)));
                        }
                    } else if (role === 'manager' || role === 'user') {
                        this.showToast('Seleziona un\'azienda', 'error');
                        return;
                    }
                }

                let response;
                try {
                    console.log('Sending user creation request...');
                    response = await fetch('api/users/create_simple.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    });

                    console.log('Response status:', response.status, response.statusText);

                    // Check if response is OK before parsing
                    if (!response.ok) {
                        const errorText = await response.text();
                        console.error('Server error response:', errorText);
                        throw new Error(`HTTP ${response.status}: ${response.statusText}. Response: ${errorText.substring(0, 200)}`);
                    }

                    // Try to parse JSON with detailed error handling
                    let data;
                    try {
                        const responseText = await response.text();
                        console.log('Raw response:', responseText.substring(0, 500));
                        data = JSON.parse(responseText);
                    } catch (jsonError) {
                        console.error('JSON parsing error:', jsonError.message);
                        console.error('JSON error stack:', jsonError.stack);
                        throw new Error(`Invalid JSON response: ${jsonError.message}`);
                    }

                    // Debug: log della risposta
                    console.log('Create user response:', data);

                    if (data.success) {
                        let message = 'Utente creato con successo';
                        if (data.data && data.data.email_sent) {
                            message += '. Email di benvenuto inviata.';
                        } else if (data.warning) {
                            message += '. ATTENZIONE: ' + data.warning;
                            if (data.reset_link) {
                                console.log('Link manuale per impostare password:', data.reset_link);
                                // Mostra il link in caso di errore email
                                setTimeout(() => {
                                    if (confirm('Email non inviata. Vuoi copiare il link per impostare la password?')) {
                                        navigator.clipboard.writeText(data.reset_link);
                                        this.showToast('Link copiato negli appunti', 'info');
                                    }
                                }, 1000);
                            }
                        }
                        this.showToast(message, 'success');
                        // Per-azienda: assegna Ruolo Aziendale per l'azienda selezionata nel Company Filter
                        try {
                            const newUserId = parseInt((data.data && (data.data.id || data.data.user_id || data.data.userId)) || '0', 10) || 0;
                            const cfTenantId = this.getActiveCompanyFilterId();
                            const tenantRoleSelect = document.getElementById('addTenantRole');
                            const selectedTenantRoleId = tenantRoleSelect ? (tenantRoleSelect.value || '') : '';

                            // Determine assignment tenant:
                            // - super_admin/admin: per-company via Company Filter
                            // - manager/user: via selected tenant (Company Filter may not exist)
                            let targetTenantId = cfTenantId;
                            if (!targetTenantId && (role === 'manager' || role === 'user')) {
                                const tenantSelect = document.getElementById('addTenant');
                                const selectedTenant = tenantSelect && tenantSelect.value ? parseInt(tenantSelect.value, 10) : null;
                                targetTenantId = selectedTenant || null;
                            }

                            // For manager/user, if Company Filter is selected, require selected tenant matches it
                            if ((role === 'manager' || role === 'user') && cfTenantId) {
                                const tenantSelect = document.getElementById('addTenant');
                                const selectedTenant = tenantSelect && tenantSelect.value ? parseInt(tenantSelect.value, 10) : null;
                                if (!selectedTenant || selectedTenant !== cfTenantId) {
                                    targetTenantId = null;
                                }
                            }

                            if (newUserId > 0 && targetTenantId) {
                                await this.setUserTenantRole(
                                    newUserId,
                                    targetTenantId,
                                    selectedTenantRoleId === '' ? null : parseInt(selectedTenantRoleId, 10)
                                );
                            }
                        } catch (e) {
                            console.warn('Tenant role assignment post-create failed (non-blocking):', e);
                        }

                        closeModal('addModal');
                        form.reset();
                        this.loadUsers();
                    } else {
                        const errorMsg = data.message || data.error || 'Errore nella creazione utente';
                        console.error('API returned error:', errorMsg, data);
                        this.showToast(errorMsg, 'error');
                    }
                } catch (error) {
                    // Enhanced error logging with full details
                    console.error('=== ERROR ADDING USER ===');
                    console.error('Error type:', error.constructor.name);
                    console.error('Error message:', error.message);
                    console.error('Error stack:', error.stack);
                    if (response) {
                        console.error('Response status:', response.status);
                        console.error('Response headers:', [...response.headers.entries()]);
                    }
                    console.error('Full error object:', error);
                    console.error('========================');

                    // User-friendly error message
                    let userMessage = 'Errore di connessione';
                    if (error.message.includes('HTTP')) {
                        userMessage = 'Errore del server: ' + error.message;
                    } else if (error.message.includes('JSON')) {
                        userMessage = 'Risposta non valida dal server';
                    }
                    this.showToast(userMessage, 'error');
                }
            }

            async openEditModal(userId) {
                const user = this.users.find(u => u.id === userId);
                if (!user) return;

                // Managers cannot edit admin/super_admin users (prevents forbidden API calls)
                if (window.CNX_CURRENT_USER_ROLE === 'manager' && (user.role === 'admin' || user.role === 'super_admin')) {
                    this.showToast('Permessi insufficienti per modificare questo utente', 'error');
                    return;
                }

                // Handle both formats: single 'name' field or 'first_name'/'last_name'
                const userName = user.name || `${user.first_name || ''} ${user.last_name || ''}`.trim() || '';

                document.getElementById('editUserId').value = user.id;
                document.getElementById('editName').value = userName;
                document.getElementById('editEmail').value = user.email;
                try {
                    const hc = (user && typeof user === 'object' && user.home_city) ? String(user.home_city) : '';
                    const el = document.getElementById('editHomeCity');
                    if (el) el.value = hc;
                } catch (e) {}
                try {
                    const jt = (user && typeof user === 'object' && user.job_title) ? String(user.job_title) : '';
                    const st = (user && typeof user === 'object' && user.skills_text) ? String(user.skills_text) : '';
                    const ct = (user && typeof user === 'object' && user.certifications_text) ? String(user.certifications_text) : '';
                    const el1 = document.getElementById('editJobTitle');
                    const el2 = document.getElementById('editSkillsText');
                    const el3 = document.getElementById('editCertificationsText');
                    if (el1) el1.value = jt;
                    if (el2) el2.value = st;
                    if (el3) el3.value = ct;
                } catch (e) {}
                document.getElementById('editRole').value = user.role;
                document.getElementById('editPassword').value = '';
                const editMaxAgeEl = document.getElementById('editPasswordMaxAgeDays');
                if (editMaxAgeEl) {
                    editMaxAgeEl.value = (user.password_max_age_days || 90);
                }

                // First set the role, which will update the tenant field
                this.handleRoleChange(user.role, 'edit');

                // Then set the tenant value(s) after a short delay to ensure DOM is updated
                setTimeout(async () => {
                    if (user.role === 'admin') {
                        // For admins, we need to fetch their assigned companies
                        // Only admin/super_admin can call this endpoint (managers are blocked above)
                        this.loadUserCompanies(userId, 'edit');
                    } else if (user.role !== 'super_admin') {
                        // For other roles, set single tenant
                        const tenantSelect = document.getElementById('editTenant');
                        if (tenantSelect) {
                            tenantSelect.value = user.tenant_id || '';

                            // Load tenant roles for the selected tenant
                            if (user.tenant_id) {
                                await this.loadTenantRoles(user.tenant_id, 'edit');

                                // Multi-role selection is loaded via api/users/tenant_role.php (refreshTenantRoleFromCompanyFilter)
                            }
                        }
                    }

                    // Apply tenant-role context based on Company Filter (per-azienda)
                    await this.refreshTenantRoleFromCompanyFilter('edit', userId);
                }, 100);

                openModal('editModal');
            }

            async loadUserCompanies(userId, formType) {
                try {
                    const response = await fetch(`api/users/get-companies.php?user_id=${userId}`, {
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    if (data.success && data.companies) {
                        // Check the corresponding checkboxes and update visual state
                        data.companies.forEach(companyId => {
                            const checkbox = document.getElementById(`${formType}_tenant_${companyId}`);
                            if (checkbox) {
                                checkbox.checked = true;
                                // Update visual state of the item
                                const item = checkbox.closest('.tenant-checkbox-item');
                                if (item) {
                                    item.classList.add('checked');
                                }
                            }
                        });
                        // Update the selection count
                        this.updateTenantSelectionCount(formType);
                    }
                } catch (error) {
                    console.error('Error loading user companies:', error);
                }
            }

            updateTenantSelectionCount(formType) {
                const checkboxes = document.querySelectorAll(`#${formType}TenantContainer input[type="checkbox"]:checked`);
                const count = checkboxes.length;

                // Update counter badge
                const counterBadge = document.getElementById(`${formType}TenantCounter`);
                if (counterBadge) {
                    counterBadge.textContent = count.toString();
                    const list = document.getElementById(`${formType}TenantCheckboxList`);
                    if (list) {
                        if (count > 0) {
                            list.classList.add('has-selection');
                        } else {
                            list.classList.remove('has-selection');
                        }
                    }
                }

                // Update label counter
                const labelCounter = document.getElementById(`${formType}SelectedCount`);
                if (labelCounter) {
                    if (count > 0) {
                        labelCounter.textContent = `(${count} selezionate)`;
                    } else {
                        labelCounter.textContent = '';
                    }
                }
            }

            async updateUser() {
                const form = document.getElementById('editUserForm');
                const role = document.getElementById('editRole').value;

                // Managers: allow ONLY Ruolo Aziendale assignment (per-tenant) via api/users/tenant_role.php
                // update_v2.php requires admin, so managers must not call it.
                if (window.CNX_CURRENT_USER_ROLE === 'manager') {
                    const uid = parseInt(form.user_id.value, 10) || 0;
                    const tenantSelect = document.getElementById('editTenant');
                    const targetTenantId = tenantSelect && tenantSelect.value ? parseInt(tenantSelect.value, 10) : 0;
                    const tenantRoleSelect = document.getElementById('editTenantRole');
                    const selectedTenantRoleIds = tenantRoleSelect
                        ? [...tenantRoleSelect.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0)
                        : [];

                    if (!uid || uid <= 0 || !targetTenantId || targetTenantId <= 0) {
                        this.showToast('Seleziona un utente e una azienda valida', 'error');
                        return;
                    }

                    const ok = await this.setUserTenantRole(
                        uid,
                        targetTenantId,
                        selectedTenantRoleIds
                    );

                    if (ok) {
                        // Refresh row immediately (best effort)
                        try {
                            const current = await this.getUserTenantRole(uid, targetTenantId);
                            const idx = this.users.findIndex(u => String(u.id) === String(uid));
                            if (idx >= 0 && current) {
                                // Keep backward-compatible single fields for table rendering
                                const ids = Array.isArray(current.tenant_role_ids) ? current.tenant_role_ids : [];
                                this.users[idx].tenant_role_id = ids.length ? ids[0] : (current.tenant_role_id ?? null);
                                this.users[idx].tenant_role_name = current.tenant_role?.name ?? (current.tenant_roles?.[0]?.name ?? null);
                                this.users[idx].tenant_role_color = current.tenant_role?.color ?? (current.tenant_roles?.[0]?.color ?? null);
                            }
                        } catch (e) {
                            // non-blocking
                        }

                        this.showToast('Ruolo aziendale aggiornato con successo', 'success');
                        closeModal('editModal');
                        this.renderUsers();
                    }

                    return;
                }

                // Validazione manuale dei campi required
                const name = form.name.value.trim();

                if (!name || name.length < 2) {
                    this.showToast('Inserisci il nome completo (almeno 2 caratteri)', 'error');
                    document.getElementById('editName').focus();
                    return;
                }

                const formData = new FormData();

                // Add basic fields
                formData.append('user_id', form.user_id.value);
                formData.append('name', name);
                formData.append('email', form.email.value);
                // Optional: user default city (used as Planning default home city). Empty => clear.
                let homeCity = '';
                try { homeCity = (document.getElementById('editHomeCity')?.value || '').trim(); } catch (e) {}
                if (homeCity) {
                    const res = await this.validateItalianMunicipality(homeCity);
                    if (res && res.ok && !res.skipped) {
                        homeCity = String(res?.municipality?.name || this.normalizeCityInput(homeCity) || homeCity).trim();
                        const el = document.getElementById('editHomeCity');
                        if (el) el.value = homeCity;
                    } else if (res && res.ok && res.skipped) {
                        // Validation unavailable: do not block
                    } else {
                        const sugg = (res?.suggestions || []).slice(0, 5).map(s => `${s.name} (${s.province_code})`).join(', ');
                        this.showToast(`Città di residenza non riconosciuta: "${homeCity}". ${sugg ? 'Suggerimenti: ' + sugg : ''}`, 'error');
                        document.getElementById('editHomeCity')?.focus();
                        return;
                    }
                }
                formData.append('home_city', homeCity);
                // Optional professional profile fields (empty string clears when supported)
                try {
                    formData.append('job_title', (document.getElementById('editJobTitle')?.value || '').trim());
                    formData.append('skills_text', (document.getElementById('editSkillsText')?.value || '').trim());
                    formData.append('certifications_text', (document.getElementById('editCertificationsText')?.value || '').trim());
                } catch (e) {}
                if (form.password.value) {
                    formData.append('password', form.password.value);
                }
                formData.append('role', role);
                const editMaxAge = document.getElementById('editPasswordMaxAgeDays');
                if (editMaxAge && editMaxAge.value) {
                    formData.append('password_max_age_days', editMaxAge.value);
                }
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                // Handle tenant assignment based on role
                if (role === 'admin') {
                    // Get all checked tenants for admin role
                    const checkedTenants = document.querySelectorAll('#editTenantContainer input[type="checkbox"]:checked');
                    if (checkedTenants.length === 0) {
                        this.showToast('Seleziona almeno un\'azienda per l\'admin', 'error');
                        return;
                    }
                    checkedTenants.forEach(checkbox => {
                        formData.append('tenant_ids[]', checkbox.value);
                    });
                } else if (role !== 'super_admin') {
                    // For manager and user roles, get single tenant
                    const tenantSelect = document.getElementById('editTenant');
                    if (tenantSelect && tenantSelect.value) {
                        formData.append('tenant_id', tenantSelect.value);

                        // Add tenant_role_ids[] if selected (for manager/user roles only)
                        const tenantRoleSelect = document.getElementById('editTenantRole');
                        if (tenantRoleSelect) {
                            const selected = [...tenantRoleSelect.selectedOptions]
                                .map(o => parseInt(o.value, 10))
                                .filter(v => Number.isFinite(v) && v > 0);
                            selected.forEach(v => formData.append('tenant_role_ids[]', String(v)));
                        }
                    } else if (role === 'manager' || role === 'user') {
                        this.showToast('Seleziona un\'azienda', 'error');
                        return;
                    }
                }

                let response;
                try {
                    console.log('Sending user update request...');
                    response = await fetch('api/users/update_v2.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: formData
                    });

                    console.log('Response status:', response.status, response.statusText);

                    // Check if response is OK before parsing
                    if (!response.ok) {
                        const errorText = await response.text();
                        console.error('Server error response:', errorText);
                        throw new Error(`HTTP ${response.status}: ${response.statusText}. Response: ${errorText.substring(0, 200)}`);
                    }

                    // Try to parse JSON with detailed error handling
                    let data;
                    try {
                        const responseText = await response.text();
                        console.log('Raw response:', responseText.substring(0, 500));
                        data = JSON.parse(responseText);
                    } catch (jsonError) {
                        console.error('JSON parsing error:', jsonError.message);
                        console.error('JSON error stack:', jsonError.stack);
                        throw new Error(`Invalid JSON response: ${jsonError.message}`);
                    }

                    if (data.success) {
                        this.showToast('Utente aggiornato con successo', 'success');
                        closeModal('editModal');
                        // Per-azienda: assegna Ruolo Aziendale per l'azienda selezionata nel Company Filter
                        try {
                            const uid = parseInt(form.user_id.value, 10) || 0;
                            const cfTenantId = this.getActiveCompanyFilterId();
                            const tenantRoleSelect = document.getElementById('editTenantRole');
                            const selectedTenantRoleIds = tenantRoleSelect
                                ? [...tenantRoleSelect.selectedOptions].map(o => parseInt(o.value, 10)).filter(v => Number.isFinite(v) && v > 0)
                                : [];

                            // Determine assignment tenant:
                            // - super_admin/admin: per-company via Company Filter
                            // - manager/user: via selected tenant (Company Filter may not exist)
                            let targetTenantId = cfTenantId;
                            if (!targetTenantId && (role === 'manager' || role === 'user')) {
                                const tenantSelect = document.getElementById('editTenant');
                                const selectedTenant = tenantSelect && tenantSelect.value ? parseInt(tenantSelect.value, 10) : null;
                                targetTenantId = selectedTenant || null;
                            }

                            // For manager/user, if Company Filter is selected, require selected tenant matches it
                            if ((role === 'manager' || role === 'user') && cfTenantId) {
                                const tenantSelect = document.getElementById('editTenant');
                                const selectedTenant = tenantSelect && tenantSelect.value ? parseInt(tenantSelect.value, 10) : null;
                                if (!selectedTenant || selectedTenant !== cfTenantId) {
                                    targetTenantId = null;
                                }
                            }

                            if (uid > 0 && targetTenantId) {
                                await this.setUserTenantRole(
                                    uid,
                                    targetTenantId,
                                    selectedTenantRoleIds
                                );
                            }
                        } catch (e) {
                            console.warn('Tenant role assignment post-update failed (non-blocking):', e);
                        }

                        this.loadUsers();
                    } else {
                        const errorMsg = data.message || data.error || 'Errore nell\'aggiornamento utente';
                        console.error('API returned error:', errorMsg, data);
                        this.showToast(errorMsg, 'error');
                    }
                } catch (error) {
                    // Enhanced error logging with full details
                    console.error('=== ERROR UPDATING USER ===');
                    console.error('Error type:', error.constructor.name);
                    console.error('Error message:', error.message);
                    console.error('Error stack:', error.stack);
                    if (response) {
                        console.error('Response status:', response.status);
                        console.error('Response headers:', [...response.headers.entries()]);
                    }
                    console.error('Full error object:', error);
                    console.error('===========================');

                    // User-friendly error message
                    let userMessage = 'Errore di connessione';
                    if (error.message.includes('HTTP')) {
                        userMessage = 'Errore del server: ' + error.message;
                    } else if (error.message.includes('JSON')) {
                        userMessage = 'Risposta non valida dal server';
                    }
                    this.showToast(userMessage, 'error');
                }
            }

            openDeleteModal(userId) {
                this.deleteUserId = userId;
                openModal('deleteModal');
            }

            async confirmDelete() {
                if (!this.deleteUserId) return;

                const formData = new FormData();
                formData.append('user_id', this.deleteUserId);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                let response;
                try {
                    console.log('Sending user delete request...');
                    response = await fetch('api/users/delete.php', {
                        method: 'POST',
                        body: formData
                    });

                    console.log('Response status:', response.status, response.statusText);

                    // Check if response is OK before parsing
                    if (!response.ok) {
                        const errorText = await response.text();
                        console.error('Server error response:', errorText);
                        throw new Error(`HTTP ${response.status}: ${response.statusText}. Response: ${errorText.substring(0, 200)}`);
                    }

                    // Try to parse JSON with detailed error handling
                    let data;
                    try {
                        const responseText = await response.text();
                        console.log('Raw response:', responseText.substring(0, 500));
                        data = JSON.parse(responseText);
                    } catch (jsonError) {
                        console.error('JSON parsing error:', jsonError.message);
                        console.error('JSON error stack:', jsonError.stack);
                        throw new Error(`Invalid JSON response: ${jsonError.message}`);
                    }

                    if (data.success) {
                        this.showToast('Utente eliminato con successo', 'success');
                        closeModal('deleteModal');
                        this.loadUsers();
                    } else {
                        const errorMsg = data.message || data.error || 'Errore nell\'eliminazione utente';
                        console.error('API returned error:', errorMsg, data);
                        this.showToast(errorMsg, 'error');
                    }
                } catch (error) {
                    // Enhanced error logging with full details
                    console.error('=== ERROR DELETING USER ===');
                    console.error('Error type:', error.constructor.name);
                    console.error('Error message:', error.message);
                    console.error('Error stack:', error.stack);
                    if (response) {
                        console.error('Response status:', response.status);
                        console.error('Response headers:', [...response.headers.entries()]);
                    }
                    console.error('Full error object:', error);
                    console.error('===========================');

                    // User-friendly error message
                    let userMessage = 'Errore di connessione';
                    if (error.message.includes('HTTP')) {
                        userMessage = 'Errore del server: ' + error.message;
                    } else if (error.message.includes('JSON')) {
                        userMessage = 'Risposta non valida dal server';
                    }
                    this.showToast(userMessage, 'error');
                }

                this.deleteUserId = null;
            }

            async toggleStatus(userId) {
                const formData = new FormData();
                formData.append('user_id', userId);
                formData.append('csrf_token', document.getElementById('csrfToken').value);

                let response;
                try {
                    console.log('Sending toggle status request...');
                    response = await fetch('api/users/toggle-status.php', {
                        method: 'POST',
                        body: formData
                    });

                    console.log('Response status:', response.status, response.statusText);

                    // Check if response is OK before parsing
                    if (!response.ok) {
                        const errorText = await response.text();
                        console.error('Server error response:', errorText);
                        throw new Error(`HTTP ${response.status}: ${response.statusText}. Response: ${errorText.substring(0, 200)}`);
                    }

                    // Try to parse JSON with detailed error handling
                    let data;
                    try {
                        const responseText = await response.text();
                        console.log('Raw response:', responseText.substring(0, 500));
                        data = JSON.parse(responseText);
                    } catch (jsonError) {
                        console.error('JSON parsing error:', jsonError.message);
                        console.error('JSON error stack:', jsonError.stack);
                        throw new Error(`Invalid JSON response: ${jsonError.message}`);
                    }

                    if (data.success) {
                        this.showToast('Stato utente aggiornato', 'success');
                        this.loadUsers();
                    } else {
                        const errorMsg = data.message || data.error || 'Errore nell\'aggiornamento stato';
                        console.error('API returned error:', errorMsg, data);
                        this.showToast(errorMsg, 'error');
                    }
                } catch (error) {
                    // Enhanced error logging with full details
                    console.error('=== ERROR TOGGLING STATUS ===');
                    console.error('Error type:', error.constructor.name);
                    console.error('Error message:', error.message);
                    console.error('Error stack:', error.stack);
                    if (response) {
                        console.error('Response status:', response.status);
                        console.error('Response headers:', [...response.headers.entries()]);
                    }
                    console.error('Full error object:', error);
                    console.error('=============================');

                    // User-friendly error message
                    let userMessage = 'Errore di connessione';
                    if (error.message.includes('HTTP')) {
                        userMessage = 'Errore del server: ' + error.message;
                    } else if (error.message.includes('JSON')) {
                        userMessage = 'Risposta non valida dal server';
                    }
                    this.showToast(userMessage, 'error');
                }
            }

            getInitials(firstName, lastName) {
                return (firstName[0] + lastName[0]).toUpperCase();
            }

            getInitialsFromName(name) {
                const parts = name.split(' ');
                if (parts.length >= 2) {
                    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
                } else if (parts.length === 1 && parts[0].length >= 2) {
                    return parts[0].substring(0, 2).toUpperCase();
                }
                return 'U';
            }

            getRoleLabel(role) {
                const labels = {
                    'super_admin': 'Super Admin',
                    'admin': 'Admin',
                    'tenant_admin': 'Admin',
                    'manager': 'Manager',
                    'user': 'Utente',
                    'guest': 'Ospite'
                };
                return labels[role] || role;
            }

            getTenantDisplay(user) {
                if (user.role === 'super_admin') {
                    return '<em style="color: var(--color-gray-500)">Accesso globale</em>';
                } else if (user.role === 'admin' && user.company_names) {
                    // Display multiple companies for admin
                    const companies = user.company_names.split(',');
                    if (companies.length > 2) {
                        return `${companies[0]} +${companies.length - 1} altri`;
                    }
                    return companies.join(', ');
                }
                return user.tenant_name || '-';
            }

            // Get tenant role (Ruolo Aziendale) display
            getTenantRoleDisplay(user) {
                // Super admin and admin don't have tenant roles
                if (user.role === 'super_admin' || user.role === 'admin') {
                    return '<span class="tenant-role-badge no-role">-</span>';
                }

                // Support both shapes:
                // - flat fields: tenant_role_name / tenant_role_color (legacy)
                // - object: tenant_role { name, color } (API default)
                const roleName = user.tenant_role_name || user.tenant_role?.name || '';
                if (roleName) {
                    // Use consistent styling (same palette as role-badge.user) to avoid a “rainbow” UI.
                    return `<span class="tenant-role-badge">${roleName}</span>`;
                }

                return '<span class="tenant-role-badge no-role">Nessuno</span>';
            }

            // Calculate contrasting text color (black or white) for given background
            getContrastingColor(hexColor) {
                // Remove # if present
                const hex = hexColor.replace('#', '');

                // Parse RGB values
                const r = parseInt(hex.substr(0, 2), 16);
                const g = parseInt(hex.substr(2, 2), 16);
                const b = parseInt(hex.substr(4, 2), 16);

                // Calculate luminance
                const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

                return luminance > 0.5 ? '#000000' : '#ffffff';
            }

            // Load tenant roles for a specific tenant
            async loadTenantRoles(tenantId, formType = 'add') {
                if (!tenantId) {
                    this.hideTenantRoleGroup(formType);
                    return;
                }

                try {
                    const response = await fetch(`api/tenant-roles/list.php?tenant_id=${tenantId}`, {
                        credentials: 'same-origin',
                        headers: {
                            'X-CSRF-Token': document.getElementById('csrfToken').value
                        }
                    });

                    const data = await response.json();

                    if (data.success && data.data && data.data.tenant_has_custom_roles && data.data.roles.length > 0) {
                        // Show dropdown only if current user is allowed to assign for this tenant
                        if (data.data.can_assign_custom_roles) {
                            this.showTenantRoleGroup(formType, data.data.roles);
                        } else {
                            this.hideTenantRoleGroup(formType);
                        }
                    } else {
                        // No custom roles for this tenant
                        this.hideTenantRoleGroup(formType);
                    }
                } catch (error) {
                    console.error('Error loading tenant roles:', error);
                    this.hideTenantRoleGroup(formType);
                }
            }

            // Show the tenant role dropdown and populate with options
            showTenantRoleGroup(formType, roles) {
                const group = document.getElementById(`${formType}TenantRoleGroup`);
                const select = document.getElementById(`${formType}TenantRole`);

                if (!group || !select) return;

                // Populate options
                let optionsHtml = '<option value="">-- Nessun ruolo aziendale --</option>';
                roles.forEach(role => {
                    const colorStyle = role.color ? `style="background-color: ${role.color}20;"` : '';
                    optionsHtml += `<option value="${role.id}" ${colorStyle}>${role.name}</option>`;
                });
                select.innerHTML = optionsHtml;

                // Show the group
                group.style.display = 'block';
            }

            // Hide the tenant role dropdown
            hideTenantRoleGroup(formType) {
                const group = document.getElementById(`${formType}TenantRoleGroup`);
                const select = document.getElementById(`${formType}TenantRole`);

                if (group) {
                    group.style.display = 'none';
                }
                if (select) {
                    select.value = '';
                }
            }

            formatDate(dateString) {
                const date = new Date(dateString);
                return date.toLocaleDateString('it-IT', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric'
                });
            }

            normalizeCityInput(city) {
                const raw = String(city || '').trim();
                if (!raw) return '';
                const m = raw.match(/^(.*)\s*\([A-Z]{2}\)\s*$/);
                const base = (m && m[1]) ? String(m[1]).trim() : raw;
                return base.replace(/\s+/g, ' ').trim();
            }

            async validateItalianMunicipality(city) {
                const q = this.normalizeCityInput(city);
                if (!q || q.length < 2) return { ok: true, query: q, skipped: true };

                if (!this._municipalityCache) this._municipalityCache = new Map();
                const cacheKey = q.toLowerCase();
                if (this._municipalityCache.has(cacheKey)) return this._municipalityCache.get(cacheKey);

                try {
                    const url = `api/locations/search_municipalities.php?q=${encodeURIComponent(q)}&limit=8`;
                    const resp = await fetch(url, { method: 'GET', credentials: 'same-origin' });
                    if (!resp.ok) {
                        const out = { ok: true, query: q, skipped: true, warning: 'validation_unavailable' };
                        this._municipalityCache.set(cacheKey, out);
                        return out;
                    }
                    const data = await resp.json();
                    const results = Array.isArray(data?.data?.results) ? data.data.results : [];
                    const exact = results.find(r => String(r?.name || '').toLowerCase() === q.toLowerCase()) || null;
                    const out = exact ? {
                        ok: true,
                        query: q,
                        municipality: {
                            name: String(exact.name || ''),
                            province_code: String(exact.province_code || ''),
                            province_name: String(exact.province_name || ''),
                            region: String(exact.region || '')
                        }
                    } : {
                        ok: false,
                        query: q,
                        suggestions: results.slice(0, 5).map(r => ({
                            name: String(r?.name || ''),
                            province_code: String(r?.province_code || ''),
                            province_name: String(r?.province_name || ''),
                            region: String(r?.region || '')
                        })).filter(x => x.name && x.province_code)
                    };
                    this._municipalityCache.set(cacheKey, out);
                    return out;
                } catch (e) {
                    const out = { ok: true, query: q, skipped: true, warning: 'validation_unavailable' };
                    this._municipalityCache.set(cacheKey, out);
                    return out;
                }
            }

            showToast(message, type = 'info') {
                const toast = document.getElementById('toast');
                toast.textContent = message;
                toast.className = `toast show ${type}`;

                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
            }
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        function openAddModal() {
            console.log('=== OPENING ADD USER MODAL ===');
            const form = document.getElementById('addUserForm');
            console.log('Form found:', form);
            form.reset();
            // Set default role and trigger tenant field update
            document.getElementById('addRole').value = 'user';
            console.log('Triggering role change for: user');
            userManager.handleRoleChange('user', 'add');
            // Load tenant roles for selected Company Filter (if any)
            if (userManager && typeof userManager.refreshTenantRoleFromCompanyFilter === 'function') {
                userManager.refreshTenantRoleFromCompanyFilter('add');
            }
            openModal('addModal');
            console.log('Modal opened successfully');
        }

        function confirmDelete() {
            userManager.confirmDelete();
        }

        // Initialize when DOM is ready
        let userManager;
        document.addEventListener('DOMContentLoaded', () => {
            userManager = new UserManager();
        });
    </script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>