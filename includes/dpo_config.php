<?php
/**
 * DPO / Privacy contact configuration helper.
 *
 * Loads values from system_settings with safe fallbacks:
 * - dpo_protocol_number
 * - privacy_contact_recipient_email
 * - privacy_contact_public_url
 */

function cnx_get_dpo_config_from_database(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $fallback = [
        'protocol_number' => '20250009908',
        'recipient_email' => 'asamodeo@fortibyte.it',
        'public_contact_url' => 'https://app.nexiosolution.it/CollaboraNexio/privacy_contact.php'
    ];

    try {
        require_once __DIR__ . '/db.php';
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT setting_key, setting_value
            FROM system_settings
            WHERE setting_key IN ('dpo_protocol_number', 'privacy_contact_recipient_email', 'privacy_contact_public_url')
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $cfg = [
            'protocol_number' => (string)($rows['dpo_protocol_number'] ?? $fallback['protocol_number']),
            'recipient_email' => (string)($rows['privacy_contact_recipient_email'] ?? $fallback['recipient_email']),
            'public_contact_url' => (string)($rows['privacy_contact_public_url'] ?? $fallback['public_contact_url'])
        ];

        // basic sanity
        if ($cfg['recipient_email'] === '') $cfg['recipient_email'] = $fallback['recipient_email'];
        if ($cfg['public_contact_url'] === '') $cfg['public_contact_url'] = $fallback['public_contact_url'];
        if ($cfg['protocol_number'] === '') $cfg['protocol_number'] = $fallback['protocol_number'];

        $cached = $cfg;
        return $cfg;
    } catch (Throwable $e) {
        // best-effort fallback; do not hard-fail legal flows
        $cached = $fallback;
        return $fallback;
    }
}


