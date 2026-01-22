<?php
/**
 * Send onboarding/docs PDFs to an existing user (super_admin only).
 *
 * POST JSON:
 *  - user_id: int
 *  - doc_names: optional array of basenames (defaults to all docs/*.pdf)
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/email_layout.php';
require_once __DIR__ . '/../../includes/email_template_renderer.php';
require_once __DIR__ . '/../../includes/user_docs.php';

initializeApiEnvironment();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();
verifyApiCsrfToken();

$userInfo = getApiUserInfo();
$role = (string)($userInfo['role'] ?? ($_SESSION['role'] ?? 'user'));
if ($role !== 'super_admin') {
    api_error('Accesso negato', 403);
}

$raw = cnx_get_raw_request_body();
$payload = $raw ? json_decode($raw, true) : null;
if (!is_array($payload)) $payload = [];

$targetUserId = (int)($payload['user_id'] ?? 0);
if ($targetUserId <= 0) {
    api_error('user_id non valido', 400);
}

// Resolve attachments
$docNames = $payload['doc_names'] ?? null;
if (is_array($docNames)) {
    $docs = cnx_filter_user_doc_pdfs_by_name($docNames);
} else {
    $docs = cnx_list_user_doc_pdfs();
}

if (empty($docs)) {
    api_error('Nessun PDF disponibile in docs/*.pdf', 404);
}

$db = Database::getInstance();
$pdo = $db->getConnection();

$u = $db->fetchOne(
    "SELECT id, name, email, tenant_id, deleted_at
     FROM users
     WHERE id = ?
     LIMIT 1",
    [$targetUserId]
);
if (!$u || !empty($u['deleted_at'])) {
    api_error('Utente non trovato', 404);
}

$to = (string)($u['email'] ?? '');
$userName = (string)($u['name'] ?? 'Utente');
if ($to === '') {
    api_error('Email utente non valida', 400);
}

$tenantName = '';
try {
    $tid = (int)($u['tenant_id'] ?? 0);
    if ($tid > 0) {
        $t = $db->fetchOne("SELECT COALESCE(denominazione, name) AS tn FROM tenants WHERE id = ? LIMIT 1", [$tid]);
        $tenantName = (string)($t['tn'] ?? '');
    }
} catch (Throwable $e) {
    // non-blocking
}

$templatePath = __DIR__ . '/../../includes/email_templates/users/user_docs_email.html';
$tpl = is_file($templatePath) ? (string)file_get_contents($templatePath) : '';
if ($tpl === '') {
    api_error('Template email mancante', 500);
}

$docBasenames = array_map(fn($d) => (string)($d['name'] ?? ''), $docs);

$bodyInner = emailRenderTemplate($tpl, [
    'USER_NAME' => $userName,
    'DOC_NAMES' => array_values(array_filter($docBasenames))
]);

$htmlBody = renderEmailLayout(
    'Documentazione Nexio (PDF)',
    $bodyInner,
    [
        'BASE_URL' => defined('BASE_URL') ? BASE_URL : '',
        'TENANT_NAME' => $tenantName
    ],
    [
        'preheader' => 'In allegato la documentazione PDF di Nexio.'
    ]
);

$attachments = array_map(
    fn($d) => ['path' => (string)$d['path'], 'name' => (string)$d['name']],
    $docs
);

$ctx = [
    'action' => 'send_user_docs',
    'tenant_id' => $u['tenant_id'] ?? null,
    'user_id' => $_SESSION['user_id'] ?? null
];

$ok = sendEmail(
    $to,
    'Nexio — Documentazione (PDF)',
    $htmlBody,
    '',
    [
        'attachments' => $attachments,
        'context' => $ctx
    ]
);

if (!$ok) {
    api_error('Invio email fallito (verifica configurazione SMTP)', 500);
}

api_success([
    'user_id' => $targetUserId,
    'to' => $to,
    'docs' => $docBasenames,
    'docs_count' => count($docBasenames)
], 'Email inviata con allegati');


