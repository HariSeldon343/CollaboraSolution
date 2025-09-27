<?php
/**
 * Sistema di Long-Polling per Chat Real-Time
 *
 * Implementa un sistema robusto di long-polling per gestire chat real-time
 * senza WebSockets, ottimizzato per supportare fino a 100 utenti concorrenti
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Classe LongPolling
 *
 * Gestisce il polling asincrono per messaggi chat, presenza utenti e indicatori di digitazione
 */
class LongPolling {

    /** @var PDO Connessione al database */
    private PDO $pdo;

    /** @var int Timeout massimo in secondi */
    private const MAX_TIMEOUT = 30;

    /** @var int Intervallo di sleep tra i controlli (millisecondi) */
    private const POLL_INTERVAL = 1000000; // 1 secondo in microsecondi

    /** @var int Tempo di vita dell'indicatore di digitazione (secondi) */
    private const TYPING_EXPIRE_TIME = 5;

    /** @var int Tempo per considerare un utente offline (secondi) */
    private const OFFLINE_THRESHOLD = 35;

    /** @var int Limite massimo di messaggi per risposta */
    private const MAX_MESSAGES_PER_RESPONSE = 50;

    /** @var array Cache per query ottimizzate */
    private array $queryCache = [];

    /**
     * Costruttore
     */
    public function __construct() {
        // Ottieni istanza database
        $db = Database::getInstance();
        $this->pdo = $db->getConnection();

        // Configurazione per long-polling
        ignore_user_abort(false);
        ob_implicit_flush(true);

        // Disabilita output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Imposta timeout dello script
        set_time_limit(self::MAX_TIMEOUT + 10);
    }

    /**
     * Metodo principale di polling
     *
     * @param int $tenant_id ID del tenant
     * @param int $user_id ID dell'utente
     * @param int $last_sequence_id Ultimo sequence_id ricevuto
     * @param array $channels Array di channel_id a cui l'utente è iscritto
     * @return array Risposta con nuovi messaggi e presenza
     */
    public function poll(int $tenant_id, int $user_id, int $last_sequence_id, array $channels): array {
        $startTime = time();
        $response = [
            'success' => true,
            'last_sequence_id' => $last_sequence_id,
            'messages' => [],
            'presence' => [
                'online_users' => [],
                'typing_users' => []
            ],
            'timestamp' => time()
        ];

        try {
            // Validazione accesso ai canali
            if (!$this->validateChannelAccess($tenant_id, $user_id, $channels)) {
                throw new Exception('Accesso non autorizzato ai canali richiesti');
            }

            // Aggiorna presenza utente
            $this->updateUserPresence($tenant_id, $user_id);

            // Loop di polling
            while ((time() - $startTime) < self::MAX_TIMEOUT) {
                // Controlla se la connessione è ancora attiva
                if (connection_aborted()) {
                    break;
                }

                // Controlla nuovi messaggi
                $newMessages = $this->checkNewMessages($tenant_id, $last_sequence_id, $channels);

                if (!empty($newMessages)) {
                    $response['messages'] = $newMessages;
                    $response['last_sequence_id'] = end($newMessages)['sequence_id'];

                    // Ottieni informazioni presenza
                    $response['presence'] = $this->getPresenceInfo($tenant_id, $channels);
                    break;
                }

                // Pulizia dati obsoleti (ogni 5 iterazioni)
                if ((time() - $startTime) % 5 == 0) {
                    $this->cleanupStaleData($tenant_id);
                }

                // Sleep per ridurre carico CPU
                usleep(self::POLL_INTERVAL);
            }

            // Se timeout senza nuovi messaggi, invia comunque presenza aggiornata
            if (empty($response['messages'])) {
                $response['presence'] = $this->getPresenceInfo($tenant_id, $channels);
            }

        } catch (Exception $e) {
            error_log("LongPolling Error: " . $e->getMessage());
            $response['success'] = false;
            $response['error'] = 'Errore durante il polling';
        }

        $response['timestamp'] = time();
        return $response;
    }

