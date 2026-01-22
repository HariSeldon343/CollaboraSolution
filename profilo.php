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

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();

// Load DB-backed profile data (schema-drift safe) + handle form submissions
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/locations_municipalities.php';
$db = Database::getInstance();

// Flash message (local to this page)
$profileFlash = $_SESSION['profilo_flash'] ?? null;
unset($_SESSION['profilo_flash']);

// Detect optional columns on users table
$userCols = [];
try {
    $rows = $db->fetchAll(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'"
    ) ?: [];
    foreach ($rows as $r) {
        $k = (string)($r['COLUMN_NAME'] ?? '');
        if ($k !== '') $userCols[$k] = true;
    }
} catch (Throwable $e) {
    $userCols = [];
}
$hasHomeCity = !empty($userCols['home_city']);
$hasJobTitle = !empty($userCols['job_title']);
$hasSkillsText = !empty($userCols['skills_text']);
$hasCertText = !empty($userCols['certifications_text']);
$hasPasswordMaxAge = !empty($userCols['password_max_age_days']);
$hasPasswordSetAt = !empty($userCols['password_set_at']);
$hasPasswordExpiresAt = !empty($userCols['password_expires_at']);

// Detect notification preferences table (optional)
$hasNotifPrefsTable = false;
try {
    $t = $db->fetchOne(
        "SELECT 1 AS ok
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_notification_preferences'
         LIMIT 1"
    );
    $hasNotifPrefsTable = ((int)($t['ok'] ?? 0) === 1);
} catch (Throwable $e) {
    $hasNotifPrefsTable = false;
}

$currentUserId = (int)($currentUser['id'] ?? 0);
$currentTenantId = (int)($currentUser['tenant_id'] ?? 0);

// Helper: load profile row from DB
$loadProfileRow = static function () use ($db, $currentUserId, $hasHomeCity, $hasJobTitle, $hasSkillsText, $hasCertText, $hasPasswordMaxAge, $hasPasswordExpiresAt): ?array {
    if ($currentUserId <= 0) return null;
    $select = "SELECT u.id, u.name, u.email, u.role, u.tenant_id";
    if ($hasHomeCity) $select .= ", u.home_city";
    if ($hasJobTitle) $select .= ", u.job_title";
    if ($hasSkillsText) $select .= ", u.skills_text";
    if ($hasCertText) $select .= ", u.certifications_text";
    if ($hasPasswordMaxAge) $select .= ", u.password_max_age_days";
    if ($hasPasswordExpiresAt) $select .= ", u.password_expires_at";
    $select .= ", t.denominazione AS tenant_denominazione, t.name AS tenant_name";
    $select .= " FROM users u LEFT JOIN tenants t ON t.id = u.tenant_id";
    $select .= " WHERE u.id = ? AND u.deleted_at IS NULL LIMIT 1";
    try {
        return $db->fetchOne($select, [$currentUserId]) ?: null;
    } catch (Throwable $e) {
        return null;
    }
};

// Load notification preferences
$loadNotifPrefs = static function () use ($db, $hasNotifPrefsTable, $currentUserId): ?array {
    if (!$hasNotifPrefsTable || $currentUserId <= 0) return null;
    try {
        return $db->fetchOne(
            "SELECT *
             FROM user_notification_preferences
             WHERE user_id = ?
               AND deleted_at IS NULL
             LIMIT 1",
            [$currentUserId]
        ) ?: null;
    } catch (Throwable $e) {
        return null;
    }
};

