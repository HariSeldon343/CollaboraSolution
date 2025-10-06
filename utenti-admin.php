<?php
require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/auth.php';
require_once __DIR__ . '/backend/includes/AdminGate.php';

// Admin gate check
AdminGate::requireSuperUser();

$pageTitle = 'Gestione Utenti';
$currentPage = 'admin';

// Include header
require_once __DIR__ . '/includes/header.php';
?>

<style>
.admin-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--color-main-background);
    border-radius: var(--border-radius-large);
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
}

.stat-icon.users { background: rgba(47, 90, 143, 0.1); color: var(--color-primary); }
.stat-icon.active { background: rgba(40, 167, 69, 0.1); color: var(--color-success); }
.stat-icon.admins { background: rgba(220, 53, 69, 0.1); color: var(--color-danger); }
.stat-icon.projects { background: rgba(23, 162, 184, 0.1); color: var(--color-info); }

.stat-content h3 {
    font-size: 2rem;
    font-weight: 600;
    margin: 0;
}

.stat-content p {
    color: var(--color-text-light);
    margin: 0;
    font-size: 0.875rem;
}

.users-toolbar {
    background: var(--color-main-background);
    border-radius: var(--border-radius-large);
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.toolbar-left {
    display: flex;
    gap: 1rem;
    align-items: center;
    flex: 1;
}

.search-box {
    position: relative;
    flex: 1;
    max-width: 400px;
}

.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--color-text-light);
}

.search-box input {
    padding-left: 36px;
    width: 100%;
}

.users-table {
    background: var(--color-main-background);
    border-radius: var(--border-radius-large);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.table-responsive {
    overflow-x: auto;
}

.user-row {
    transition: background var(--transition-fast);
}

.user-row:hover {
    background: var(--color-background-hover);
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--color-primary), #764ba2);
    color: white;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    margin-right: 1rem;
}

.user-info {
    display: flex;
    align-items: center;
}

.user-details {
    display: flex;
    flex-direction: column;
}

.user-name {
    font-weight: 500;
    color: var(--color-main-text);
}

.user-email {
    font-size: 0.875rem;
    color: var(--color-text-light);
}

.role-badge {
    padding: 0.25rem 0.75rem;
    border-radius: var(--border-radius-pill);
    font-size: 0.875rem;
    font-weight: 500;
}

.role-super_user { background: rgba(220, 53, 69, 0.1); color: var(--color-danger); }
.role-project_user { background: rgba(47, 90, 143, 0.1); color: var(--color-primary); }

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.75rem;
    border-radius: var(--border-radius-pill);
    font-size: 0.875rem;
}

.status-active { background: rgba(40, 167, 69, 0.1); color: var(--color-success); }
.status-inactive { background: rgba(108, 117, 125, 0.1); color: var(--color-secondary); }
.status-blocked { background: rgba(220, 53, 69, 0.1); color: var(--color-danger); }

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-icon {
    padding: 0.375rem;
    border-radius: var(--border-radius);
    background: none;
    border: none;
    color: var(--color-text-light);
    cursor: pointer;
    transition: all var(--transition-fast);
}

.btn-icon:hover {
    background: var(--color-background-hover);
    color: var(--color-primary);
}

/* Modal Styles */
.modal-body {
    padding: 1.5rem 0;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: var(--color-main-text);
}

.form-control {
    width: 100%;
    padding: 0.625rem 0.875rem;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    font-size: 0.95rem;
    transition: border-color var(--transition-fast);
}

.form-control:focus {
    outline: none;
    border-color: var(--color-primary);
    box-shadow: 0 0 0 3px rgba(47, 90, 143, 0.1);
}

.form-hint {
    font-size: 0.875rem;
    color: var(--color-text-light);
    margin-top: 0.25rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border);
}