    /**
     * Controlla nuovi messaggi dal database
     *
     * @param int $tenant_id ID del tenant
     * @param int $last_sequence_id Ultimo sequence_id ricevuto
     * @param array $channels Array di channel_id
     * @return array Array di nuovi messaggi
     */
    public function checkNewMessages(int $tenant_id, int $last_sequence_id, array $channels): array {
        if (empty($channels)) {
            return [];
        }

        try {
            // Query ottimizzata con JOIN per recuperare info utente
            $placeholders = str_repeat('?,', count($channels) - 1) . '?';

            $sql = "SELECT
                        m.id,
                        m.sequence_id,
                        m.channel_id,
                        m.user_id,
                        m.parent_message_id,
                        m.message_type,
                        m.content,
                        m.is_edited,
                        m.is_pinned,
                        m.created_at,
                        m.updated_at,
                        u.nome AS user_name,
                        u.email AS user_email,
                        u.avatar_url AS user_avatar,
                        c.name AS channel_name,
                        c.channel_type
                    FROM chat_messages m
                    INNER JOIN users u ON m.user_id = u.id
                    INNER JOIN chat_channels c ON m.channel_id = c.id
                    WHERE m.tenant_id = ?
                        AND m.sequence_id > ?
                        AND m.channel_id IN ($placeholders)
                        AND m.is_deleted = 0
                    ORDER BY m.sequence_id ASC
                    LIMIT ?";

            $params = [$tenant_id, $last_sequence_id];
            $params = array_merge($params, $channels);
            $params[] = self::MAX_MESSAGES_PER_RESPONSE;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formatta messaggi per la risposta
            return array_map(function($msg) {
                return [
                    'id' => (int)$msg['id'],
                    'sequence_id' => (int)$msg['sequence_id'],
                    'channel_id' => (int)$msg['channel_id'],
                    'channel_name' => $msg['channel_name'],
                    'channel_type' => $msg['channel_type'],
                    'user' => [
                        'id' => (int)$msg['user_id'],
                        'name' => $msg['user_name'],
                        'email' => $msg['user_email'],
                        'avatar' => $msg['user_avatar']
                    ],
                    'parent_message_id' => $msg['parent_message_id'] ? (int)$msg['parent_message_id'] : null,
                    'message_type' => $msg['message_type'],
                    'content' => $msg['content'],
                    'is_edited' => (bool)$msg['is_edited'],
                    'is_pinned' => (bool)$msg['is_pinned'],
                    'created_at' => $msg['created_at'],
                    'updated_at' => $msg['updated_at']
                ];
            }, $messages);

        } catch (PDOException $e) {
            error_log("Database error in checkNewMessages: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Aggiorna presenza utente
     *
     * @param int $tenant_id ID del tenant
     * @param int $user_id ID dell'utente
     * @param string $status Status opzionale (online, away, busy, offline)
     * @return bool Success
     */
    public function updateUserPresence(int $tenant_id, int $user_id, string $status = 'online'): bool {
        try {
            $sql = "INSERT INTO chat_presence (tenant_id, user_id, status, last_active_at, last_poll_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        last_active_at = NOW(),
                        last_poll_at = NOW()";

            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([$tenant_id, $user_id, $status]);

        } catch (PDOException $e) {
            error_log("Error updating presence: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ottieni informazioni presenza per i canali specificati
     *
     * @param int $tenant_id ID del tenant
     * @param array $channels Array di channel_id
     * @return array Informazioni presenza
     */
    private function getPresenceInfo(int $tenant_id, array $channels): array {
        $presence = [
            'online_users' => [],
            'typing_users' => []
        ];

        if (empty($channels)) {
            return $presence;
        }

        try {
            // Ottieni utenti online nei canali specificati
            $placeholders = str_repeat('?,', count($channels) - 1) . '?';

            // Query per utenti online
            $sql = "SELECT DISTINCT
                        p.user_id,
                        p.status,
                        p.status_message,
                        p.current_channel_id,
                        u.nome AS user_name,
                        u.avatar_url AS user_avatar
                    FROM chat_presence p
                    INNER JOIN users u ON p.user_id = u.id
                    INNER JOIN channel_members cm ON cm.user_id = p.user_id
                    WHERE p.tenant_id = ?
                        AND cm.channel_id IN ($placeholders)
                        AND p.status != 'offline'
                        AND p.last_poll_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";

            $params = [$tenant_id];
            $params = array_merge($params, $channels);
            $params[] = self::OFFLINE_THRESHOLD;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $presence['online_users'][] = [
                    'user_id' => (int)$row['user_id'],
                    'name' => $row['user_name'],
                    'avatar' => $row['user_avatar'],
                    'status' => $row['status'],
                    'status_message' => $row['status_message'],
                    'current_channel_id' => $row['current_channel_id'] ? (int)$row['current_channel_id'] : null
                ];
            }

            // Ottieni utenti che stanno digitando
            foreach ($channels as $channel_id) {
                $typingUsers = $this->getTypingUsers($tenant_id, $channel_id);
                if (!empty($typingUsers)) {
                    $presence['typing_users'][$channel_id] = $typingUsers;
                }
            }

        } catch (PDOException $e) {
            error_log("Error getting presence info: " . $e->getMessage());
        }

        return $presence;
    }

    /**
     * Ottieni utenti che stanno digitando in un canale
     *
     * @param int $tenant_id ID del tenant
     * @param int $channel_id ID del canale
     * @return array Array di utenti che stanno digitando
     */
    public function getTypingUsers(int $tenant_id, int $channel_id): array {
        try {
            $sql = "SELECT
                        t.user_id,
                        u.nome AS user_name
                    FROM chat_typing t
                    INNER JOIN users u ON t.user_id = u.id
                    WHERE t.tenant_id = ?
                        AND t.channel_id = ?
                        AND t.expires_at > NOW()";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenant_id, $channel_id]);

            $users = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $users[] = [
                    'user_id' => (int)$row['user_id'],
                    'name' => $row['user_name']
                ];
            }

            return $users;

        } catch (PDOException $e) {
            error_log("Error getting typing users: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Imposta stato di digitazione per un utente
     *
     * @param int $tenant_id ID del tenant
     * @param int $user_id ID dell'utente
     * @param int $channel_id ID del canale
     * @param bool $is_typing True se sta digitando, false altrimenti
     * @return bool Success
     */
    public function setUserTyping(int $tenant_id, int $user_id, int $channel_id, bool $is_typing): bool {
        try {
            if ($is_typing) {
                // Inserisci o aggiorna indicatore di digitazione
                $expiresAt = date('Y-m-d H:i:s', time() + self::TYPING_EXPIRE_TIME);

                $sql = "INSERT INTO chat_typing (tenant_id, channel_id, user_id, expires_at)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)";

                $stmt = $this->pdo->prepare($sql);
                return $stmt->execute([$tenant_id, $channel_id, $user_id, $expiresAt]);

            } else {
                // Rimuovi indicatore di digitazione
                $sql = "DELETE FROM chat_typing
                        WHERE tenant_id = ? AND channel_id = ? AND user_id = ?";

                $stmt = $this->pdo->prepare($sql);
                return $stmt->execute([$tenant_id, $channel_id, $user_id]);
            }

        } catch (PDOException $e) {
            error_log("Error setting typing status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Valida accesso dell'utente ai canali specificati
     *
     * @param int $tenant_id ID del tenant
     * @param int $user_id ID dell'utente
     * @param array $channels Array di channel_id
     * @return bool True se l'utente ha accesso a tutti i canali
     */
    private function validateChannelAccess(int $tenant_id, int $user_id, array $channels): bool {
        if (empty($channels)) {
            return true;
        }

        try {
            $placeholders = str_repeat('?,', count($channels) - 1) . '?';

            $sql = "SELECT COUNT(DISTINCT channel_id) as accessible_count
                    FROM channel_members
                    WHERE tenant_id = ?
                        AND user_id = ?
                        AND channel_id IN ($placeholders)";

            $params = [$tenant_id, $user_id];
            $params = array_merge($params, $channels);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // L'utente deve avere accesso a tutti i canali richiesti
            return (int)$result['accessible_count'] === count($channels);

        } catch (PDOException $e) {
            error_log("Error validating channel access: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Pulizia dati obsoleti (presence, typing indicators)
     *
     * @param int $tenant_id ID del tenant
     * @return void
     */
    private function cleanupStaleData(int $tenant_id): void {
        try {
            // Rimuovi indicatori di digitazione scaduti
            $sql = "DELETE FROM chat_typing
                    WHERE tenant_id = ? AND expires_at < NOW()";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenant_id]);

            // Aggiorna utenti offline (nessun polling per più di OFFLINE_THRESHOLD secondi)
            $sql = "UPDATE chat_presence
                    SET status = 'offline'
                    WHERE tenant_id = ?
                        AND status != 'offline'
                        AND last_poll_at < DATE_SUB(NOW(), INTERVAL ? SECOND)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenant_id, self::OFFLINE_THRESHOLD]);

        } catch (PDOException $e) {
            error_log("Error cleaning up stale data: " . $e->getMessage());
        }
    }

    /**
     * Ottieni statistiche di utilizzo per monitoring
     *
     * @param int $tenant_id ID del tenant
     * @return array Statistiche di utilizzo
     */
    public function getPollingStats(int $tenant_id): array {
        try {
            $stats = [];

            // Conta utenti online
            $sql = "SELECT COUNT(*) as online_count
                    FROM chat_presence
                    WHERE tenant_id = ?
                        AND status != 'offline'
                        AND last_poll_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenant_id, self::OFFLINE_THRESHOLD]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['online_users'] = (int)$result['online_count'];

            // Conta utenti che stanno digitando
            $sql = "SELECT COUNT(DISTINCT user_id) as typing_count
                    FROM chat_typing
                    WHERE tenant_id = ?
                        AND expires_at > NOW()";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenant_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['typing_users'] = (int)$result['typing_count'];

            // Conta messaggi nelle ultime 24 ore
            $sql = "SELECT COUNT(*) as message_count
                    FROM chat_messages
                    WHERE tenant_id = ?
                        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenant_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['messages_24h'] = (int)$result['message_count'];

            return $stats;

        } catch (PDOException $e) {
            error_log("Error getting polling stats: " . $e->getMessage());
            return [
                'online_users' => 0,
                'typing_users' => 0,
                'messages_24h' => 0
            ];
        }
    }

    /**
     * Metodo per test di connessione e performance
     *
     * @return array Informazioni di test
     */
    public function testConnection(): array {
        $startTime = microtime(true);

        try {
            // Test query semplice
            $stmt = $this->pdo->query("SELECT 1");
            $dbConnected = $stmt !== false;

            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            return [
                'success' => true,
                'database_connected' => $dbConnected,
                'response_time_ms' => $responseTime,
                'max_timeout' => self::MAX_TIMEOUT,
                'poll_interval_ms' => self::POLL_INTERVAL / 1000,
                'php_version' => PHP_VERSION,
                'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

/**
 * Classe helper per rate limiting
 */
class RateLimiter {

    /** @var PDO Connessione al database */
    private PDO $pdo;

    /** @var int Numero massimo di richieste per finestra temporale */
    private const MAX_REQUESTS = 60;

    /** @var int Finestra temporale in secondi */
    private const TIME_WINDOW = 60;

    /**
     * Costruttore
     */
    public function __construct() {
        $db = Database::getInstance();
        $this->pdo = $db->getConnection();
    }

    /**
     * Controlla se l'utente ha superato il rate limit
     *
     * @param int $tenant_id ID del tenant
     * @param int $user_id ID dell'utente
     * @param string $action Tipo di azione
     * @return bool True se entro i limiti, false se superato
     */
    public function checkLimit(int $tenant_id, int $user_id, string $action = 'polling'): bool {
        try {
            // Pulizia vecchie entries
            $this->cleanup();

            // Conta richieste recenti
            $sql = "SELECT COUNT(*) as request_count
                    FROM rate_limits
                    WHERE tenant_id = ?
                        AND user_id = ?
                        AND action = ?
                        AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenant_id, $user_id, $action, self::TIME_WINDOW]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ((int)$result['request_count'] >= self::MAX_REQUESTS) {
                return false;
            }

            // Registra nuova richiesta
            $sql = "INSERT INTO rate_limits (tenant_id, user_id, action, created_at)
                    VALUES (?, ?, ?, NOW())";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenant_id, $user_id, $action]);

            return true;

        } catch (PDOException $e) {
            error_log("Rate limiter error: " . $e->getMessage());
            // In caso di errore, permetti l'accesso per non bloccare il servizio
            return true;
        }
    }

    /**
     * Pulizia entries vecchie
     */
    private function cleanup(): void {
        try {
            $sql = "DELETE FROM rate_limits
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([self::TIME_WINDOW * 2]);

        } catch (PDOException $e) {
            error_log("Rate limiter cleanup error: " . $e->getMessage());
        }
    }
}