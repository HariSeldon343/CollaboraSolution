<?php
/**
 * Super Admin: manage DPO/Privacy contact registry + protocol recordkeeping.
 *
 * Uses system_settings as the source of truth + logs changes into dpo_protocol_history (if available).
 *
 * GET  action=get_current
 * GET  action=get_history
 * POST action=update  JSON { protocol_number, recipient_email, public_contact_url, note }
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/api_auth.php';

initializeApiEnvironment();
verifyApiAuthentication();
verifyApiCsrfToken();
requireApiRole('super_admin');

$db = Database::getInstance();
$conn = $db->getConnection();

$action = (string)($_GET['action'] ?? '');

function cnx_ss_get(PDO $conn, string $key, string $fallback = ''): string {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $v = (string)($row['setting_value'] ?? '');
    return $v !== '' ? $v : $fallback;
}

function cnx_ss_set(PDO $conn, string $key, string $value, string $type = 'string'): void {
    $stmt = $conn->prepare("
        INSERT INTO system_settings (setting_key, setting_value, value_type, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW()
    ");
    $stmt->execute([$key, $value, $type]);
}

function cnx_table_exists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

if ($action === 'get_current') {
    $current = [
        'protocol_number' => cnx_ss_get($conn, 'dpo_protocol_number', '20250009908'),
        'recipient_email' => cnx_ss_get($conn, 'privacy_contact_recipient_email', 'asamodeo@fortibyte.it'),
        'public_contact_url' => cnx_ss_get($conn, 'privacy_contact_public_url', 'https://app.nexiosolution.it/CollaboraNexio/privacy_contact.php'),
        'protocol_reference' => 'prot. ' . cnx_ss_get($conn, 'dpo_protocol_number', '20250009908'),
    ];
    api_success(['current' => $current]);
}

if ($action === 'get_history') {
    if (!cnx_table_exists($conn, 'dpo_protocol_history')) {
        api_success(['history' => [], 'history_storage_available' => false]);
    }
    $rows = $db->fetchAll("SELECT id, protocol_number, recipient_email, public_contact_url, event_type, changed_by, changed_at, note FROM dpo_protocol_history ORDER BY id DESC LIMIT 200");
    api_success(['history' => $rows, 'history_storage_available' => true]);
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = cnx_get_raw_request_body();
    $payload = $raw ? json_decode($raw, true) : null;
    if (!is_array($payload)) $payload = [];

    $protocol = trim((string)($payload['protocol_number'] ?? ''));
    $email = trim((string)($payload['recipient_email'] ?? ''));
    $url = trim((string)($payload['public_contact_url'] ?? ''));
    $note = trim((string)($payload['note'] ?? ''));

    if ($protocol === '') api_error('Protocollo mancante', 400);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('Email destinatario non valida', 400);
    if ($url === '' || !preg_match('#^https?://#i', $url)) api_error('URL pubblico non valido (deve iniziare con http/https)', 400);

    $userInfo = getApiUserInfo();
    $changedBy = (int)($userInfo['id'] ?? 0);

    $old = [
        'protocol_number' => cnx_ss_get($conn, 'dpo_protocol_number', ''),
        'recipient_email' => cnx_ss_get($conn, 'privacy_contact_recipient_email', ''),
        'public_contact_url' => cnx_ss_get($conn, 'privacy_contact_public_url', ''),
    ];
    $new = [
        'protocol_number' => $protocol,
        'recipient_email' => $email,
        'public_contact_url' => $url,
    ];

    $conn->beginTransaction();
    try {
        cnx_ss_set($conn, 'dpo_protocol_number', $protocol, 'string');
        cnx_ss_set($conn, 'privacy_contact_recipient_email', $email, 'string');
        cnx_ss_set($conn, 'privacy_contact_public_url', $url, 'string');

        $historyAvailable = cnx_table_exists($conn, 'dpo_protocol_history');
        if ($historyAvailable) {
            $stmt = $conn->prepare("
                INSERT INTO dpo_protocol_history (protocol_number, recipient_email, public_contact_url, event_type, old_values_json, new_values_json, changed_by, note)
                VALUES (?, ?, ?, 'update', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $protocol,
                $email,
                $url,
                json_encode($old, JSON_UNESCAPED_UNICODE),
                json_encode($new, JSON_UNESCAPED_UNICODE),
                $changedBy > 0 ? $changedBy : null,
                $note !== '' ? $note : null
            ]);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        api_error('Errore salvataggio configurazione DPO', 500);
    }

    api_success([
        'saved' => true,
        'history_storage_available' => cnx_table_exists($conn, 'dpo_protocol_history')
    ], 'Configurazione DPO aggiornata');
}

api_error('Azione non valida', 400);


