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

<style>
    .sidebar {
        min-height: 100vh;
        background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
        padding: 0;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        position: fixed;
        top: 0;
        left: 0;
        width: 250px;
        z-index: 1000;
        transition: transform 0.3s ease;
    }

    .sidebar.collapsed {
        transform: translateX(-250px);
    }

    .sidebar-header {
        background: rgba(255,255,255,0.05);
        padding: 20px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        margin-bottom: 20px;
    }

    .sidebar-logo {
        display: flex;
        align-items: center;
        color: white;
        text-decoration: none;
        font-size: 1.3rem;
        font-weight: 600;
    }

    .sidebar-logo .logo-img {
        width: 40px;
        height: 40px;
        margin-right: 12px;
        border-radius: 8px;
        background: white;
        padding: 2px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    .sidebar-logo i {
        font-size: 1.5rem;
        margin-right: 10px;
        background: linear-gradient(45deg, #667eea, #764ba2);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .sidebar-nav {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .sidebar-nav .nav-item {
        margin: 0;
    }

    .sidebar-nav .nav-link {
        color: rgba(255,255,255,0.8);
        padding: 12px 20px;
        display: flex;
        align-items: center;
        text-decoration: none;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .sidebar-nav .nav-link:hover {
        color: white;
        background: rgba(255,255,255,0.1);
        padding-left: 25px;
    }

    .sidebar-nav .nav-link.active {
        color: white;
        background: linear-gradient(90deg, rgba(102,126,234,0.3) 0%, rgba(118,75,162,0.3) 100%);
        border-left: 3px solid #667eea;
    }

    .sidebar-nav .nav-link i {
        font-size: 1.1rem;
        width: 30px;
        text-align: center;
        margin-right: 10px;
    }

    .sidebar-nav .nav-badge {
        margin-left: auto;
        background: #dc3545;
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }

    .sidebar-footer {
        position: absolute;
        bottom: 0;
        width: 100%;
        padding: 20px;
        border-top: 1px solid rgba(255,255,255,0.1);
        background: rgba(0,0,0,0.2);
    }

    .user-info {
        display: flex;
        align-items: center;
        color: white;
        margin-bottom: 15px;
    }

    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(45deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 10px;
        font-weight: 600;
    }

    .user-details {
        flex: 1;
    }

    .user-name {
        font-size: 0.9rem;
        font-weight: 600;
        margin: 0;
    }

    .user-role {
        font-size: 0.75rem;
        opacity: 0.7;
        margin: 0;
    }

    .sidebar-toggle {
        position: fixed;
        top: 20px;
        left: 260px;
        z-index: 1001;
        background: #667eea;
        color: white;
        border: none;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(102,126,234,0.3);
        transition: all 0.3s ease;
    }

    .sidebar-toggle:hover {
        background: #764ba2;
        transform: scale(1.1);
    }

    .sidebar.collapsed + .sidebar-toggle {
        left: 10px;
    }

    .content-wrapper {
        margin-left: 250px;
        padding: 20px;
        transition: margin-left 0.3s ease;
        min-height: 100vh;
    }

    .content-wrapper.expanded {
        margin-left: 0;
    }

    .nav-divider {
        height: 1px;
        background: rgba(255,255,255,0.1);
        margin: 20px 0;
    }

    .nav-category {
        color: rgba(255,255,255,0.5);
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        padding: 0 20px;
        margin-bottom: 10px;
        letter-spacing: 1px;
    }

    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-250px);
        }

        .sidebar.show {
            transform: translateX(0);
        }

        .content-wrapper {
            margin-left: 0;
        }

        .sidebar-toggle {
            left: 10px;
        }

        .sidebar.show + .sidebar-toggle {
            left: 260px;
        }
    }
</style>

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