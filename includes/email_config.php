<?php
/**
 * Email Configuration Helper
 *
 * Carica le impostazioni email dal database system_settings
 * con fallback ai valori hardcoded per garantire funzionamento anche in caso di errori DB
 *
 * IMPORTANTE: Questo file risolve il problema di configurazione drift dove EmailSender.php
 * utilizzava credenziali hardcoded mentre il database aveva configurazioni diverse.
 *
 * Ordine di caricamento:
 * 1. Tenta di caricare da database (system_settings table)
 * 2. Se database non disponibile o valori mancanti, usa fallback hardcoded Infomaniak
 * 3. Cache in-memory per evitare query multiple nella stessa request
 */

/**
 * Carica la configurazione email dal database con fallback
 *
 * @return array Configurazione email con chiavi: smtpHost, smtpPort, smtpUsername, smtpPassword, fromEmail, fromName, replyTo
 */
function getEmailConfigFromDatabase() {
    static $cachedConfig = null;

    // Cache in-memory per evitare query multiple nella stessa request
    if ($cachedConfig !== null) {
        return $cachedConfig;
    }

    // Valori di fallback hardcoded (Nexio Solution - credenziali aggiornate)
    $fallbackConfig = [
        'smtpHost' => 'mail.nexiosolution.it',
        'smtpPort' => 465,
        'smtpUsername' => 'info@nexiosolution.it',
        'smtpPassword' => 'Ricord@1991',
        'fromEmail' => 'info@nexiosolution.it',
        'fromName' => 'CollaboraNexio',
        'replyTo' => 'info@nexiosolution.it'
    ];

    try {
        // Carica database connection
        require_once __DIR__ . '/db.php';
        $db = Database::getInstance();
        $conn = $db->getConnection();

        // Query per recuperare tutte le impostazioni email
        $stmt = $conn->prepare("
            SELECT setting_key, setting_value
            FROM system_settings
            WHERE setting_key IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'from_email', 'from_name', 'reply_to')
        ");

        $stmt->execute();
        $dbSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Costruisci configurazione dal database, usando fallback per valori mancanti
        $config = [
            'smtpHost' => $dbSettings['smtp_host'] ?? $fallbackConfig['smtpHost'],
            'smtpPort' => isset($dbSettings['smtp_port']) ? (int)$dbSettings['smtp_port'] : $fallbackConfig['smtpPort'],
            'smtpUsername' => $dbSettings['smtp_username'] ?? $fallbackConfig['smtpUsername'],
            'smtpPassword' => $dbSettings['smtp_password'] ?? $fallbackConfig['smtpPassword'],
            'fromEmail' => $dbSettings['from_email'] ?? $fallbackConfig['fromEmail'],
            'fromName' => $dbSettings['from_name'] ?? $fallbackConfig['fromName'],
            'replyTo' => $dbSettings['reply_to'] ?? $fallbackConfig['replyTo']
        ];

        // Verifica che almeno smtp_host sia presente dal database
        // Se mancante, significa che il database non ha impostazioni email configurate
        if (empty($dbSettings['smtp_host'])) {
            error_log("EmailConfig: Nessuna configurazione email trovata nel database, utilizzo fallback Nexio Solution");
            $cachedConfig = $fallbackConfig;
            return $fallbackConfig;
        }

        // Log successful database load (solo in debug mode)
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("EmailConfig: Configurazione caricata da database - Host: {$config['smtpHost']}, Port: {$config['smtpPort']}, From: {$config['fromEmail']}");
        }

        // Cache e ritorna configurazione
        $cachedConfig = $config;
        return $config;

    } catch (Exception $e) {
        // In caso di errore database, usa fallback e logga l'errore
        error_log("EmailConfig: Errore caricamento da database - " . $e->getMessage());
        error_log("EmailConfig: Utilizzo configurazione fallback Nexio Solution");

        $cachedConfig = $fallbackConfig;
        return $fallbackConfig;
    }
}

/**
 * Verifica se le impostazioni email sono configurate nel database
 * Utile per mostrare warning nell'interfaccia se manca configurazione
 *
 * @return bool True se configurate, false se sta usando fallback
 */
function isEmailConfiguredInDatabase() {
    try {
        require_once __DIR__ . '/db.php';
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM system_settings
            WHERE setting_key = 'smtp_host' AND setting_value != ''
        ");

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['count'] > 0;

    } catch (Exception $e) {
        error_log("EmailConfig: Errore verifica configurazione - " . $e->getMessage());
        return false;
    }
}

/**
 * Salva la configurazione email nel database
 * Usata dalle pagine di amministrazione per aggiornare le impostazioni
 *
 * @param array $config Array con chiavi: smtp_host, smtp_port, smtp_username, smtp_password (opzionale), from_email, from_name, reply_to
 * @return bool True se salvato con successo
 */
function saveEmailConfigToDatabase($config) {
    try {
        require_once __DIR__ . '/db.php';
        $db = Database::getInstance();
        $conn = $db->getConnection();

        $conn->beginTransaction();

        // Mapping tra chiavi array e chiavi database
        $settingsMap = [
            'smtp_host' => $config['smtp_host'] ?? '',
            'smtp_port' => $config['smtp_port'] ?? '465',
            'smtp_username' => $config['smtp_username'] ?? '',
            'from_email' => $config['from_email'] ?? '',
            'from_name' => $config['from_name'] ?? 'CollaboraNexio',
            'reply_to' => $config['reply_to'] ?? ''
        ];

        // Solo se la password è stata fornita esplicitamente (non vuota)
        if (isset($config['smtp_password']) && $config['smtp_password'] !== '') {
            $settingsMap['smtp_password'] = $config['smtp_password'];
        }

        foreach ($settingsMap as $key => $value) {
            // Determina il tipo di valore
            $type = 'string';
            if ($key === 'smtp_port') {
                $type = 'integer';
            }

            // Insert or update
            $stmt = $conn->prepare("
                INSERT INTO system_settings (setting_key, setting_value, value_type, updated_at)
                VALUES (:key, :value, :type, NOW())
                ON DUPLICATE KEY UPDATE
                    setting_value = :value,
                    value_type = :type,
                    updated_at = NOW()
            ");

            $stmt->execute([
                'key' => $key,
                'value' => (string)$value,
                'type' => $type
            ]);
        }

        $conn->commit();

        // Invalida cache in-memory per questa richiesta
        // (il cache statico si resetta automaticamente alla prossima richiesta)
        global $cachedConfig;
        $cachedConfig = null;

        error_log("EmailConfig: Configurazione salvata con successo nel database");
        return true;

    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log("EmailConfig: Errore salvataggio configurazione - " . $e->getMessage());
        return false;
    }
}
?>