@media (max-width: 768px) {
    .admin-stats {
        grid-template-columns: 1fr;
    }
    
    .users-toolbar {
        flex-direction: column;
    }
    
    .toolbar-left {
        width: 100%;
        flex-direction: column;
    }
    
    .search-box {
        max-width: none;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<!-- Admin Statistics -->
<div class="admin-stats">
    <div class="stat-card">
        <div class="stat-icon users">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
            <h3 id="totalUsers">0</h3>
            <p>Utenti totali</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon active">
            <i class="fas fa-user-check"></i>
        </div>
        <div class="stat-content">
            <h3 id="activeUsers">0</h3>
            <p>Utenti attivi</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon admins">
            <i class="fas fa-user-shield"></i>
        </div>
        <div class="stat-content">
            <h3 id="adminUsers">0</h3>
            <p>Super utenti</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon projects">
            <i class="fas fa-briefcase"></i>
        </div>
        <div class="stat-content">
            <h3 id="avgProjects">0</h3>
            <p>Media progetti/utente</p>
        </div>
    </div>
</div>

<!-- Users Toolbar -->
<div class="users-toolbar">
    <div class="toolbar-left">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" class="form-control" placeholder="Cerca utenti..." id="searchInput">
        </div>
        
        <select class="form-control" style="width: auto;" id="filterRole">
            <option value="">Tutti i ruoli</option>
            <option value="super_user">Super User</option>
            <option value="project_user">Project User</option>
        </select>
        
        <select class="form-control" style="width: auto;" id="filterStatus">
            <option value="">Tutti gli stati</option>
            <option value="attivo">Attivi</option>
            <option value="inattivo">Inattivi</option>
            <option value="bloccato">Bloccati</option>
        </select>
    </div>
    
    <button class="btn btn-primary" onclick="openNewUserModal()">
        <i class="fas fa-user-plus"></i> Nuovo utente
    </button>
</div>

<!-- Users Table -->
<div class="users-table">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Utente</th>
                    <th>Ruolo</th>
                    <th>Stato</th>
                    <th>Ultimo accesso</th>
                    <th>Progetti</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
                <tr>
                    <td colspan="6" class="text-center p-4">
                        <div class="progress-circular mx-auto"></div>
                        <p class="text-muted mt-2">Caricamento utenti...</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- New/Edit User Modal -->
<div class="modal" id="userModal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Nuovo Utente</h2>
            <button class="modal-close" onclick="closeUserModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="userForm">
            <input type="hidden" name="id" id="userId">
            
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cognome *</label>
                        <input type="text" name="cognome" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" required>
                    <div class="form-hint">L'email sarà utilizzata per il login</div>
                </div>
                
                <div class="form-group" id="passwordGroup">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" id="passwordField">
                    <div class="form-hint">Minimo 8 caratteri</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Ruolo *</label>
                        <select name="ruolo" class="form-control" required>
                            <option value="project_user">Project User</option>
                            <option value="super_user">Super User</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Stato *</label>
                        <select name="stato" class="form-control" required>
                            <option value="attivo">Attivo</option>
                            <option value="inattivo">Inattivo</option>
                            <option value="bloccato">Bloccato</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Telefono</label>
                    <input type="tel" name="telefono" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea name="note" class="form-control" rows="3"></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeUserModal()">Annulla</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Salva utente
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal" id="resetPasswordModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h2 class="modal-title">Reset Password</h2>
            <button class="modal-close" onclick="closeResetPasswordModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="resetPasswordForm">
            <input type="hidden" name="user_id" id="resetUserId">
            
            <div class="modal-body">
                <p class="text-muted mb-3">
                    Stai per reimpostare la password per l'utente:
                    <strong id="resetUserName"></strong>
                </p>
                
                <div class="form-group">
                    <label class="form-label">Nuova password *</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8">
                    <div class="form-hint">Minimo 8 caratteri</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Conferma password *</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8">
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeResetPasswordModal()">Annulla</button>
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-key"></i> Reset password
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Global variables
let usersData = [];
let editingUserId = null;

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadUsers();
    setupEventListeners();
    
    // Auto-refresh every 30 seconds
    setInterval(loadUsers, 30000);
});

// Setup event listeners
function setupEventListeners() {
    // Search and filters
    document.getElementById('searchInput').addEventListener('input', debounce(filterUsers, 300));
    document.getElementById('filterRole').addEventListener('change', filterUsers);
    document.getElementById('filterStatus').addEventListener('change', filterUsers);
    
    // User form
    document.getElementById('userForm').addEventListener('submit', handleUserSubmit);
    
    // Reset password form
    document.getElementById('resetPasswordForm').addEventListener('submit', handleResetPassword);
}

// Load users
async function loadUsers() {
    try {
        const response = await fetch('/backend/api/users-api.php?action=list');
        const result = await response.json();
        
        if (result.success) {
            usersData = result.data;
            renderUsers();
            updateStatistics();
        } else {
            showError(result.error || 'Errore nel caricamento degli utenti');
        }
    } catch (error) {
        console.error('Error loading users:', error);
        showError('Errore di connessione');
    }
}

