<?php
/**
 * CollaboraNexio - Sidebar Navigation Component
 * Include questo file in tutte le pagine per avere una sidebar consistente
 */

// Ottieni il nome del file corrente per evidenziare la voce attiva
$current_page = basename($_SERVER['PHP_SELF']);

// Mappa delle pagine e icone
$menu_items = [
    'dashboard.php' => ['icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'badge' => null],
    'progetti.php' => ['icon' => 'bi-diagram-3', 'label' => 'Progetti', 'badge' => null],
    'tasks.php' => ['icon' => 'bi-check2-square', 'label' => 'Tasks', 'badge' => null],
    'calendar.php' => ['icon' => 'bi-calendar3', 'label' => 'Calendario', 'badge' => null],
    'files.php' => ['icon' => 'bi-folder', 'label' => 'File Manager', 'badge' => null],
    'chat.php' => ['icon' => 'bi-chat-dots', 'label' => 'Chat', 'badge' => 'new_messages'],
    'utenti.php' => ['icon' => 'bi-people', 'label' => 'Utenti', 'badge' => null],
];

// Funzione per ottenere notifiche non lette (placeholder)
function getUnreadCount($type) {
    // Qui dovresti fare una query al database
    // Per ora restituisco valori di esempio
    if ($type === 'new_messages') {
        return rand(0, 5); // Esempio: numero casuale di messaggi
    }
    return 0;
}
?>


<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="/dashboard.php" class="sidebar-logo">
            <img src="/CollaboraNexio/assets/images/logo.png" alt="CollaboraNexio" class="logo-img">
            <span>CollaboraNexio</span>
        </a>
    </div>

    <ul class="sidebar-nav">
        <li class="nav-category">PRINCIPALE</li>

        <?php foreach ($menu_items as $page => $item): ?>
            <?php if ($page === 'chat.php'): ?>
                <li class="nav-divider"></li>
                <li class="nav-category">COMUNICAZIONE</li>
            <?php elseif ($page === 'utenti.php'): ?>
                <li class="nav-divider"></li>
                <li class="nav-category">AMMINISTRAZIONE</li>
            <?php endif; ?>

            <li class="nav-item">
                <a href="/<?php echo $page; ?>" class="nav-link <?php echo ($current_page === $page) ? 'active' : ''; ?>">
                    <i class="bi <?php echo $item['icon']; ?>"></i>
                    <span><?php echo $item['label']; ?></span>
                    <?php
                    if ($item['badge']) {
                        $count = getUnreadCount($item['badge']);
                        if ($count > 0) {
                            echo '<span class="nav-badge">' . $count . '</span>';
                        }
                    }
                    ?>
                </a>
            </li>
        <?php endforeach; ?>

        <li class="nav-divider"></li>
        <li class="nav-category">SISTEMA</li>

        <li class="nav-item">
            <a href="/system_check.php" class="nav-link <?php echo ($current_page === 'system_check.php') ? 'active' : ''; ?>">
                <i class="bi bi-gear-wide-connected"></i>
                <span>System Check</span>
            </a>
        </li>

        <li class="nav-item">
            <a href="/settings.php" class="nav-link <?php echo ($current_page === 'settings.php') ? 'active' : ''; ?>">
                <i class="bi bi-gear"></i>
                <span>Impostazioni</span>
            </a>
        </li>
    </ul>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar">
                <?php
                $initials = isset($_SESSION['user']) ?
                    strtoupper(substr($_SESSION['user']['first_name'], 0, 1) . substr($_SESSION['user']['last_name'], 0, 1)) :
                    'UN';
                echo $initials;
                ?>
            </div>
            <div class="user-details">
                <p class="user-name">
                    <?php echo isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']['display_name']) : 'Utente'; ?>
                </p>
                <p class="user-role">
                    <?php echo isset($_SESSION['user']) ? ucfirst($_SESSION['user']['role']) : 'Guest'; ?>
                </p>
            </div>
        </div>

        <a href="/logout.php" class="btn btn-sm btn-outline-light w-100">
            <i class="bi bi-box-arrow-left"></i> Logout
        </a>
    </div>
</div>

<button class="sidebar-toggle" id="sidebarToggle">
    <i class="bi bi-list"></i>
</button>

<script>
    // Toggle sidebar
    document.getElementById('sidebarToggle').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        const content = document.querySelector('.content-wrapper');

        sidebar.classList.toggle('collapsed');
        if (content) {
            content.classList.toggle('expanded');
        }

        // Salva stato in localStorage
        const isCollapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('sidebarCollapsed', isCollapsed);
    });

    // Ripristina stato sidebar
    window.addEventListener('DOMContentLoaded', function() {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            document.getElementById('sidebar').classList.add('collapsed');
            const content = document.querySelector('.content-wrapper');
            if (content) {
                content.classList.add('expanded');
            }
        }
    });

    // Mobile toggle
    if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.add('collapsed');
    }
</script>