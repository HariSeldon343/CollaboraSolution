<?php
/**
 * Query Helper - Gestione query con filtro multi-tenant
 * Fornisce funzioni di utilità per applicare filtri azienda alle query
 *
 * @author CollaboraNexio Backend Team
 * @version 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/company_filter.php';

class QueryHelper {
    private PDO $pdo;
    private ?CompanyFilter $companyFilter;

    /**
     * Costruttore
     *
     * @param array|null $currentUser Utente corrente per inizializzare il filtro
     */
    public function __construct(?array $currentUser = null) {
        $this->pdo = Database::getInstance()->getConnection();
        $this->companyFilter = $currentUser ? new CompanyFilter($currentUser) : null;
    }

    /**
     * Esegue una query SELECT applicando automaticamente il filtro azienda
     *
     * @param string $query Query SQL da eseguire
     * @param array $params Parametri per la query
     * @param string $tenantColumn Nome della colonna tenant_id (default: 'tenant_id')
     * @return array Risultati della query
     */
    public function selectWithFilter(string $query, array $params = [], string $tenantColumn = 'tenant_id'): array {
        try {
            // Applica il filtro azienda se disponibile
            if ($this->companyFilter) {
                $filtered = $this->companyFilter->applyFilterToQuery($query, $tenantColumn, $params);
                $query = $filtered['query'];
                $params = $filtered['params'];
            }

            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log('Errore selectWithFilter: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Esegue una query SELECT per ottenere un singolo record
     *
     * @param string $query Query SQL
     * @param array $params Parametri
     * @param string $tenantColumn Nome colonna tenant_id
     * @return array|null Record trovato o null
     */
    public function selectOneWithFilter(string $query, array $params = [], string $tenantColumn = 'tenant_id'): ?array {
        $results = $this->selectWithFilter($query, $params, $tenantColumn);
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Conta i record applicando il filtro azienda
     *
     * @param string $table Nome della tabella
     * @param string $where Clausola WHERE aggiuntiva (senza WHERE keyword)
     * @param array $params Parametri per la clausola WHERE
     * @param string $tenantColumn Nome colonna tenant_id
     * @return int Numero di record
     */
    public function countWithFilter(string $table, string $where = '', array $params = [], string $tenantColumn = 'tenant_id'): int {
        try {
            $query = "SELECT COUNT(*) as total FROM {$table}";

            if ($where) {
                $query .= " WHERE {$where}";
            }

            // Applica il filtro azienda
            if ($this->companyFilter) {
                $filtered = $this->companyFilter->applyFilterToQuery($query, $tenantColumn, $params);
                $query = $filtered['query'];
                $params = $filtered['params'];
            }

            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (int)($result['total'] ?? 0);

        } catch (Exception $e) {
            error_log('Errore countWithFilter: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Inserisce un record aggiungendo automaticamente il tenant_id se necessario
     *
     * @param string $table Nome della tabella
     * @param array $data Dati da inserire
     * @param bool $addTenantId Se aggiungere automaticamente il tenant_id (default: true)
     * @return int|null ID del record inserito o null in caso di errore
     */
    public function insertWithTenant(string $table, array $data, bool $addTenantId = true): ?int {
        try {
            // Aggiungi tenant_id se necessario e se c'è un filtro attivo
            if ($addTenantId && $this->companyFilter) {
                $filterId = $this->companyFilter->getActiveFilterId();
                if ($filterId) {
                    $data['tenant_id'] = $filterId;
                } elseif (isset($_SESSION['tenant_id'])) {
                    // Fallback al tenant_id della sessione
                    $data['tenant_id'] = $_SESSION['tenant_id'];
                }
            }

            $columns = array_keys($data);
            $placeholders = array_map(fn($col) => ":{$col}", $columns);

            $query = sprintf(
                "INSERT INTO %s (%s) VALUES (%s)",
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $stmt = $this->pdo->prepare($query);

            foreach ($data as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            $stmt->execute();
            return (int)$this->pdo->lastInsertId();

        } catch (Exception $e) {
            error_log('Errore insertWithTenant: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Aggiorna record applicando il filtro azienda
     *
     * @param string $table Nome della tabella
     * @param array $data Dati da aggiornare
     * @param string $where Clausola WHERE
     * @param array $whereParams Parametri per WHERE
     * @param string $tenantColumn Nome colonna tenant_id
     * @return int Numero di righe aggiornate
     */
    public function updateWithFilter(string $table, array $data, string $where, array $whereParams = [], string $tenantColumn = 'tenant_id'): int {
        try {
            $setParts = [];
            foreach ($data as $key => $value) {
                $setParts[] = "{$key} = :set_{$key}";
            }

            $query = sprintf(
                "UPDATE %s SET %s WHERE %s",
                $table,
                implode(', ', $setParts),
                $where
            );

            // Applica il filtro azienda
            if ($this->companyFilter) {
                $filtered = $this->companyFilter->applyFilterToQuery($query, $tenantColumn, $whereParams);
                $query = $filtered['query'];
                $whereParams = $filtered['params'];
            }

            $stmt = $this->pdo->prepare($query);

            // Bind dei valori SET
            foreach ($data as $key => $value) {
                $stmt->bindValue(":set_{$key}", $value);
            }

            // Bind dei parametri WHERE
            foreach ($whereParams as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }

            $stmt->execute();
            return $stmt->rowCount();

        } catch (Exception $e) {
            error_log('Errore updateWithFilter: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Elimina record applicando il filtro azienda
     *
     * @param string $table Nome della tabella
     * @param string $where Clausola WHERE
     * @param array $params Parametri
     * @param string $tenantColumn Nome colonna tenant_id
     * @return int Numero di righe eliminate
     */
    public function deleteWithFilter(string $table, string $where, array $params = [], string $tenantColumn = 'tenant_id'): int {
        try {
            $query = "DELETE FROM {$table} WHERE {$where}";

            // Applica il filtro azienda
            if ($this->companyFilter) {
                $filtered = $this->companyFilter->applyFilterToQuery($query, $tenantColumn, $params);
                $query = $filtered['query'];
                $params = $filtered['params'];
            }

            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->rowCount();

        } catch (Exception $e) {
            error_log('Errore deleteWithFilter: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Verifica se un record esiste applicando il filtro azienda
     *
     * @param string $table Nome della tabella
     * @param string $where Clausola WHERE
     * @param array $params Parametri
     * @param string $tenantColumn Nome colonna tenant_id
     * @return bool True se esiste
     */
    public function existsWithFilter(string $table, string $where, array $params = [], string $tenantColumn = 'tenant_id'): bool {
        return $this->countWithFilter($table, $where, $params, $tenantColumn) > 0;
    }

    /**
     * Ottiene il filtro azienda corrente
     *
     * @return CompanyFilter|null Filtro azienda o null
     */
    public function getCompanyFilter(): ?CompanyFilter {
        return $this->companyFilter;
    }

    /**
     * Imposta un nuovo filtro azienda
     *
     * @param CompanyFilter $filter Nuovo filtro
     */
    public function setCompanyFilter(CompanyFilter $filter): void {
        $this->companyFilter = $filter;
    }
}

/**
 * Esempi di utilizzo del QueryHelper
 */

/*
// Esempio 1: Query semplice con filtro automatico
$queryHelper = new QueryHelper($currentUser);
$users = $queryHelper->selectWithFilter("SELECT * FROM users ORDER BY created_at DESC");

// Esempio 2: Query con parametri
$projects = $queryHelper->selectWithFilter(
    "SELECT * FROM projects WHERE status = :status",
    ['status' => 'active']
);

// Esempio 3: Conteggio con filtro
$totalTasks = $queryHelper->countWithFilter('tasks', 'completed = :completed', ['completed' => 0]);

// Esempio 4: Inserimento con tenant_id automatico
$newProjectId = $queryHelper->insertWithTenant('projects', [
    'name' => 'Nuovo Progetto',
    'description' => 'Descrizione del progetto',
    'status' => 'planning',
    'created_by' => $currentUser['id']
]);

// Esempio 5: Aggiornamento con filtro
$updated = $queryHelper->updateWithFilter(
    'tasks',
    ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')],
    'id = :id',
    ['id' => 123]
);

// Esempio 6: Query con colonna tenant personalizzata
$documents = $queryHelper->selectWithFilter(
    "SELECT * FROM documents WHERE type = :type",
    ['type' => 'contract'],
    'company_id'  // Usa company_id invece di tenant_id
);
*/