// Render users table
function renderUsers() {
    const tbody = document.getElementById('usersTableBody');
    let filteredUsers = [...usersData];
    
    // Apply filters
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const roleFilter = document.getElementById('filterRole').value;
    const statusFilter = document.getElementById('filterStatus').value;
    
    if (searchTerm) {
        filteredUsers = filteredUsers.filter(user =>
            user.nome.toLowerCase().includes(searchTerm) ||
            user.cognome.toLowerCase().includes(searchTerm) ||
            user.email.toLowerCase().includes(searchTerm)
        );
    }
    
    if (roleFilter) {
        filteredUsers = filteredUsers.filter(user => user.ruolo === roleFilter);
    }
    
    if (statusFilter) {
        filteredUsers = filteredUsers.filter(user => user.stato === statusFilter);
    }
    
    if (filteredUsers.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center p-4">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Nessun utente trovato</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = filteredUsers.map(user => {
        const roleBadge = getRoleBadge(user.ruolo);
        const statusBadge = getStatusBadge(user.stato);
        
        return `
            <tr class="user-row">
                <td>
                    <div class="user-info">
                        <div class="user-avatar">
                            ${getInitials(user.nome + ' ' + user.cognome)}
                        </div>
                        <div class="user-details">
                            <span class="user-name">${escapeHtml(user.nome)} ${escapeHtml(user.cognome)}</span>
                            <span class="user-email">${escapeHtml(user.email)}</span>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="role-badge role-${user.ruolo}">${roleBadge}</span>
                </td>
                <td>
                    <span class="status-badge status-${user.stato}">
                        <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                        ${statusBadge}
                    </span>
                </td>
                <td>${formatDateTime(user.ultimo_accesso)}</td>
                <td>${user.progetti_count || 0}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-icon" onclick="editUser(${user.id})" title="Modifica">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn-icon" onclick="resetPassword(${user.id}, '${escapeHtml(user.nome)} ${escapeHtml(user.cognome)}')" title="Reset password">
                            <i class="fas fa-key"></i>
                        </button>
                        <button class="btn-icon" onclick="toggleUserStatus(${user.id}, '${user.stato}')" title="Cambia stato">
                            <i class="fas fa-${user.stato === 'attivo' ? 'ban' : 'check-circle'}"></i>
                        </button>
                        ${user.id !== <?php echo Auth::getCurrentUserId(); ?> ? `
                            <button class="btn-icon text-danger" onclick="deleteUser(${user.id})" title="Elimina">
                                <i class="fas fa-trash"></i>
                            </button>
                        ` : ''}
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

// Update statistics
function updateStatistics() {
    const stats = {
        total: usersData.length,
        active: usersData.filter(u => u.stato === 'attivo').length,
        admins: usersData.filter(u => u.ruolo === 'super_user').length,
        avgProjects: 0
    };
    
    if (usersData.length > 0) {
        const totalProjects = usersData.reduce((sum, user) => sum + (user.progetti_count || 0), 0);
        stats.avgProjects = Math.round(totalProjects / usersData.length * 10) / 10;
    }
    
    document.getElementById('totalUsers').textContent = stats.total;
    document.getElementById('activeUsers').textContent = stats.active;
    document.getElementById('adminUsers').textContent = stats.admins;
    document.getElementById('avgProjects').textContent = stats.avgProjects;
}

// Filter users
function filterUsers() {
    renderUsers();
}

// Open new user modal
function openNewUserModal() {
    editingUserId = null;
    document.getElementById('modalTitle').textContent = 'Nuovo Utente';
    document.getElementById('userForm').reset();
    document.getElementById('userId').value = '';
    document.getElementById('passwordField').required = true;
    document.getElementById('passwordGroup').style.display = 'block';
    document.getElementById('userModal').classList.add('active');
}

// Edit user
async function editUser(userId) {
    editingUserId = userId;
    const user = usersData.find(u => u.id === userId);
    
    if (!user) return;
    
    document.getElementById('modalTitle').textContent = 'Modifica Utente';
    document.getElementById('userId').value = user.id;
    document.getElementById('passwordGroup').style.display = 'none';
    document.getElementById('passwordField').required = false;
    
    // Populate form
    const form = document.getElementById('userForm');
    form.nome.value = user.nome;
    form.cognome.value = user.cognome;
    form.email.value = user.email;
    form.ruolo.value = user.ruolo;
    form.stato.value = user.stato;
    form.telefono.value = user.telefono || '';
    form.note.value = user.note || '';
    
    document.getElementById('userModal').classList.add('active');
}

// Close user modal
function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
    document.getElementById('userForm').reset();
    editingUserId = null;
}

// Handle user form submit
async function handleUserSubmit(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    formData.append('action', editingUserId ? 'update' : 'create');
    
    try {
        const response = await fetch('/backend/api/users-api.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess(editingUserId ? 'Utente aggiornato con successo' : 'Utente creato con successo');
            closeUserModal();
            loadUsers();
        } else {
            showError(result.error || 'Errore nel salvataggio');
        }
    } catch (error) {
        console.error('Error saving user:', error);
        showError('Errore di connessione');
    }
}

// Reset password
function resetPassword(userId, userName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserName').textContent = userName;
    document.getElementById('resetPasswordModal').classList.add('active');
}

// Close reset password modal
function closeResetPasswordModal() {
    document.getElementById('resetPasswordModal').classList.remove('active');
    document.getElementById('resetPasswordForm').reset();
}

// Handle reset password
async function handleResetPassword(e) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    
    // Check passwords match
    if (formData.get('new_password') !== formData.get('confirm_password')) {
        showError('Le password non corrispondono');
        return;
    }
    
    formData.append('action', 'reset_password');
    
    try {
        const response = await fetch('/backend/api/users-api.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('Password reimpostata con successo');
            closeResetPasswordModal();
        } else {
            showError(result.error || 'Errore nel reset della password');
        }
    } catch (error) {
        console.error('Error resetting password:', error);
        showError('Errore di connessione');
    }
}

// Toggle user status
async function toggleUserStatus(userId, currentStatus) {
    const newStatus = currentStatus === 'attivo' ? 'bloccato' : 'attivo';
    const action = currentStatus === 'attivo' ? 'bloccare' : 'attivare';
    
    if (!confirm(`Sei sicuro di voler ${action} questo utente?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('id', userId);
    formData.append('stato', newStatus);
    
    try {
        const response = await fetch('/backend/api/users-api.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('Stato utente aggiornato');
            loadUsers();
        } else {
            showError(result.error || 'Errore nell\'aggiornamento dello stato');
        }
    } catch (error) {
        console.error('Error toggling user status:', error);
        showError('Errore di connessione');
    }
}

// Delete user
async function deleteUser(userId) {
    if (!confirm('Sei sicuro di voler eliminare questo utente? L\'azione non può essere annullata.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', userId);
    
    try {
        const response = await fetch('/backend/api/users-api.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('Utente eliminato con successo');
            loadUsers();
        } else {
            showError(result.error || 'Errore nell\'eliminazione dell\'utente');
        }
    } catch (error) {
        console.error('Error deleting user:', error);
        showError('Errore di connessione');
    }
}

// Utility functions
function getRoleBadge(role) {
    const roles = {
        'super_user': 'Super User',
        'project_user': 'Project User'
    };
    return roles[role] || role;
}

function getStatusBadge(status) {
    const statuses = {
        'attivo': 'Attivo',
        'inattivo': 'Inattivo',
        'bloccato': 'Bloccato'
    };
    return statuses[status] || status;
}

function getInitials(name) {
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
}

function formatDateTime(datetime) {
    if (!datetime) return 'Mai';
    return new Date(datetime).toLocaleString('it-IT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function showSuccess(message) {
    const snackbar = document.createElement('div');
    snackbar.className = 'snackbar show';
    snackbar.innerHTML = `
        <i class="fas fa-check-circle"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(snackbar);
    
    setTimeout(() => {
        snackbar.classList.remove('show');
        setTimeout(() => snackbar.remove(), 300);
    }, 3000);
}

function showError(message) {
    const snackbar = document.createElement('div');
    snackbar.className = 'snackbar show';
    snackbar.style.background = '#dc3545';
    snackbar.innerHTML = `
        <i class="fas fa-exclamation-circle"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(snackbar);
    
    setTimeout(() => {
        snackbar.classList.remove('show');
        setTimeout(() => snackbar.remove(), 300);
    }, 3000);
}
</script>

<!-- Additional styles -->
<style>
.text-danger { color: var(--color-danger) !important; }
.mx-auto { margin-left: auto; margin-right: auto; }
.text-center { text-align: center; }
.text-muted { color: var(--color-text-light); }
.mb-3 { margin-bottom: 1rem; }
.mt-2 { margin-top: 0.5rem; }
.p-4 { padding: 2rem; }

.progress-circular {
    width: 40px;
    height: 40px;
    border: 3px solid var(--color-background);
    border-top: 3px solid var(--color-primary);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php
// Include footer
require_once __DIR__ . '/includes/footer.php';
?>