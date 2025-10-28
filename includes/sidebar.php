<?php
/**
 * CollaboraNexio - Sidebar Navigation Component (CSS Mask Icons)
 * Include questo file in tutte le pagine per avere una sidebar consistente
 */

// Ottieni il nome del file corrente per evidenziare la voce attiva
$current_page = basename($_SERVER['PHP_SELF']);

// Get current user info (should be available from auth)
$currentUser = $currentUser ?? $_SESSION['user'] ?? ['name' => 'Utente', 'role' => 'user'];
?>

<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <img src="<?php echo strpos($_SERVER['PHP_SELF'], '/api/') !== false ? '../' : ''; ?>assets/images/logo.png" alt="CollaboraNexio" class="logo-img">
            <span class="logo-text">NEXIO</span>
        </div>
        <div class="sidebar-subtitle">Semplifica, Connetti, Cresci Insieme</div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">AREA OPERATIVA</div>
            <a href="dashboard.php" class="nav-item <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                <i class="icon icon--home"></i> Dashboard
            </a>
            <a href="files.php" class="nav-item <?php echo $current_page === 'files.php' ? 'active' : ''; ?>">
                <i class="icon icon--folder"></i> File Manager
            </a>
            <a href="calendar.php" class="nav-item <?php echo $current_page === 'calendar.php' ? 'active' : ''; ?>">
                <i class="icon icon--calendar"></i> Calendario
            </a>
            <a href="tasks.php" class="nav-item <?php echo $current_page === 'tasks.php' ? 'active' : ''; ?>">
                <i class="icon icon--check"></i> Task
            </a>
            <a href="ticket.php" class="nav-item <?php echo $current_page === 'ticket.php' ? 'active' : ''; ?>">
                <i class="icon icon--ticket"></i> Ticket
            </a>
            <a href="conformita.php" class="nav-item <?php echo $current_page === 'conformita.php' ? 'active' : ''; ?>">
                <i class="icon icon--shield"></i> Conformità
            </a>
            <a href="ai.php" class="nav-item <?php echo $current_page === 'ai.php' ? 'active' : ''; ?>">
                <i class="icon icon--cpu"></i> AI
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">GESTIONE</div>
            <a href="aziende.php" class="nav-item <?php echo $current_page === 'aziende.php' ? 'active' : ''; ?>">
                <i class="icon icon--building"></i> Aziende
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">AMMINISTRAZIONE</div>
            <a href="utenti.php" class="nav-item <?php echo $current_page === 'utenti.php' ? 'active' : ''; ?>">
                <i class="icon icon--users"></i> Utenti
            </a>
            <a href="audit_log.php" class="nav-item <?php echo $current_page === 'audit_log.php' ? 'active' : ''; ?>">
                <i class="icon icon--chart"></i> Audit Log
            </a>
            <a href="configurazioni.php" class="nav-item <?php echo $current_page === 'configurazioni.php' ? 'active' : ''; ?>">
                <i class="icon icon--settings"></i> Configurazioni
            </a>
        </div>

        <div class="nav-section">
            <div class="nav-section-title">ACCOUNT</div>
            <a href="profilo.php" class="nav-item <?php echo $current_page === 'profilo.php' ? 'active' : ''; ?>">
                <i class="icon icon--user"></i> Il Mio Profilo
            </a>
            <a href="logout.php" class="nav-item <?php echo $current_page === 'logout.php' ? 'active' : ''; ?>">
                <i class="icon icon--logout"></i> Esci
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <?php
                echo isset($currentUser['name']) ? strtoupper(substr($currentUser['name'], 0, 2)) : 'UN';
                ?>
            </div>
            <div class="user-details">
                <div class="user-name">
                    <?php echo htmlspecialchars($currentUser['name'] ?? 'Utente'); ?>
                </div>
                <div class="user-badge">
                    <?php echo strtoupper(str_replace('_', ' ', $currentUser['role'] ?? 'USER')); ?>
                </div>
            </div>
        </div>
    </div>
</div>
