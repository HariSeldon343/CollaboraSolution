<?php
// Initialize session with proper configuration
require_once __DIR__ . '/includes/session_init.php';
// Authentication check - redirect to login if not authenticated
require_once __DIR__ . '/includes/auth_simple.php';
require_once __DIR__ . '/includes/company_filter.php';
require_once __DIR__ . '/includes/ai/embedding_utils.php';
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
checkPageAccess('ai');

// Initialize company filter
$companyFilter = new CompanyFilter($currentUser);

// Generate CSRF token for any forms
$csrfToken = $auth->generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
<?php
    $pageTitle = 'AI Hub - Nexio';
    $pageCss = [
        'assets/css/dashboard.css',
        'assets/css/ai_hub.css?v=' . (string)filemtime(__DIR__ . '/assets/css/ai_hub.css'),
    ];
    require __DIR__ . '/includes/layout_head.php';
?>
</head>
<?php require __DIR__ . '/includes/layout_start.php'; ?>
    <div class="header">
        <h1 class="page-title">AI Hub</h1>
        <div class="flex items-center gap-4">
            <?php if ($companyFilter->canUseCompanyFilter()): ?>
                <?php echo $companyFilter->renderDropdown(); ?>
            <?php endif; ?>
            <span class="text-sm text-muted">Benvenuto, <?php echo htmlspecialchars($currentUser['name']); ?></span>
        </div>
    </div>

    <div class="page-content">
        <div class="ai-hub-wrap">
            <div class="ai-hub-card">
                <div class="ai-hub-header">
                    <h2 class="ai-hub-title">Chat (multi-provider)</h2>
                    <div class="ai-hub-controls">
                        <select id="aiProviderSelect" class="form-control"></select>
                        <input id="aiModelInput" class="form-control" placeholder="Model (es. gpt-5.2 / sonar)" />
                        <select id="aiKnowledgeFolderSelect" class="form-control" style="min-width: 160px; display:none;" title="Cartella knowledge (se presenti più opzioni)"></select>
                        <label class="form-checkbox-label" style="margin:0;">
                            <input type="checkbox" id="aiUseKnowledge" class="form-checkbox" checked>
                            <span>Usa documentazione tenant</span>
                        </label>
                        <button type="button" class="btn btn-secondary btn-sm" id="aiOpenKnowledgeFolderBtn" style="display:none;">Apri cartella</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="aiIndexBtn" title="Indicizza /Knowledge o /IMS (richiede manager/admin)">Indicizza knowledge</button>
                    </div>
                </div>
                <div class="ai-hub-body">
                    <div id="aiWarning" class="ai-hub-warn"></div>
                    <div class="ai-hub-knowledge-test">
                        <div class="ai-hub-knowledge-test-row">
                            <input id="aiKnowledgeTestInput" class="form-control" placeholder="Test rapido: cerca nelle fonti indicizzate..." autocomplete="off" />
                            <button type="button" class="btn btn-secondary btn-sm" id="aiKnowledgeTestBtn">Cerca fonti</button>
                        </div>
                        <div id="aiKnowledgeTestResults" class="ai-hub-knowledge-test-results" style="display:none;"></div>
                    </div>
                    <div id="aiMessages" class="ai-hub-messages"></div>
                    <form id="aiChatForm" class="ai-hub-footer">
                        <input id="aiInput" class="form-control" placeholder="Scrivi una domanda..." autocomplete="off" />
                        <button type="submit" class="btn btn-primary" id="aiSendBtn">Invia</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/ai_hub.js?v=<?php echo (string)filemtime(__DIR__ . '/assets/js/ai_hub.js'); ?>"></script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>