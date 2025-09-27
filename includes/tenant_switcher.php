<?php
/**
 * Tenant Switcher Component
 * Allows Admin and Super Admin users to switch between tenants
 */

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only show for admin and super_admin roles
$user_role = $_SESSION['role'] ?? 'user';
if (!in_array($user_role, ['admin', 'super_admin'])) {
    return;
}

// Get database connection
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

// Get user's accessible tenants
$user_id = $_SESSION['user_id'];
$tenants = [];

try {
    if ($user_role === 'super_admin') {
        // Super admin can access all active tenants
        $query = "SELECT id, name, domain FROM tenants WHERE status = 'active' ORDER BY name";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Admin can access assigned tenants
        $query = "SELECT DISTINCT t.id, t.name, t.domain
                  FROM tenants t
                  WHERE t.id IN (
                      SELECT tenant_id FROM user_tenant_access WHERE user_id = :user_id
                      UNION
                      SELECT tenant_id FROM users WHERE id = :user_id2
                  )
                  AND t.status = 'active'
                  ORDER BY t.name";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':user_id' => $user_id, ':user_id2' => $user_id]);
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log('Tenant Switcher Error: ' . $e->getMessage());
    $tenants = [];
}

// Get current selected tenant
$current_tenant_id = $_SESSION['selected_tenant_id'] ?? $_SESSION['tenant_id'] ?? null;
$view_all_tenants = $_SESSION['view_all_tenants'] ?? false;

// Handle tenant switching
if (isset($_POST['switch_tenant'])) {
    $new_tenant_id = $_POST['switch_tenant'];

    if ($new_tenant_id === 'all') {
        $_SESSION['view_all_tenants'] = true;
        $_SESSION['selected_tenant_id'] = null;
    } else {
        $_SESSION['view_all_tenants'] = false;
        $_SESSION['selected_tenant_id'] = filter_var($new_tenant_id, FILTER_VALIDATE_INT);

        // Verify user has access to this tenant
        $has_access = false;
        foreach ($tenants as $tenant) {
            if ($tenant['id'] == $_SESSION['selected_tenant_id']) {
                $has_access = true;
                break;
            }
        }

        if (!$has_access) {
            $_SESSION['selected_tenant_id'] = $_SESSION['tenant_id'];
        }
    }

    // Reload page to apply changes
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Only show switcher if user has access to multiple tenants
if (count($tenants) <= 1) {
    return;
}
?>

<style>
.tenant-switcher {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 5px 15px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 8px;
    color: white;
    font-size: 14px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.tenant-switcher label {
    font-weight: 600;
    margin: 0;
    color: white;
}

.tenant-switcher select {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    background: white;
    color: #333;
    font-size: 14px;
    cursor: pointer;
    min-width: 200px;
    transition: all 0.3s ease;
}

.tenant-switcher select:hover {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.tenant-switcher select:focus {
    outline: 2px solid #667eea;
    outline-offset: 2px;
}

.tenant-switcher-wrapper {
    position: fixed;
    top: 15px;
    right: 15px;
    z-index: 9999;
}

@media (max-width: 768px) {
    .tenant-switcher-wrapper {
        position: static;
        margin: 10px 0;
    }

    .tenant-switcher {
        flex-direction: column;
        align-items: stretch;
        width: 100%;
        padding: 10px;
    }

    .tenant-switcher select {
        width: 100%;
    }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
    .tenant-switcher {
        background: linear-gradient(135deg, #4c5fd5 0%, #6b3aa0 100%);
    }

    .tenant-switcher select {
        background: #2d3748;
        color: #e2e8f0;
    }

    .tenant-switcher select:hover {
        background: #4a5568;
    }
}
</style>

<div class="tenant-switcher-wrapper">
    <form method="POST" class="tenant-switcher" id="tenantSwitcherForm">
        <label for="tenantSelect">
            <i class="fas fa-building"></i>
            Azienda:
        </label>
        <select name="switch_tenant" id="tenantSelect" onchange="this.form.submit()">
            <?php if ($user_role === 'super_admin'): ?>
                <option value="all" <?php echo $view_all_tenants ? 'selected' : ''; ?>>
                    🌍 Tutte le aziende
                </option>
            <?php endif; ?>

            <?php foreach ($tenants as $tenant): ?>
                <option value="<?php echo htmlspecialchars($tenant['id']); ?>"
                        <?php echo (!$view_all_tenants && $tenant['id'] == $current_tenant_id) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($tenant['name']); ?>
                    <?php if (!empty($tenant['domain'])): ?>
                        (<?php echo htmlspecialchars($tenant['domain']); ?>)
                    <?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<script>
// Add keyboard shortcut for tenant switching (Ctrl+Shift+T)
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.shiftKey && e.key === 'T') {
        e.preventDefault();
        document.getElementById('tenantSelect').focus();
    }
});

// Show tooltip on hover
document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('tenantSelect');
    if (select) {
        select.title = 'Cambia azienda (Ctrl+Shift+T)';
    }
});

// Auto-save preference
const tenantSelect = document.getElementById('tenantSelect');
if (tenantSelect) {
    // Store the preference in localStorage for persistence
    tenantSelect.addEventListener('change', function() {
        localStorage.setItem('preferred_tenant', this.value);
    });

    // Restore preference if page was refreshed without form submission
    const savedTenant = localStorage.getItem('preferred_tenant');
    if (savedTenant && !tenantSelect.value) {
        tenantSelect.value = savedTenant;
    }
}
</script>