// Handle POST actions (profile update / password change / notifications)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!$auth->verifyCSRFToken($token)) {
        $_SESSION['profilo_flash'] = ['type' => 'error', 'message' => 'Token CSRF non valido. Riprova.'];
        header('Location: profilo.php');
        exit;
    }

    if ($action === 'update_profile') {
        $first = trim((string)($_POST['first_name'] ?? ''));
        $last = trim((string)($_POST['last_name'] ?? ''));
        $fullName = trim($first . ' ' . $last);
        if ($fullName === '' || mb_strlen($fullName, 'UTF-8') < 2) {
            $_SESSION['profilo_flash'] = ['type' => 'error', 'message' => 'Nome e cognome non validi.'];
            header('Location: profilo.php');
            exit;
        }
        if (mb_strlen($fullName, 'UTF-8') > 120) {
            $fullName = mb_substr($fullName, 0, 120, 'UTF-8');
        }

        $update = [
            'name' => $fullName,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Optional fields
        if ($hasHomeCity) {
            $hc = trim((string)($_POST['home_city'] ?? ''));
            if (mb_strlen($hc, 'UTF-8') > 120) $hc = mb_substr($hc, 0, 120, 'UTF-8');
            $hc = ($hc !== '') ? $hc : null;

            // Best-effort validation against ISTAT municipalities (if dataset available)
            try {
                if ($hc !== null) {
                    $norm = cnx_locations_normalize_city_input($hc);
                    if ($norm !== '' && !cnx_locations_city_exists($db, $norm)) {
                        $sug = cnx_locations_city_suggestions($db, $norm, 8);
                        $msg = 'Città di residenza non riconosciuta. Seleziona un comune italiano valido.';
                        if (!empty($sug)) {
                            $msg .= ' Suggerimenti: ' . implode(', ', array_map('strval', $sug));
                        }
                        $_SESSION['profilo_flash'] = ['type' => 'error', 'message' => $msg];
                        header('Location: profilo.php');
                        exit;
                    }
                }
            } catch (Throwable $e) {
                // non-blocking
            }

            $update['home_city'] = $hc;
        }

        if ($hasJobTitle) {
            $jt = trim((string)($_POST['job_title'] ?? ''));
            if (mb_strlen($jt, 'UTF-8') > 160) $jt = mb_substr($jt, 0, 160, 'UTF-8');
            $update['job_title'] = ($jt !== '') ? $jt : null;
        }
        if ($hasSkillsText) {
            $st = trim((string)($_POST['skills_text'] ?? ''));
            if (mb_strlen($st, 'UTF-8') > 8000) $st = mb_substr($st, 0, 8000, 'UTF-8');
            $update['skills_text'] = ($st !== '') ? $st : null;
        }
        if ($hasCertText) {
            $ct = trim((string)($_POST['certifications_text'] ?? ''));
            if (mb_strlen($ct, 'UTF-8') > 8000) $ct = mb_substr($ct, 0, 8000, 'UTF-8');
            $update['certifications_text'] = ($ct !== '') ? $ct : null;
        }

        try {
            $db->update('users', $update, ['id' => $currentUserId]);
            // Keep session data in sync
            $_SESSION['user_name'] = $fullName;
            $_SESSION['user_email'] = $_SESSION['user_email'] ?? ($currentUser['email'] ?? '');
            $_SESSION['profilo_flash'] = ['type' => 'success', 'message' => 'Profilo aggiornato.'];
        } catch (Throwable $e) {
            $_SESSION['profilo_flash'] = ['type' => 'error', 'message' => 'Errore salvataggio profilo.'];
        }
        header('Location: profilo.php');
        exit;
    }

    if ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        $errors = [];
        if ($currentPassword === '') $errors[] = 'Inserisci la password attuale';
        if (strlen($newPassword) < 8) $errors[] = 'La nuova password deve contenere almeno 8 caratteri';
        if (!preg_match('/[A-Z]/', $newPassword)) $errors[] = 'La nuova password deve contenere almeno una lettera maiuscola';
        if (!preg_match('/[a-z]/', $newPassword)) $errors[] = 'La nuova password deve contenere almeno una lettera minuscola';
        if (!preg_match('/[0-9]/', $newPassword)) $errors[] = 'La nuova password deve contenere almeno un numero';
        if ($newPassword !== $confirmPassword) $errors[] = 'Le password non coincidono';

        if (!empty($errors)) {
            $_SESSION['profilo_flash'] = ['type' => 'error', 'message' => implode(' • ', $errors)];
            header('Location: profilo.php');
            exit;
        }

        try {
            $row = $db->fetchOne(
                "SELECT id, password_hash" . ($hasPasswordMaxAge ? ", password_max_age_days" : "") . "
                 FROM users
                 WHERE id = ?
                   AND deleted_at IS NULL
                 LIMIT 1",
                [$currentUserId]
            );
            if (!$row || !password_verify($currentPassword, (string)($row['password_hash'] ?? ''))) {
                $_SESSION['profilo_flash'] = ['type' => 'error', 'message' => 'Password attuale non corretta'];
                header('Location: profilo.php');
                exit;
            }

            $maxAgeDays = 90;
            if ($hasPasswordMaxAge && isset($row['password_max_age_days']) && (int)$row['password_max_age_days'] > 0) {
                $maxAgeDays = (int)$row['password_max_age_days'];
            }
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $maxAgeDays . ' days'));

            $upd = [
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($hasPasswordSetAt) $upd['password_set_at'] = date('Y-m-d H:i:s');
            if ($hasPasswordExpiresAt) $upd['password_expires_at'] = $expiresAt;

            $db->update('users', $upd, ['id' => $currentUserId]);
            $_SESSION['profilo_flash'] = ['type' => 'success', 'message' => 'Password aggiornata.'];
        } catch (Throwable $e) {
            $_SESSION['profilo_flash'] = ['type' => 'error', 'message' => 'Errore aggiornamento password.'];
        }
        header('Location: profilo.php');
        exit;
    }

    if ($action === 'save_notifications') {
        if (!$hasNotifPrefsTable || $currentTenantId <= 0) {
            $_SESSION['profilo_flash'] = ['type' => 'warning', 'message' => 'Preferenze notifiche non disponibili su questa installazione.'];
            header('Location: profilo.php');
            exit;
        }

        // Map UI toggles to DB fields
        $notifyTasks = isset($_POST['notify_tasks']) ? 1 : 0;
        $notifyComments = isset($_POST['notify_comments']) ? 1 : 0;
        $notifyReminders = isset($_POST['notify_reminders']) ? 1 : 0;

        try {
            $existing = $loadNotifPrefs();
            $now = date('Y-m-d H:i:s');
            if ($existing) {
                $db->update('user_notification_preferences', [
                    'notify_task_created' => $notifyTasks,
                    'notify_task_assigned' => $notifyTasks,
                    'notify_task_comment_added' => $notifyComments,
                    'notify_task_due_soon' => $notifyReminders,
                    'notify_task_overdue' => $notifyReminders,
                    'updated_at' => $now,
                ], ['id' => (int)$existing['id']]);
            } else {
                $db->insert('user_notification_preferences', [
                    'tenant_id' => $currentTenantId,
                    'user_id' => $currentUserId,
                    'notify_task_created' => $notifyTasks,
                    'notify_task_assigned' => $notifyTasks,
                    'notify_task_removed' => 1,
                    'notify_task_updated' => 1,
                    'notify_task_status_changed' => 1,
                    'notify_task_comment_added' => $notifyComments,
                    'notify_task_due_soon' => $notifyReminders,
                    'notify_task_overdue' => $notifyReminders,
                    'notify_task_priority_changed' => 1,
                    'notify_task_completed' => 0,
                    'email_digest_enabled' => 0,
                    'quiet_hours_enabled' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $_SESSION['profilo_flash'] = ['type' => 'success', 'message' => 'Preferenze notifiche salvate.'];
        } catch (Throwable $e) {
            $_SESSION['profilo_flash'] = ['type' => 'error', 'message' => 'Errore salvataggio preferenze notifiche.'];
        }
        header('Location: profilo.php');
        exit;
    }
}

$profileRow = $loadProfileRow();
$notifPrefs = $loadNotifPrefs();

// Keep $currentUser in sync with DB-backed values for display
if (is_array($profileRow)) {
    $currentUser['name'] = $profileRow['name'] ?? $currentUser['name'];
    $currentUser['email'] = $profileRow['email'] ?? $currentUser['email'];
    $currentUser['tenant_id'] = $profileRow['tenant_id'] ?? $currentUser['tenant_id'];
    $currentUser['tenant_name'] = $profileRow['tenant_denominazione'] ?? ($profileRow['tenant_name'] ?? ($currentUser['tenant_name'] ?? ''));
}

// Values for form prefilling
$fullNameForForm = trim((string)($currentUser['name'] ?? ''));
$nameParts = preg_split('/\s+/', $fullNameForForm) ?: [];
$profileFirstName = $nameParts[0] ?? '';
$profileLastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';
$profileHomeCity = $hasHomeCity ? (string)($profileRow['home_city'] ?? '') : '';
$profileJobTitle = $hasJobTitle ? (string)($profileRow['job_title'] ?? '') : '';
$profileSkillsText = $hasSkillsText ? (string)($profileRow['skills_text'] ?? '') : '';
$profileCertText = $hasCertText ? (string)($profileRow['certifications_text'] ?? '') : '';

// Notification UI defaults (best-effort)
$notifNotifyTasks = $notifPrefs ? (bool)($notifPrefs['notify_task_assigned'] ?? true) : true;
$notifNotifyComments = $notifPrefs ? (bool)($notifPrefs['notify_task_comment_added'] ?? false) : false;
$notifNotifyReminders = $notifPrefs ? (bool)($notifPrefs['notify_task_due_soon'] ?? true) : true;
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'Il Mio Profilo - Nexio';
    $pageCss = ['assets/css/dashboard.css'];
    require __DIR__ . '/includes/layout_head.php';
?>

    <style>
        .profile-container {
            padding: var(--space-6);
            max-width: 1200px;
            margin: 0 auto;
        }

        .profile-header {
            background: linear-gradient(135deg, var(--color-primary), var(--color-secondary));
            padding: var(--space-8);
            border-radius: var(--radius-lg);
            color: white;
            margin-bottom: var(--space-8);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .profile-info {
            display: flex;
            align-items: center;
            gap: var(--space-6);
            position: relative;
            z-index: 1;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: bold;
            color: var(--color-primary);
            position: relative;
        }

        .avatar-upload {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 36px;
            height: 36px;
            background: var(--color-primary);
            border: 3px solid white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform var(--transition-fast);
        }

        .avatar-upload:hover {
            transform: scale(1.1);
        }

        .profile-details {
            flex: 1;
        }

        .profile-name {
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            margin-bottom: var(--space-2);
        }

        .profile-role {
            display: inline-block;
            padding: var(--space-1) var(--space-3);
            background: rgba(255, 255, 255, 0.2);
            border-radius: var(--radius-full);
            font-size: var(--text-sm);
            margin-bottom: var(--space-2);
        }

        .profile-meta {
            display: flex;
            gap: var(--space-6);
            font-size: var(--text-sm);
            opacity: 0.9;
        }

        .profile-tabs {
            display: flex;
            gap: var(--space-2);
            margin-bottom: var(--space-6);
            background: var(--color-white);
            padding: var(--space-2);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .profile-tab {
            padding: var(--space-3) var(--space-6);
            background: transparent;
            border: none;
            color: var(--color-gray-600);
            font-weight: var(--font-medium);
            cursor: pointer;
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
        }

        .profile-tab:hover {
            background: var(--color-gray-50);
            color: var(--color-gray-900);
        }

        .profile-tab.active {
            background: var(--color-primary);
            color: white;
        }

        .profile-section {
            background: var(--color-white);
            padding: var(--space-6);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--space-6);
        }

        .section-title {
            font-size: var(--text-lg);
            font-weight: var(--font-semibold);
            color: var(--color-gray-900);
            margin-bottom: var(--space-4);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--space-6);
            margin-bottom: var(--space-6);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: var(--space-2);
        }

        .form-label {
            font-size: var(--text-sm);
            font-weight: var(--font-medium);
            color: var(--color-gray-700);
        }

        .form-control:disabled {
            background: var(--color-gray-50);
            cursor: not-allowed;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: var(--space-4);
        }

        .activity-item {
            display: flex;
            gap: var(--space-4);
            padding: var(--space-4);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--color-primary-100);
            color: var(--color-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
            margin-bottom: var(--space-1);
        }

        .activity-time {
            font-size: var(--text-sm);
            color: var(--color-gray-500);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-4);
            margin-bottom: var(--space-6);
        }

        .stat-card {
            padding: var(--space-4);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
            text-align: center;
        }

        .stat-value {
            font-size: var(--text-2xl);
            font-weight: var(--font-bold);
            color: var(--color-primary);
            margin-bottom: var(--space-1);
        }

        .stat-label {
            font-size: var(--text-sm);
            color: var(--color-gray-600);
        }

        .password-section {
            background: var(--color-warning-50);
            padding: var(--space-4);
            border-radius: var(--radius-md);
            border: 1px solid var(--color-warning-200);
            margin-bottom: var(--space-6);
        }

        .notification-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: var(--space-4);
            background: var(--color-gray-50);
            border-radius: var(--radius-md);
            margin-bottom: var(--space-3);
        }

        .notification-info {
            display: flex;
            flex-direction: column;
            gap: var(--space-1);
        }

        .notification-title {
            font-weight: var(--font-medium);
            color: var(--color-gray-900);
        }

        .notification-desc {
            font-size: var(--text-sm);
            color: var(--color-gray-600);
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--color-gray-300);
            transition: background var(--transition-fast);
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            transition: transform var(--transition-fast);
            border-radius: 50%;
        }

        input:checked + .toggle-slider {
            background: var(--color-primary);
        }

        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .profile-info {
                flex-direction: column;
                text-align: center;
            }

            .profile-meta {
                justify-content: center;
            }
        }
    </style>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
            <!-- Top Bar -->
            <div class="header">
                <h1 class="page-title">Il Mio Profilo</h1>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
                </div>
            </div>

            <!-- Page Content -->
            <div class="page-content">
                <?php if (is_array($profileFlash) && !empty($profileFlash['message'])): ?>
                    <?php
                        $ft = (string)($profileFlash['type'] ?? 'info');
                        $bg = '#eff6ff';
                        $bd = '#bfdbfe';
                        $fg = '#1d4ed8';
                        if ($ft === 'success') { $bg = '#ecfdf5'; $bd = '#bbf7d0'; $fg = '#065f46'; }
                        elseif ($ft === 'error') { $bg = '#fef2f2'; $bd = '#fecaca'; $fg = '#991b1b'; }
                        elseif ($ft === 'warning') { $bg = '#fffbeb'; $bd = '#fde68a'; $fg = '#92400e'; }
                    ?>
                    <div style="max-width: 1200px; margin: 0 auto 14px auto; padding: 10px 12px; border-radius: 10px; border: 1px solid <?php echo htmlspecialchars($bd); ?>; background: <?php echo htmlspecialchars($bg); ?>; color: <?php echo htmlspecialchars($fg); ?>;">
                        <?php echo htmlspecialchars((string)$profileFlash['message']); ?>
                    </div>
                <?php endif; ?>
                <div class="profile-container">
                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="profile-info">
                            <div class="profile-avatar">
                                <?php echo strtoupper(substr($currentUser['name'], 0, 2)); ?>
                                <div class="avatar-upload">
                                    <i class="icon icon--camera" style="color: white"></i>
                                </div>
                            </div>
                            <div class="profile-details">
                                <h2 class="profile-name"><?php echo htmlspecialchars($currentUser['name']); ?></h2>
                                <span class="profile-role">
                                    <?php
                                    $roleDisplay = [
                                        'super_admin' => 'Super Amministratore',
                                        'admin' => 'Amministratore',
                                        'manager' => 'Manager',
                                        'user' => 'Utente'
                                    ];
                                    echo $roleDisplay[$currentUser['role']] ?? 'Utente';
                                    ?>
                                </span>
                                <div class="profile-meta">
                                    <span><i class="icon icon--mail"></i> <?php echo htmlspecialchars($currentUser['email']); ?></span>
                                    <span><i class="icon icon--calendar"></i> Membro dal 01/01/2024</span>
                                    <span><i class="icon icon--building"></i> <?php echo htmlspecialchars($currentUser['tenant_name'] ?? ''); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Tabs -->
                    <div class="profile-tabs">
                        <button type="button" class="profile-tab active" onclick="switchTab('info', event)">
                            <i class="icon icon--user"></i> Informazioni
                        </button>
                        <button type="button" class="profile-tab" onclick="switchTab('security', event)">
                            <i class="icon icon--lock"></i> Sicurezza
                        </button>
                    </div>

                    <!-- Info Tab -->
                    <div id="info-tab" class="tab-content active">
                        <div class="profile-section">
                            <h3 class="section-title">Informazioni Personali</h3>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Nome</label>
                                        <input type="text" class="form-control" name="first_name" required value="<?php echo htmlspecialchars($profileFirstName); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Cognome</label>
                                        <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($profileLastName); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($currentUser['email']); ?>" disabled>
                                    </div>
                                    <?php if ($hasHomeCity): ?>
                                    <div class="form-group">
                                        <label class="form-label">Città di residenza (opzionale)</label>
                                        <input type="text" class="form-control" name="home_city" placeholder="Es. Messina" value="<?php echo htmlspecialchars($profileHomeCity); ?>">
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($hasJobTitle): ?>
                                    <div class="form-group">
                                        <label class="form-label">Titolo / Ruolo professionale (opzionale)</label>
                                        <input type="text" class="form-control" name="job_title" placeholder="Es. Lead Auditor, Consulente SGQ..." value="<?php echo htmlspecialchars($profileJobTitle); ?>">
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($hasSkillsText): ?>
                                <div class="form-group">
                                    <label class="form-label">Competenze (opzionale)</label>
                                    <textarea class="form-control" name="skills_text" rows="4" placeholder="Competenze, ambiti, esperienze..."><?php echo htmlspecialchars($profileSkillsText); ?></textarea>
                                </div>
                                <?php endif; ?>

                                <?php if ($hasCertText): ?>
                                <div class="form-group">
                                    <label class="form-label">Certificazioni (opzionale)</label>
                                    <textarea class="form-control" name="certifications_text" rows="3" placeholder="Es. Lead Auditor ISO 9001, ISO 14001..."><?php echo htmlspecialchars($profileCertText); ?></textarea>
                                </div>
                                <?php endif; ?>

                                <?php if (!$hasHomeCity && !$hasJobTitle && !$hasSkillsText && !$hasCertText): ?>
                                    <div style="margin-top: 6px; color: var(--color-gray-600); font-size: var(--text-sm);">
                                        Nota: alcuni campi profilo avanzati non sono disponibili su questa installazione (migrazioni non applicate).
                                    </div>
                                <?php endif; ?>

                                <div style="display: flex; justify-content: flex-end; gap: var(--space-3)">
                                    <button type="reset" class="btn btn-secondary">Annulla</button>
                                    <button type="submit" class="btn btn-primary">Salva Modifiche</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Security Tab -->
                    <div id="security-tab" class="tab-content">
                        <div class="profile-section">
                            <h3 class="section-title">Sicurezza Account</h3>

                            <div class="password-section">
                                <h4 style="margin-bottom: var(--space-2)">Modifica Password</h4>
                                <p style="color: var(--color-gray-600); font-size: var(--text-sm)">
                                    Assicurati di utilizzare una password sicura di almeno 8 caratteri
                                </p>
                            </div>

                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="change_password">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Password Attuale</label>
                                        <input type="password" class="form-control" name="current_password" required>
                                    </div>
                                    <div></div>
                                    <div class="form-group">
                                        <label class="form-label">Nuova Password</label>
                                        <input type="password" class="form-control" name="new_password" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Conferma Nuova Password</label>
                                        <input type="password" class="form-control" name="confirm_password" required>
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: flex-end; gap: var(--space-3); margin-bottom: var(--space-8)">
                                    <button type="submit" class="btn btn-primary">Aggiorna Password</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

    <!-- Scripts -->
    <script src="assets/js/app.js"></script>
    <script>
        function switchTab(tabName, ev) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all buttons
            document.querySelectorAll('.profile-tab').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');

            // Add active class to clicked button
            const e = ev || window.event;
            const btn = e && e.target && e.target.closest ? e.target.closest('.profile-tab') : null;
            if (btn) btn.classList.add('active');
        }
    </script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>