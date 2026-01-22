<?php
/**
 * GDPR: Privacy/DPO contact form submission.
 * Sends all submissions to the super user mailbox: asamodeo@fortibyte.it
 *
 * Authenticated-only endpoint (prevents spam).
 *
 * POST JSON:
 *  - request_type (string) e.g. access, rectification, deletion, limitation, objection, portability, info, other
 *  - subject (string)
 *  - message (string)
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/email_layout.php';
require_once __DIR__ . '/../../includes/email_template_renderer.php';
require_once __DIR__ . '/../../includes/dpo_config.php';

initializeApiEnvironment();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

verifyApiAuthentication();
verifyApiCsrfToken();

$userInfo = getApiUserInfo();
$userId = (int)($userInfo['id'] ?? ($userInfo['user_id'] ?? ($_SESSION['user_id'] ?? 0)));
$userRole = (string)($userInfo['role'] ?? ($_SESSION['role'] ?? 'user'));
$tenantId = (int)($userInfo['tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0));

if ($userId <= 0 || $tenantId <= 0) {
    api_error('Contesto utente/tenant non valido', 400);
}

$raw = cnx_get_raw_request_body();
$payload = $raw ? json_decode($raw, true) : null;
if (!is_array($payload)) $payload = [];

$requestType = trim((string)($payload['request_type'] ?? 'info'));
$subject = trim((string)($payload['subject'] ?? ''));
$message = trim((string)($payload['message'] ?? ''));

if ($subject === '' || mb_strlen($subject) < 4) {
    api_error('Oggetto troppo corto', 400);
}
if ($message === '' || mb_strlen($message) < 20) {
    api_error('Messaggio troppo corto (min 20 caratteri)', 400);
}

$allowedTypes = ['access','rectification','deletion','limitation','objection','portability','info','other'];
if (!in_array($requestType, $allowedTypes, true)) {
    $requestType = 'other';
}

$db = Database::getInstance();
$tenantRow = $db->fetchOne("SELECT COALESCE(denominazione, name) AS tn FROM tenants WHERE id = ? LIMIT 1", [$tenantId]);
$tenantName = (string)($tenantRow['tn'] ?? '');

$userRow = $db->fetchOne("SELECT id, name, email FROM users WHERE id = ? LIMIT 1", [$userId]);
$userName = (string)($userRow['name'] ?? 'Utente');
$userEmail = (string)($userRow['email'] ?? '');

$dpoCfg = cnx_get_dpo_config_from_database();
$to = (string)($dpoCfg['recipient_email'] ?? 'asamodeo@fortibyte.it');
$protocolNumber = (string)($dpoCfg['protocol_number'] ?? '20250009908');

$templatePath = __DIR__ . '/../../includes/email_templates/legal/privacy_contact_request.html';
$tpl = is_file($templatePath) ? (string)file_get_contents($templatePath) : '';
if ($tpl === '') {
    api_error('Template email mancante', 500);
}

$safeMessageHtml = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$inner = emailRenderTemplate($tpl, [
    'TENANT_NAME' => $tenantName,
    'TENANT_ID' => (string)$tenantId,
    'USER_NAME' => $userName,
    'USER_ID' => (string)$userId,
    'USER_ROLE' => $userRole,
    'USER_EMAIL' => $userEmail,
    'REQUEST_TYPE' => $requestType,
    'SUBJECT' => $subject,
    'MESSAGE' => $safeMessageHtml,
    'IP_ADDRESS' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'USER_AGENT' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'PROTOCOL_NUMBER' => $protocolNumber,
]);

$htmlBody = renderEmailLayout(
    'Segnalazione Privacy/DPO',
    $inner,
    [
        'BASE_URL' => defined('BASE_URL') ? BASE_URL : '',
        'TENANT_NAME' => $tenantName
    ],
    [
        'preheader' => 'Nuova segnalazione Privacy/DPO da Nexio.'
    ]
);

$ok = sendEmail(
    $to,
    'Nexio — Segnalazione Privacy/DPO (prot. ' . $protocolNumber . '): ' . $subject,
    $htmlBody,
    '',
    [
        'replyTo' => $userEmail ?: null,
        'context' => [
            'action' => 'privacy_contact',
            'tenant_id' => $tenantId,
            'user_id' => $userId
        ]
    ]
);

if (!$ok) {
    api_error('Invio segnalazione fallito (verifica configurazione SMTP)', 500);
}

api_success([
    'sent_to' => $to
], 'Segnalazione inviata');


