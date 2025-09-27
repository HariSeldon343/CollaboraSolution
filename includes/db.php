<?php
/**
 * Classe Database Singleton per CollaboraNexio
 *
 * Gestisce tutte le connessioni e operazioni del database utilizzando PDO
 * con pattern singleton per garantire una sola istanza di connessione
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Carica configurazione
require_once dirname(__DIR__) . '/config.php';

/**
 * Classe Database con pattern Singleton
 *
 * Fornisce un'interfaccia unificata per tutte le operazioni database
 * garantendo sicurezza contro SQL injection attraverso prepared statements
 */
class Database {

    /**
     * Istanza singleton della classe
     * @var ?Database
     */
    private static ?Database $instance = null;

    /**
     * Connessione PDO al database
     * @var ?PDO
     */
    private ?PDO $connection = null;

    /**
     * Flag per indicare se siamo in una transazione
     * @var bool
     */
    private bool $inTransaction = false;

    /**
     * Percorso del file di log
     * @var string
     */
    private string $logFile;

    /**
     * Costruttore privato per implementare il pattern Singleton
     *
     * Inizializza la connessione al database con le impostazioni
     * di sicurezza e performance ottimali
     */
    private function __construct() {
        try {
            // Crea directory logs se non esiste
            $this->ensureLogDirectoryExists();

            // Prima prova a connettersi senza specificare il database
            $dsnWithoutDb = sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                DB_HOST,
                DB_PORT
            );

            // Opzioni PDO per sicurezza e performance
            $options = [
                // Modalità errore: lancia eccezioni
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                // Fetch mode di default: array associativo
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Disabilita emulazione prepared statements per maggiore sicurezza
                PDO::ATTR_EMULATE_PREPARES => false,

                // Connessione persistente se configurata
                PDO::ATTR_PERSISTENT => DB_PERSISTENT,

                // Timeout della connessione
                PDO::ATTR_TIMEOUT => 5,

                // Converte NULL e stringe vuote correttamente
                PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,

                // Case dei nomi delle colonne
                PDO::ATTR_CASE => PDO::CASE_NATURAL,

                // Abilita buffering query per performance
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ];

            try {
                // Tenta di connettersi al database specificato
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    DB_HOST,
                    DB_PORT,
                    DB_NAME
                );

                $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);

            } catch (PDOException $e) {
                // Se il database non esiste, prova a crearlo
                if (strpos($e->getMessage(), '1049') !== false || strpos($e->getMessage(), 'Unknown database') !== false) {
                    $this->log('WARNING', 'Database non esistente, tentativo di creazione automatica...');

                    // Connetti senza database
                    $tempConn = new PDO($dsnWithoutDb, DB_USER, DB_PASS, $options);

                    // Crea il database
                    $dbName = DB_NAME;
                    $tempConn->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                    // Chiudi connessione temporanea
                    $tempConn = null;

                    // Riprova con il database creato
                    $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);

                    $this->log('SUCCESS', 'Database creato e connessione stabilita');
                } else {
                    throw $e;
                }
            }

            // Imposta encoding UTF-8 a livello di connessione
            $this->connection->exec("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

            // Imposta il fuso orario della sessione MySQL
            $timezone = date_default_timezone_get();
            $this->connection->exec("SET time_zone = '+00:00'");

            // Log connessione riuscita se in debug mode
            if (DEBUG_MODE) {
                $this->log('INFO', 'Connessione al database stabilita con successo');
            }

        } catch (PDOException $e) {
            // Log errore di connessione
            $this->log('CRITICAL', 'Errore connessione database: ' . $e->getMessage());

            // Suggerimenti per risolvere il problema
            $errorMessage = 'Errore di connessione al database.';

            if (strpos($e->getMessage(), '2002') !== false) {
                $errorMessage .= ' MySQL server non raggiungibile.';
            } elseif (strpos($e->getMessage(), '1045') !== false) {
                $errorMessage .= ' Credenziali non valide.';
            } elseif (strpos($e->getMessage(), '1044') !== false) {
                $errorMessage .= ' Permessi insufficienti.';
            }

            // In produzione, non esporre dettagli dell'errore
            if (DEBUG_MODE) {
                throw new Exception($errorMessage . ' Dettaglio: ' . $e->getMessage());
            } else {
                throw new Exception($errorMessage . ' Contattare l\'amministratore.');
            }
        }
    }

    /**
     * Previene la clonazione dell'istanza (pattern Singleton)
     */
    private function __clone() {
        throw new Exception('Clonazione dell\'istanza Database non permessa');
    }

    /**
     * Previene la deserializzazione dell'istanza (pattern Singleton)
     */
    public function __wakeup() {
        throw new Exception('Deserializzazione dell\'istanza Database non permessa');
    }

    /**
     * Ottiene l'istanza singleton della classe Database
     *
     * @return Database Istanza singleton
     */
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ottiene la connessione PDO
     *
     * @return PDO Oggetto connessione PDO
     */
    public function getConnection(): PDO {
        if ($this->connection === null) {
            throw new Exception('Connessione al database non disponibile');
        }
        return $this->connection;
    }

    /**
     * Esegue una query con prepared statements
     *
     * @param string $sql Query SQL da eseguire
     * @param array $params Parametri per la query (default: array vuoto)
     * @return PDOStatement Statement eseguito
     * @throws Exception In caso di errore nell'esecuzione
     */
    public function query(string $sql, array $params = []): PDOStatement {
        try {
            // Prepara la query
            $stmt = $this->connection->prepare($sql);

            // Esegue con i parametri forniti
            $stmt->execute($params);

            // Log query in debug mode
            if (DEBUG_MODE) {
                $this->log('DEBUG', 'Query eseguita: ' . $sql);
            }

            return $stmt;

        } catch (PDOException $e) {
            // Log errore
            $this->log('ERROR', 'Errore query: ' . $e->getMessage() . ' - SQL: ' . $sql);

            // Rilancia eccezione con messaggio generico in produzione
            if (DEBUG_MODE) {
                throw new Exception('Errore database: ' . $e->getMessage());
            } else {
                throw new Exception('Errore durante l\'operazione sul database');
            }
        }
    }

    /**
     * Recupera tutti i record da una query
     *
     * @param string $sql Query SQL
     * @param array $params Parametri per la query
     * @return array Array di record
     */
    public function fetchAll(string $sql, array $params = []): array {
        try {
            $stmt = $this->query($sql, $params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            $this->log('ERROR', 'Errore fetchAll: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Recupera un singolo record da una query
     *
     * @param string $sql Query SQL
     * @param array $params Parametri per la query
     * @return array|false Record trovato o false se non trovato
     */
    public function fetchOne(string $sql, array $params = []): array|false {
        try {
            $stmt = $this->query($sql, $params);
            return $stmt->fetch();
        } catch (Exception $e) {
            $this->log('ERROR', 'Errore fetchOne: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Inserisce un record in una tabella
     *
     * @param string $table Nome della tabella
     * @param array $data Array associativo campo => valore
     * @return int ID del record inserito
     * @throws Exception In caso di errore nell'inserimento
     */
    public function insert(string $table, array $data): int {
        try {
            // Valida nome tabella
            $this->validateTableName($table);

            // Prepara i campi e i placeholders
            $fields = array_keys($data);
            $placeholders = array_map(fn($field) => ':' . $field, $fields);

            // Costruisce la query INSERT
            $sql = sprintf(
                "INSERT INTO `%s` (`%s`) VALUES (%s)",
                $table,
                implode('`, `', $fields),
                implode(', ', $placeholders)
            );

            // Prepara i parametri
            $params = [];
            foreach ($data as $field => $value) {
                $params[':' . $field] = $value;
            }

            // Esegue la query
            $this->query($sql, $params);

            // Ritorna l'ID inserito
            return $this->lastInsertId();

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore insert in ' . $table . ': ' . $e->getMessage());
            throw new Exception('Errore durante l\'inserimento del record');
        }
    }

    /**
     * Aggiorna record in una tabella
     *
     * @param string $table Nome della tabella
     * @param array $data Array associativo campo => valore da aggiornare
     * @param array $where Array associativo per la clausola WHERE
     * @return int Numero di righe modificate
     * @throws Exception In caso di errore nell'aggiornamento
     */
    public function update(string $table, array $data, array $where): int {
        try {
            // Valida nome tabella
            $this->validateTableName($table);

            // Verifica che ci siano dati da aggiornare
            if (empty($data)) {
                throw new Exception('Nessun dato da aggiornare');
            }

            // Verifica che ci sia una condizione WHERE
            if (empty($where)) {
                throw new Exception('Clausola WHERE richiesta per update');
            }

            // Prepara i SET statements
            $setSql = [];
            $params = [];
            foreach ($data as $field => $value) {
                $setSql[] = "`$field` = :set_$field";
                $params[':set_' . $field] = $value;
            }

            // Prepara le condizioni WHERE
            $whereSql = [];
            foreach ($where as $field => $value) {
                if ($value === null) {
                    $whereSql[] = "`$field` IS NULL";
                } else {
                    $whereSql[] = "`$field` = :where_$field";
                    $params[':where_' . $field] = $value;
                }
            }

            // Costruisce la query UPDATE
            $sql = sprintf(
                "UPDATE `%s` SET %s WHERE %s",
                $table,
                implode(', ', $setSql),
                implode(' AND ', $whereSql)
            );

            // Esegue la query
            $stmt = $this->query($sql, $params);

            // Ritorna il numero di righe modificate
            return $stmt->rowCount();

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore update in ' . $table . ': ' . $e->getMessage());
            throw new Exception('Errore durante l\'aggiornamento del record');
        }
    }

    /**
     * Elimina record da una tabella
     *
     * @param string $table Nome della tabella
     * @param array $where Array associativo per la clausola WHERE
     * @return int Numero di righe eliminate
     * @throws Exception In caso di errore nell'eliminazione
     */
    public function delete(string $table, array $where): int {
        try {
            // Valida nome tabella
            $this->validateTableName($table);

            // Verifica che ci sia una condizione WHERE per sicurezza
            if (empty($where)) {
                throw new Exception('Clausola WHERE richiesta per delete (sicurezza)');
            }

            // Prepara le condizioni WHERE
            $whereSql = [];
            $params = [];
            foreach ($where as $field => $value) {
                if ($value === null) {
                    $whereSql[] = "`$field` IS NULL";
                } else {
                    $whereSql[] = "`$field` = :$field";
                    $params[':' . $field] = $value;
                }
            }

            // Costruisce la query DELETE
            $sql = sprintf(
                "DELETE FROM `%s` WHERE %s",
                $table,
                implode(' AND ', $whereSql)
            );

            // Esegue la query
            $stmt = $this->query($sql, $params);

            // Ritorna il numero di righe eliminate
            return $stmt->rowCount();

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore delete in ' . $table . ': ' . $e->getMessage());
            throw new Exception('Errore durante l\'eliminazione del record');
        }
    }

    /**
     * Ottiene l'ID dell'ultimo record inserito
     *
     * @return int ID dell'ultimo record inserito
     */
    public function lastInsertId(): int {
        return (int) $this->connection->lastInsertId();
    }

    /**
     * Inizia una transazione
     *
     * @return bool True se la transazione è iniziata con successo
     * @throws Exception Se già in una transazione
     */
    public function beginTransaction(): bool {
        try {
            if ($this->inTransaction) {
                throw new Exception('Transazione già in corso');
            }

            $result = $this->connection->beginTransaction();
            if ($result) {
                $this->inTransaction = true;
                $this->log('DEBUG', 'Transazione iniziata');
            }

            return $result;

        } catch (PDOException $e) {
            $this->log('ERROR', 'Errore inizio transazione: ' . $e->getMessage());
            throw new Exception('Impossibile iniziare la transazione');
        }
    }

    /**
     * Conferma una transazione
     *
     * @return bool True se il commit è avvenuto con successo
     * @throws Exception Se non in una transazione
     */
    public function commit(): bool {
        try {
            if (!$this->inTransaction) {
                throw new Exception('Nessuna transazione attiva');
            }

            $result = $this->connection->commit();
            if ($result) {
                $this->inTransaction = false;
                $this->log('DEBUG', 'Transazione confermata');
            }

            return $result;

        } catch (PDOException $e) {
            $this->log('ERROR', 'Errore commit transazione: ' . $e->getMessage());
            throw new Exception('Impossibile confermare la transazione');
        }
    }

    /**
     * Annulla una transazione
     *
     * @return bool True se il rollback è avvenuto con successo
     * @throws Exception Se non in una transazione
     */
    public function rollback(): bool {
        try {
            if (!$this->inTransaction) {
                throw new Exception('Nessuna transazione attiva');
            }

            $result = $this->connection->rollBack();
            if ($result) {
                $this->inTransaction = false;
                $this->log('DEBUG', 'Transazione annullata');
            }

            return $result;

        } catch (PDOException $e) {
            $this->log('ERROR', 'Errore rollback transazione: ' . $e->getMessage());
            throw new Exception('Impossibile annullare la transazione');
        }
    }

    /**
     * Verifica se siamo in una transazione
     *
     * @return bool True se in transazione
     */
    public function inTransaction(): bool {
        return $this->inTransaction;
    }

    /**
     * Escapa una stringa per uso sicuro nelle query
     * NOTA: Preferire sempre prepared statements quando possibile
     *
     * @param string $value Valore da escapare
     * @return string Valore escapato
     */
    public function quote(string $value): string {
        return $this->connection->quote($value);
    }

    /**
     * Valida il nome di una tabella per prevenire SQL injection
     *
     * @param string $table Nome della tabella
     * @throws Exception Se il nome non è valido
     */
    private function validateTableName(string $table): void {
        // Permette solo lettere, numeri e underscore
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new Exception('Nome tabella non valido: ' . $table);
        }

        // Verifica lunghezza massima (MySQL limite 64 caratteri)
        if (strlen($table) > 64) {
            throw new Exception('Nome tabella troppo lungo: ' . $table);
        }
    }

    /**
     * Garantisce che la directory dei log esista
     */
    private function ensureLogDirectoryExists(): void {
        $logDir = dirname(__DIR__) . '/logs';

        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                error_log('Impossibile creare directory logs: ' . $logDir);
            }
        }

        $this->logFile = $logDir . '/database_errors.log';
    }

    /**
     * Registra un messaggio nel log
     *
     * @param string $level Livello del log (DEBUG, INFO, WARNING, ERROR, CRITICAL)
     * @param string $message Messaggio da loggare
     */
    private function log(string $level, string $message): void {
        // Controlla il livello di log configurato
        $levels = ['DEBUG' => 1, 'INFO' => 2, 'WARNING' => 3, 'ERROR' => 4, 'CRITICAL' => 5];
        $configLevel = $levels[LOG_LEVEL] ?? 1;
        $messageLevel = $levels[$level] ?? 1;

        if ($messageLevel < $configLevel) {
            return;
        }

        // Formatta il messaggio
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = sprintf(
            "[%s] [%s] %s" . PHP_EOL,
            $timestamp,
            $level,
            $message
        );

        // Scrivi nel file di log
        if (isset($this->logFile)) {
            error_log($logMessage, 3, $this->logFile);
        } else {
            // Fallback al log di sistema se il file non è disponibile
            error_log($logMessage);
        }
    }

    /**
     * Conta i record in una tabella con condizioni opzionali
     *
     * @param string $table Nome della tabella
     * @param array $where Condizioni WHERE opzionali
     * @return int Numero di record
     */
    public function count(string $table, array $where = []): int {
        try {
            $this->validateTableName($table);

            $sql = "SELECT COUNT(*) as total FROM `$table`";
            $params = [];

            if (!empty($where)) {
                $whereSql = [];
                foreach ($where as $field => $value) {
                    if ($value === null) {
                        $whereSql[] = "`$field` IS NULL";
                    } else {
                        $whereSql[] = "`$field` = :$field";
                        $params[':' . $field] = $value;
                    }
                }
                $sql .= ' WHERE ' . implode(' AND ', $whereSql);
            }

            $result = $this->fetchOne($sql, $params);
            return (int) ($result['total'] ?? 0);

        } catch (Exception $e) {
            $this->log('ERROR', 'Errore count in ' . $table . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Verifica se un record esiste
     *
     * @param string $table Nome della tabella
     * @param array $where Condizioni WHERE
     * @return bool True se il record esiste
     */
    public function exists(string $table, array $where): bool {
        return $this->count($table, $where) > 0;
    }

    /**
     * Esegue query multiple in batch
     *
     * @param array $queries Array di query da eseguire
     * @param bool $useTransaction Se true, usa una transazione
     * @return bool True se tutte le query sono state eseguite con successo
     */
    public function batchQuery(array $queries, bool $useTransaction = true): bool {
        try {
            if ($useTransaction && !$this->inTransaction) {
                $this->beginTransaction();
            }

            foreach ($queries as $query) {
                if (is_string($query)) {
                    $this->query($query);
                } elseif (is_array($query) && isset($query['sql'])) {
                    $params = $query['params'] ?? [];
                    $this->query($query['sql'], $params);
                }
            }

            if ($useTransaction && $this->inTransaction) {
                $this->commit();
            }

            return true;

        } catch (Exception $e) {
            if ($useTransaction && $this->inTransaction) {
                $this->rollback();
            }
            $this->log('ERROR', 'Errore batch query: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ottiene informazioni sulla versione del database
     *
     * @return array Informazioni versione
     */
    public function getVersion(): array {
        try {
            $version = $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);
            $driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);

            return [
                'version' => $version,
                'driver' => $driver,
                'info' => $this->connection->getAttribute(PDO::ATTR_SERVER_INFO)
            ];
        } catch (Exception $e) {
            $this->log('ERROR', 'Errore recupero versione: ' . $e->getMessage());
            return ['version' => 'unknown', 'driver' => 'unknown'];
        }
    }

    /**
     * Distruttore - chiude la connessione se ancora aperta
     */
    public function __destruct() {
        // Chiude eventuali transazioni aperte
        if ($this->inTransaction) {
            try {
                $this->rollback();
            } catch (Exception $e) {
                // Ignora errori nel distruttore
            }
        }

        // Chiude la connessione
        $this->connection = null;
    }
}

// Funzione helper globale per accesso rapido al database
if (!function_exists('db')) {
    /**
     * Helper function per ottenere l'istanza del database
     *
     * @return Database Istanza singleton del database
     */
    function db(): Database {
        return Database::getInstance();
    }
}