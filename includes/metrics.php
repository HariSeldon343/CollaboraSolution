<?php
/**
 * Sistema di Raccolta e Aggregazione Metriche per CollaboraNexio
 *
 * Fornisce funzionalità complete per la raccolta, aggregazione e analisi
 * di metriche di sistema con supporto multi-tenant e caching intelligente
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Classe principale per la gestione delle metriche
 *
 * Implementa pattern Singleton per gestione efficiente delle risorse
 * con supporto per aggregazioni, caching e analisi statistica avanzata
 */
class MetricsCollector {

    /**
     * Istanza singleton della classe
     * @var ?MetricsCollector
     */
    private static ?MetricsCollector $instance = null;

    /**
     * Connessione al database
     * @var Database
     */
    private Database $db;

    /**
     * Cache in memoria per metriche frequenti
     * @var array
     */
    private array $memoryCache = [];

    /**
     * TTL della cache in secondi
     * @var int
     */
    private int $cacheTTL = 300; // 5 minuti di default

    /**
     * Connessione Redis per caching distribuito (opzionale)
     * @var ?Redis
     */
    private ?Redis $redis = null;

    /**
     * Memcached per caching alternativo (opzionale)
     * @var ?Memcached
     */
    private ?Memcached $memcached = null;

    /**
     * Buffer per batch processing
     * @var array
     */
    private array $batchBuffer = [];

    /**
     * Dimensione massima del buffer
     * @var int
     */
    private int $maxBufferSize = 1000;

    /**
     * Flag per abilitare il debug
     * @var bool
     */
    private bool $debug = false;

    /**
     * Livelli di aggregazione supportati
     * @var array
     */
    private const AGGREGATION_LEVELS = ['raw', 'minute', 'hour', 'day', 'week', 'month'];

    /**
     * Tipi di metriche supportati
     * @var array
     */
    private const METRIC_TYPES = ['counter', 'gauge', 'histogram', 'summary'];

    /**
     * Metodi di aggregazione disponibili
     * @var array
     */
    private const AGGREGATION_METHODS = ['sum', 'avg', 'min', 'max', 'count', 'p50', 'p95', 'p99', 'stddev'];

    /**
     * Costruttore privato per pattern Singleton
     */
    private function __construct() {
        $this->db = Database::getInstance();
        $this->initializeCache();
        $this->debug = DEBUG_MODE ?? false;
    }

    /**
     * Ottiene l'istanza singleton
     *
     * @return MetricsCollector
     */
    public static function getInstance(): MetricsCollector {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inizializza i sistemi di caching
     */
    private function initializeCache(): void {
        // Tenta di connettersi a Redis se disponibile
        if (extension_loaded('redis')) {
            try {
                $this->redis = new Redis();
                if (!$this->redis->connect('127.0.0.1', 6379, 2.0)) {
                    $this->redis = null;
                }
            } catch (Exception $e) {
                $this->redis = null;
                $this->logError("Redis connection failed: " . $e->getMessage());
            }
        }

        // Fallback su Memcached se Redis non disponibile
        if (!$this->redis && extension_loaded('memcached')) {
            try {
                $this->memcached = new Memcached();
                $this->memcached->addServer('127.0.0.1', 11211);
                // Test connessione
                $this->memcached->set('test', '1', 1);
                if (!$this->memcached->get('test')) {
                    $this->memcached = null;
                }
            } catch (Exception $e) {
                $this->memcached = null;
                $this->logError("Memcached connection failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Raccoglie una metrica
     *
     * @param int $tenantId ID del tenant
     * @param string $metricName Nome della metrica
     * @param float $value Valore della metrica
     * @param string $metricType Tipo di metrica
     * @param array $dimensions Dimensioni aggiuntive per filtering
     * @param array $tags Tags aggiuntivi
     * @param string|null $source Sorgente della metrica
     * @return bool Success status
     */
    public function collect(
        int $tenantId,
        string $metricName,
        float $value,
        string $metricType = 'gauge',
        array $dimensions = [],
        array $tags = [],
        ?string $source = null
    ): bool {
        try {
            // Validazione input
            if (!$this->validateMetricType($metricType)) {
                throw new InvalidArgumentException("Invalid metric type: $metricType");
            }

            if (!$this->validateMetricName($metricName)) {
                throw new InvalidArgumentException("Invalid metric name: $metricName");
            }

            // Prepara i dati per l'inserimento
            $metric = [
                'tenant_id' => $tenantId,
                'metric_name' => $metricName,
                'metric_type' => $metricType,
                'value' => $value,
                'dimensions' => !empty($dimensions) ? json_encode($dimensions) : null,
                'tags' => !empty($tags) ? json_encode($tags) : null,
                'source' => $source ?? $this->detectSource(),
                'timestamp' => $this->getCurrentTimestamp(),
                'aggregation_level' => 'raw'
            ];

            // Aggiungi al buffer per batch processing
            $this->addToBuffer($metric);

            // Flush buffer se necessario
            if (count($this->batchBuffer) >= $this->maxBufferSize) {
                $this->flushBuffer();
            }

            // Invalida cache correlata
            $this->invalidateCache($tenantId, $metricName);

            // Trigger aggregazioni in tempo reale per metriche critiche
            if ($this->isRealtimeMetric($metricName)) {
                $this->triggerRealtimeAggregation($tenantId, $metricName, $value);
            }

            return true;

        } catch (Exception $e) {
            $this->logError("Failed to collect metric: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Raccoglie metriche in batch
     *
     * @param int $tenantId ID del tenant
     * @param array $metrics Array di metriche
     * @return array Risultato dell'operazione con statistiche
     */
    public function collectBatch(int $tenantId, array $metrics): array {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($metrics as $metric) {
            $success = $this->collect(
                $tenantId,
                $metric['name'] ?? '',
                $metric['value'] ?? 0,
                $metric['type'] ?? 'gauge',
                $metric['dimensions'] ?? [],
                $metric['tags'] ?? [],
                $metric['source'] ?? null
            );

            if ($success) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = $metric['name'] ?? 'unknown';
            }
        }

        // Forza flush del buffer
        $this->flushBuffer();

        return $results;
    }

    /**
     * Aggrega metriche per un periodo specifico
     *
     * @param int $tenantId ID del tenant
     * @param string $metricName Nome della metrica
     * @param string $aggregationLevel Livello di aggregazione
     * @param DateTime $startDate Data inizio
     * @param DateTime $endDate Data fine
     * @return bool Success status
     */
    public function aggregate(
        int $tenantId,
        string $metricName,
        string $aggregationLevel,
        DateTime $startDate,
        DateTime $endDate
    ): bool {
        try {
            // Validazione livello aggregazione
            if (!in_array($aggregationLevel, self::AGGREGATION_LEVELS)) {
                throw new InvalidArgumentException("Invalid aggregation level: $aggregationLevel");
            }

            // Recupera definizione metrica
            $definition = $this->getMetricDefinition($tenantId, $metricName);
            if (!$definition) {
                throw new RuntimeException("Metric definition not found: $metricName");
            }

            // Calcola periodi di aggregazione
            $periods = $this->calculateAggregationPeriods($aggregationLevel, $startDate, $endDate);

            foreach ($periods as $period) {
                $this->aggregatePeriod(
                    $tenantId,
                    $metricName,
                    $aggregationLevel,
                    $period['start'],
                    $period['end'],
                    $definition['aggregation_method'] ?? 'avg'
                );
            }

            // Calcola trend
            $this->calculateTrends($tenantId, $metricName, $aggregationLevel);

            return true;

        } catch (Exception $e) {
            $this->logError("Aggregation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggrega metriche per un singolo periodo
     */
    private function aggregatePeriod(
        int $tenantId,
        string $metricName,
        string $aggregationLevel,
        DateTime $periodStart,
        DateTime $periodEnd,
        string $aggregationMethod
    ): void {
        $db = $this->db->getConnection();

        // Query per recuperare metriche raw
        $sql = "SELECT
                    COUNT(*) as sample_count,
                    AVG(value) as avg_value,
                    MIN(value) as min_value,
                    MAX(value) as max_value,
                    SUM(value) as sum_value,
                    STDDEV(value) as stddev_value,
                    dimensions
                FROM metrics
                WHERE tenant_id = :tenant_id
                    AND metric_name = :metric_name
                    AND timestamp >= :start_time
                    AND timestamp < :end_time
                    AND aggregation_level = 'raw'
                GROUP BY dimensions";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':metric_name' => $metricName,
            ':start_time' => $periodStart->format('Y-m-d H:i:s'),
            ':end_time' => $periodEnd->format('Y-m-d H:i:s')
        ]);

        $results = $stmt->fetchAll();

        foreach ($results as $row) {
            // Calcola percentili se necessario
            $percentiles = [];
            if (in_array($aggregationMethod, ['p50', 'p95', 'p99'])) {
                $percentiles = $this->calculatePercentiles(
                    $tenantId,
                    $metricName,
                    $periodStart,
                    $periodEnd,
                    $row['dimensions']
                );
            }

            // Determina il valore principale basato sul metodo di aggregazione
            $primaryValue = match($aggregationMethod) {
                'sum' => $row['sum_value'],
                'avg' => $row['avg_value'],
                'min' => $row['min_value'],
                'max' => $row['max_value'],
                'count' => $row['sample_count'],
                'p50' => $percentiles['p50'] ?? 0,
                'p95' => $percentiles['p95'] ?? 0,
                'p99' => $percentiles['p99'] ?? 0,
                default => $row['avg_value']
            };

            // Inserisci o aggiorna aggregazione
            $this->upsertAggregation(
                $tenantId,
                $metricName,
                $aggregationMethod,
                $aggregationLevel,
                $primaryValue,
                $row['sample_count'],
                $row['min_value'],
                $row['max_value'],
                $row['sum_value'],
                $periodStart,
                $periodEnd,
                $row['dimensions']
            );
        }
    }

    /**
     * Calcola percentili per una metrica
     */
    private function calculatePercentiles(
        int $tenantId,
        string $metricName,
        DateTime $startTime,
        DateTime $endTime,
        ?string $dimensions
    ): array {
        $db = $this->db->getConnection();

        $sql = "SELECT value
                FROM metrics
                WHERE tenant_id = :tenant_id
                    AND metric_name = :metric_name
                    AND timestamp >= :start_time
                    AND timestamp < :end_time
                    AND aggregation_level = 'raw'";

        if ($dimensions) {
            $sql .= " AND dimensions = :dimensions";
        }

        $sql .= " ORDER BY value ASC";

        $stmt = $db->prepare($sql);
        $params = [
            ':tenant_id' => $tenantId,
            ':metric_name' => $metricName,
            ':start_time' => $startTime->format('Y-m-d H:i:s'),
            ':end_time' => $endTime->format('Y-m-d H:i:s')
        ];

        if ($dimensions) {
            $params[':dimensions'] = $dimensions;
        }

        $stmt->execute($params);
        $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($values)) {
            return ['p50' => 0, 'p95' => 0, 'p99' => 0];
        }

        $count = count($values);

        return [
            'p50' => $values[intval($count * 0.50)],
            'p95' => $values[intval($count * 0.95)],
            'p99' => $values[intval($count * 0.99)]
        ];
    }

    /**
     * Inserisce o aggiorna un'aggregazione
     */
    private function upsertAggregation(
        int $tenantId,
        string $metricName,
        string $aggregationType,
        string $aggregationLevel,
        float $value,
        int $sampleCount,
        float $minValue,
        float $maxValue,
        float $sumValue,
        DateTime $periodStart,
        DateTime $periodEnd,
        ?string $dimensions
    ): void {
        $db = $this->db->getConnection();

        // Recupera valore precedente per calcolare trend
        $previousValue = $this->getPreviousAggregationValue(
            $tenantId,
            $metricName,
            $aggregationType,
            $aggregationLevel,
            $periodStart
        );

        $sql = "INSERT INTO metric_aggregations (
                    tenant_id, metric_name, aggregation_type, aggregation_level,
                    value, sample_count, min_value, max_value, sum_value,
                    period_start, period_end, dimensions, previous_value
                ) VALUES (
                    :tenant_id, :metric_name, :aggregation_type, :aggregation_level,
                    :value, :sample_count, :min_value, :max_value, :sum_value,
                    :period_start, :period_end, :dimensions, :previous_value
                ) ON DUPLICATE KEY UPDATE
                    value = VALUES(value),
                    sample_count = VALUES(sample_count),
                    min_value = VALUES(min_value),
                    max_value = VALUES(max_value),
                    sum_value = VALUES(sum_value),
                    previous_value = VALUES(previous_value),
                    updated_at = NOW()";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':metric_name' => $metricName,
            ':aggregation_type' => $aggregationType,
            ':aggregation_level' => $aggregationLevel,
            ':value' => $value,
            ':sample_count' => $sampleCount,
            ':min_value' => $minValue,
            ':max_value' => $maxValue,
            ':sum_value' => $sumValue,
            ':period_start' => $periodStart->format('Y-m-d H:i:s'),
            ':period_end' => $periodEnd->format('Y-m-d H:i:s'),
            ':dimensions' => $dimensions,
            ':previous_value' => $previousValue
        ]);
    }

    /**
     * Recupera il valore di aggregazione precedente
     */
    private function getPreviousAggregationValue(
        int $tenantId,
        string $metricName,
        string $aggregationType,
        string $aggregationLevel,
        DateTime $currentPeriodStart
    ): ?float {
        $db = $this->db->getConnection();

        $sql = "SELECT value
                FROM metric_aggregations
                WHERE tenant_id = :tenant_id
                    AND metric_name = :metric_name
                    AND aggregation_type = :aggregation_type
                    AND aggregation_level = :aggregation_level
                    AND period_start < :current_period
                ORDER BY period_start DESC
                LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':metric_name' => $metricName,
            ':aggregation_type' => $aggregationType,
            ':aggregation_level' => $aggregationLevel,
            ':current_period' => $currentPeriodStart->format('Y-m-d H:i:s')
        ]);

        $result = $stmt->fetchColumn();
        return $result !== false ? (float)$result : null;
    }

    /**
     * Calcola i trend per le metriche aggregate
     */
    private function calculateTrends(
        int $tenantId,
        string $metricName,
        string $aggregationLevel
    ): void {
        // I trend sono calcolati automaticamente tramite GENERATED columns nel database
        // Questa funzione può essere estesa per calcoli più complessi se necessario
    }

    /**
     * Recupera metriche con caching intelligente
     *
     * @param int $tenantId ID del tenant
     * @param string $metricName Nome della metrica
     * @param DateTime $startDate Data inizio
     * @param DateTime $endDate Data fine
     * @param array $filters Filtri aggiuntivi
     * @return array Dati delle metriche
     */
    public function getMetrics(
        int $tenantId,
        string $metricName,
        DateTime $startDate,
        DateTime $endDate,
        array $filters = []
    ): array {
        // Genera cache key
        $cacheKey = $this->generateCacheKey('metrics', [
            'tenant' => $tenantId,
            'metric' => $metricName,
            'start' => $startDate->format('Y-m-d H:i:s'),
            'end' => $endDate->format('Y-m-d H:i:s'),
            'filters' => md5(json_encode($filters))
        ]);

        // Controlla cache
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $db = $this->db->getConnection();

            $sql = "SELECT
                        m.*,
                        md.display_name,
                        md.unit,
                        md.format_pattern,
                        md.decimal_places
                    FROM metrics m
                    LEFT JOIN metric_definitions md ON
                        m.tenant_id = md.tenant_id AND
                        m.metric_name = md.metric_name
                    WHERE m.tenant_id = :tenant_id
                        AND m.metric_name = :metric_name
                        AND m.timestamp >= :start_date
                        AND m.timestamp <= :end_date";

            $params = [
                ':tenant_id' => $tenantId,
                ':metric_name' => $metricName,
                ':start_date' => $startDate->format('Y-m-d H:i:s'),
                ':end_date' => $endDate->format('Y-m-d H:i:s')
            ];

            // Applica filtri aggiuntivi
            if (isset($filters['aggregation_level'])) {
                $sql .= " AND m.aggregation_level = :aggregation_level";
                $params[':aggregation_level'] = $filters['aggregation_level'];
            }

            if (isset($filters['source'])) {
                $sql .= " AND m.source = :source";
                $params[':source'] = $filters['source'];
            }

            if (isset($filters['dimensions'])) {
                foreach ($filters['dimensions'] as $key => $value) {
                    $sql .= " AND JSON_EXTRACT(m.dimensions, '$.$key') = :dim_$key";
                    $params[":dim_$key"] = $value;
                }
            }

            $sql .= " ORDER BY m.timestamp DESC";

            if (isset($filters['limit'])) {
                $sql .= " LIMIT :limit";
                $params[':limit'] = (int)$filters['limit'];
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();

            // Post-processa i risultati
            $processed = $this->processMetricResults($results);

            // Salva in cache
            $this->saveToCache($cacheKey, $processed, $this->cacheTTL);

            return $processed;

        } catch (Exception $e) {
            $this->logError("Failed to get metrics: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Recupera aggregazioni con statistiche avanzate
     *
     * @param int $tenantId ID del tenant
     * @param string $metricName Nome della metrica
     * @param string $aggregationLevel Livello di aggregazione
     * @param DateTime $startDate Data inizio
     * @param DateTime $endDate Data fine
     * @param array $options Opzioni aggiuntive
     * @return array Dati aggregati con statistiche
     */
    public function getAggregations(
        int $tenantId,
        string $metricName,
        string $aggregationLevel,
        DateTime $startDate,
        DateTime $endDate,
        array $options = []
    ): array {
        // Genera cache key
        $cacheKey = $this->generateCacheKey('aggregations', [
            'tenant' => $tenantId,
            'metric' => $metricName,
            'level' => $aggregationLevel,
            'start' => $startDate->format('Y-m-d H:i:s'),
            'end' => $endDate->format('Y-m-d H:i:s'),
            'options' => md5(json_encode($options))
        ]);

        // Controlla cache
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $db = $this->db->getConnection();

            $sql = "SELECT
                        ma.*,
                        md.display_name,
                        md.unit,
                        md.warning_threshold,
                        md.critical_threshold,
                        md.threshold_direction
                    FROM metric_aggregations ma
                    LEFT JOIN metric_definitions md ON
                        ma.tenant_id = md.tenant_id AND
                        ma.metric_name = md.metric_name
                    WHERE ma.tenant_id = :tenant_id
                        AND ma.metric_name = :metric_name
                        AND ma.aggregation_level = :aggregation_level
                        AND ma.period_start >= :start_date
                        AND ma.period_end <= :end_date";

            $params = [
                ':tenant_id' => $tenantId,
                ':metric_name' => $metricName,
                ':aggregation_level' => $aggregationLevel,
                ':start_date' => $startDate->format('Y-m-d H:i:s'),
                ':end_date' => $endDate->format('Y-m-d H:i:s')
            ];

            // Filtro per tipo di aggregazione
            if (isset($options['aggregation_type'])) {
                $sql .= " AND ma.aggregation_type = :aggregation_type";
                $params[':aggregation_type'] = $options['aggregation_type'];
            }

            $sql .= " ORDER BY ma.period_start DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();

            // Calcola statistiche aggiuntive
            $statistics = $this->calculateStatistics($results);

            // Rileva anomalie se richiesto
            if ($options['detect_anomalies'] ?? false) {
                $anomalies = $this->detectAnomalies($results);
                $statistics['anomalies'] = $anomalies;
            }

            $processed = [
                'data' => $results,
                'statistics' => $statistics,
                'metadata' => [
                    'metric_name' => $metricName,
                    'aggregation_level' => $aggregationLevel,
                    'period' => [
                        'start' => $startDate->format('Y-m-d H:i:s'),
                        'end' => $endDate->format('Y-m-d H:i:s')
                    ],
                    'count' => count($results)
                ]
            ];

            // Salva in cache
            $this->saveToCache($cacheKey, $processed, $this->cacheTTL);

            return $processed;

        } catch (Exception $e) {
            $this->logError("Failed to get aggregations: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Calcola statistiche avanzate sui dati
     */
    private function calculateStatistics(array $data): array {
        if (empty($data)) {
            return [
                'mean' => 0,
                'median' => 0,
                'stddev' => 0,
                'variance' => 0,
                'min' => 0,
                'max' => 0,
                'sum' => 0,
                'count' => 0
            ];
        }

        $values = array_column($data, 'value');
        $count = count($values);
        $sum = array_sum($values);
        $mean = $sum / $count;

        // Calcola mediana
        sort($values);
        $median = $count % 2 === 0
            ? ($values[$count/2 - 1] + $values[$count/2]) / 2
            : $values[floor($count/2)];

        // Calcola deviazione standard e varianza
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        $variance /= $count;
        $stddev = sqrt($variance);

        return [
            'mean' => round($mean, 4),
            'median' => round($median, 4),
            'stddev' => round($stddev, 4),
            'variance' => round($variance, 4),
            'min' => min($values),
            'max' => max($values),
            'sum' => round($sum, 4),
            'count' => $count,
            'percentiles' => [
                'p25' => $this->getPercentile($values, 0.25),
                'p50' => $this->getPercentile($values, 0.50),
                'p75' => $this->getPercentile($values, 0.75),
                'p90' => $this->getPercentile($values, 0.90),
                'p95' => $this->getPercentile($values, 0.95),
                'p99' => $this->getPercentile($values, 0.99)
            ]
        ];
    }

    /**
     * Calcola un percentile specifico
     */
    private function getPercentile(array $values, float $percentile): float {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $index = ceil(count($values) * $percentile) - 1;
        return $values[$index] ?? 0;
    }

    /**
     * Rileva anomalie nei dati utilizzando algoritmo IQR
     */
    private function detectAnomalies(array $data): array {
        if (count($data) < 4) {
            return [];
        }

        $values = array_column($data, 'value');
        sort($values);

        // Calcola quartili
        $q1 = $this->getPercentile($values, 0.25);
        $q3 = $this->getPercentile($values, 0.75);
        $iqr = $q3 - $q1;

        // Calcola limiti per outliers
        $lowerBound = $q1 - (1.5 * $iqr);
        $upperBound = $q3 + (1.5 * $iqr);

        $anomalies = [];
        foreach ($data as $item) {
            if ($item['value'] < $lowerBound || $item['value'] > $upperBound) {
                $anomalies[] = [
                    'timestamp' => $item['period_start'] ?? $item['timestamp'] ?? null,
                    'value' => $item['value'],
                    'type' => $item['value'] < $lowerBound ? 'low' : 'high',
                    'severity' => $this->calculateAnomalySeverity(
                        $item['value'],
                        $lowerBound,
                        $upperBound
                    )
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Calcola la severità di un'anomalia
     */
    private function calculateAnomalySeverity(
        float $value,
        float $lowerBound,
        float $upperBound
    ): string {
        $deviation = $value < $lowerBound
            ? abs($value - $lowerBound) / abs($lowerBound)
            : abs($value - $upperBound) / abs($upperBound);

        if ($deviation > 0.5) {
            return 'critical';
        } elseif ($deviation > 0.25) {
            return 'high';
        } elseif ($deviation > 0.1) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Recupera metriche in tempo reale con streaming support
     *
     * @param int $tenantId ID del tenant
     * @param array $metricNames Array di nomi metriche
     * @param int $windowSeconds Finestra temporale in secondi
     * @return Generator
     */
    public function streamRealTimeMetrics(
        int $tenantId,
        array $metricNames,
        int $windowSeconds = 60
    ): Generator {
        $db = $this->db->getConnection();

        $placeholders = str_repeat('?,', count($metricNames) - 1) . '?';

        $sql = "SELECT *
                FROM metrics
                WHERE tenant_id = ?
                    AND metric_name IN ($placeholders)
                    AND timestamp >= DATE_SUB(NOW(), INTERVAL ? SECOND)
                    AND aggregation_level = 'raw'
                ORDER BY timestamp DESC";

        $params = array_merge([$tenantId], $metricNames, [$windowSeconds]);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        while ($row = $stmt->fetch()) {
            // Decodifica JSON fields
            if ($row['dimensions']) {
                $row['dimensions'] = json_decode($row['dimensions'], true);
            }
            if ($row['tags']) {
                $row['tags'] = json_decode($row['tags'], true);
            }

            yield $row;
        }
    }

    /**
     * Esporta metriche in vari formati
     *
     * @param int $tenantId ID del tenant
     * @param array $metrics Array di configurazioni metriche
     * @param string $format Formato di export (csv, json, excel)
     * @param DateTime $startDate Data inizio
     * @param DateTime $endDate Data fine
     * @return string|array Dati esportati
     */
    public function exportMetrics(
        int $tenantId,
        array $metrics,
        string $format,
        DateTime $startDate,
        DateTime $endDate
    ): string|array {
        $data = [];

        foreach ($metrics as $metric) {
            $metricData = $this->getMetrics(
                $tenantId,
                $metric['name'],
                $startDate,
                $endDate,
                $metric['filters'] ?? []
            );

            $data[$metric['name']] = $metricData;
        }

        return match($format) {
            'csv' => $this->exportToCsv($data),
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            'excel' => $this->exportToExcel($data),
            default => $data
        };
    }

    /**
     * Esporta dati in formato CSV
     */
    private function exportToCsv(array $data): string {
        $csv = '';
        $headers = ['Metric', 'Timestamp', 'Value', 'Type', 'Source'];
        $csv .= implode(',', $headers) . "\n";

        foreach ($data as $metricName => $metrics) {
            foreach ($metrics as $metric) {
                $row = [
                    $metricName,
                    $metric['timestamp'],
                    $metric['value'],
                    $metric['metric_type'],
                    $metric['source'] ?? ''
                ];
                $csv .= implode(',', array_map('str_getcsv', $row)) . "\n";
            }
        }

        return $csv;
    }

    /**
     * Esporta dati in formato Excel (ritorna path del file)
     */
    private function exportToExcel(array $data): string {
        // Implementazione semplificata - in produzione usare PhpSpreadsheet
        $tempFile = tempnam(sys_get_temp_dir(), 'metrics_export_');
        file_put_contents($tempFile, $this->exportToCsv($data));
        return $tempFile;
    }

    /**
     * Applica politiche di retention e pulisce metriche vecchie
     *
     * @param int $tenantId ID del tenant (0 per tutti)
     * @return array Statistiche di pulizia
     */
    public function pruneOldMetrics(int $tenantId = 0): array {
        $stats = [
            'deleted_raw' => 0,
            'deleted_aggregations' => 0,
            'freed_space' => 0
        ];

        try {
            $db = $this->db->getConnection();

            // Recupera politiche di retention
            $sql = "SELECT DISTINCT tenant_id, metric_name, retention_days
                    FROM metric_definitions
                    WHERE is_active = TRUE";

            if ($tenantId > 0) {
                $sql .= " AND tenant_id = :tenant_id";
            }

            $stmt = $db->prepare($sql);
            if ($tenantId > 0) {
                $stmt->execute([':tenant_id' => $tenantId]);
            } else {
                $stmt->execute();
            }

            $policies = $stmt->fetchAll();

            foreach ($policies as $policy) {
                // Elimina metriche raw oltre il periodo di retention
                $deleteSql = "DELETE FROM metrics
                             WHERE tenant_id = :tenant_id
                                AND metric_name = :metric_name
                                AND aggregation_level = 'raw'
                                AND timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)";

                $deleteStmt = $db->prepare($deleteSql);
                $deleteStmt->execute([
                    ':tenant_id' => $policy['tenant_id'],
                    ':metric_name' => $policy['metric_name'],
                    ':days' => $policy['retention_days']
                ]);

                $stats['deleted_raw'] += $deleteStmt->rowCount();

                // Elimina aggregazioni vecchie con logica graduata
                $this->pruneAggregations($policy['tenant_id'], $policy['metric_name'], $stats);
            }

            // Stima spazio liberato
            $stats['freed_space'] = $this->estimateFreedSpace($stats);

            // Log operazione
            $this->logInfo("Metrics pruning completed", $stats);

        } catch (Exception $e) {
            $this->logError("Pruning failed: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Pulisce aggregazioni con logica graduata
     */
    private function pruneAggregations(int $tenantId, string $metricName, array &$stats): void {
        $db = $this->db->getConnection();

        $policies = [
            ['level' => 'minute', 'keep_days' => 1],
            ['level' => 'hour', 'keep_days' => 7],
            ['level' => 'day', 'keep_days' => 90],
            ['level' => 'week', 'keep_days' => 365],
            ['level' => 'month', 'keep_days' => 1095] // 3 anni
        ];

        foreach ($policies as $policy) {
            $sql = "DELETE FROM metric_aggregations
                    WHERE tenant_id = :tenant_id
                        AND metric_name = :metric_name
                        AND aggregation_level = :level
                        AND period_start < DATE_SUB(NOW(), INTERVAL :days DAY)";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':metric_name' => $metricName,
                ':level' => $policy['level'],
                ':days' => $policy['keep_days']
            ]);

            $stats['deleted_aggregations'] += $stmt->rowCount();
        }
    }

    /**
     * Stima lo spazio liberato
     */
    private function estimateFreedSpace(array $stats): int {
        // Stima approssimativa: 100 bytes per record raw, 150 per aggregazione
        return ($stats['deleted_raw'] * 100) + ($stats['deleted_aggregations'] * 150);
    }

    /**
     * Definisce una nuova metrica personalizzata
     *
     * @param int $tenantId ID del tenant
     * @param array $definition Definizione della metrica
     * @return bool Success status
     */
    public function defineMetric(int $tenantId, array $definition): bool {
        try {
            $db = $this->db->getConnection();

            $sql = "INSERT INTO metric_definitions (
                        tenant_id, metric_name, display_name, description, category,
                        metric_type, unit, calculation_formula, format_pattern,
                        decimal_places, prefix, suffix, warning_threshold,
                        critical_threshold, threshold_direction, collection_interval,
                        retention_days, is_active, aggregation_method, can_aggregate
                    ) VALUES (
                        :tenant_id, :metric_name, :display_name, :description, :category,
                        :metric_type, :unit, :calculation_formula, :format_pattern,
                        :decimal_places, :prefix, :suffix, :warning_threshold,
                        :critical_threshold, :threshold_direction, :collection_interval,
                        :retention_days, :is_active, :aggregation_method, :can_aggregate
                    ) ON DUPLICATE KEY UPDATE
                        display_name = VALUES(display_name),
                        description = VALUES(description),
                        updated_at = NOW()";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':metric_name' => $definition['name'],
                ':display_name' => $definition['display_name'],
                ':description' => $definition['description'] ?? null,
                ':category' => $definition['category'] ?? 'custom',
                ':metric_type' => $definition['type'] ?? 'gauge',
                ':unit' => $definition['unit'] ?? null,
                ':calculation_formula' => $definition['formula'] ?? null,
                ':format_pattern' => $definition['format'] ?? null,
                ':decimal_places' => $definition['decimals'] ?? 2,
                ':prefix' => $definition['prefix'] ?? null,
                ':suffix' => $definition['suffix'] ?? null,
                ':warning_threshold' => $definition['warning_threshold'] ?? null,
                ':critical_threshold' => $definition['critical_threshold'] ?? null,
                ':threshold_direction' => $definition['threshold_direction'] ?? 'above',
                ':collection_interval' => $definition['interval'] ?? 60,
                ':retention_days' => $definition['retention'] ?? 90,
                ':is_active' => $definition['active'] ?? true,
                ':aggregation_method' => $definition['aggregation'] ?? 'avg',
                ':can_aggregate' => $definition['can_aggregate'] ?? true
            ]);

            // Invalida cache delle definizioni
            $this->invalidateCache($tenantId, 'definitions');

            return true;

        } catch (Exception $e) {
            $this->logError("Failed to define metric: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Imposta soglie di allerta per una metrica
     *
     * @param int $tenantId ID del tenant
     * @param string $metricName Nome della metrica
     * @param float|null $warningThreshold Soglia di warning
     * @param float|null $criticalThreshold Soglia critica
     * @param string $direction Direzione (above/below)
     * @return bool Success status
     */
    public function setAlertThresholds(
        int $tenantId,
        string $metricName,
        ?float $warningThreshold,
        ?float $criticalThreshold,
        string $direction = 'above'
    ): bool {
        try {
            $db = $this->db->getConnection();

            $sql = "UPDATE metric_definitions
                    SET warning_threshold = :warning,
                        critical_threshold = :critical,
                        threshold_direction = :direction,
                        updated_at = NOW()
                    WHERE tenant_id = :tenant_id
                        AND metric_name = :metric_name";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':metric_name' => $metricName,
                ':warning' => $warningThreshold,
                ':critical' => $criticalThreshold,
                ':direction' => $direction
            ]);

            // Controlla violazioni immediate
            $this->checkThresholdViolations($tenantId, $metricName);

            return $stmt->rowCount() > 0;

        } catch (Exception $e) {
            $this->logError("Failed to set thresholds: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Controlla violazioni delle soglie e genera alert
     */
    private function checkThresholdViolations(int $tenantId, string $metricName): void {
        $db = $this->db->getConnection();

        $sql = "SELECT m.value, md.warning_threshold, md.critical_threshold, md.threshold_direction
                FROM metrics m
                JOIN metric_definitions md ON
                    m.tenant_id = md.tenant_id AND
                    m.metric_name = md.metric_name
                WHERE m.tenant_id = :tenant_id
                    AND m.metric_name = :metric_name
                    AND m.timestamp >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ORDER BY m.timestamp DESC
                LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':metric_name' => $metricName
        ]);

        $result = $stmt->fetch();

        if ($result) {
            $violated = false;
            $level = 'normal';

            if ($result['threshold_direction'] === 'above') {
                if ($result['critical_threshold'] && $result['value'] > $result['critical_threshold']) {
                    $violated = true;
                    $level = 'critical';
                } elseif ($result['warning_threshold'] && $result['value'] > $result['warning_threshold']) {
                    $violated = true;
                    $level = 'warning';
                }
            } else {
                if ($result['critical_threshold'] && $result['value'] < $result['critical_threshold']) {
                    $violated = true;
                    $level = 'critical';
                } elseif ($result['warning_threshold'] && $result['value'] < $result['warning_threshold']) {
                    $violated = true;
                    $level = 'warning';
                }
            }

            if ($violated) {
                $this->triggerAlert($tenantId, $metricName, $result['value'], $level);
            }
        }
    }

    /**
     * Attiva un alert
     */
    private function triggerAlert(
        int $tenantId,
        string $metricName,
        float $value,
        string $level
    ): void {
        // Implementazione semplificata - integra con sistema notifiche
        $this->logWarning("Alert triggered", [
            'tenant_id' => $tenantId,
            'metric' => $metricName,
            'value' => $value,
            'level' => $level
        ]);

        // TODO: Integrazione con sistema notifiche
    }

    /**
     * Raccoglie metriche di sistema automaticamente
     *
     * @param int $tenantId ID del tenant
     * @return array Metriche raccolte
     */
    public function collectSystemMetrics(int $tenantId): array {
        $metrics = [];

        // Storage usage
        $storageMetrics = $this->collectStorageMetrics($tenantId);
        $metrics = array_merge($metrics, $storageMetrics);

        // Active users
        $userMetrics = $this->collectUserMetrics($tenantId);
        $metrics = array_merge($metrics, $userMetrics);

        // API performance
        $apiMetrics = $this->collectApiMetrics($tenantId);
        $metrics = array_merge($metrics, $apiMetrics);

        // Task metrics
        $taskMetrics = $this->collectTaskMetrics($tenantId);
        $metrics = array_merge($metrics, $taskMetrics);

        // Security metrics
        $securityMetrics = $this->collectSecurityMetrics($tenantId);
        $metrics = array_merge($metrics, $securityMetrics);

        // Salva tutte le metriche
        $this->collectBatch($tenantId, $metrics);

        return $metrics;
    }

    /**
     * Raccoglie metriche di storage
     */
    private function collectStorageMetrics(int $tenantId): array {
        $db = $this->db->getConnection();

        $metrics = [];

        // Total storage used
        $sql = "SELECT
                    SUM(file_size) as total_size,
                    COUNT(*) as file_count,
                    COUNT(DISTINCT uploaded_by) as unique_users
                FROM files
                WHERE tenant_id = :tenant_id";

        $stmt = $db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);
        $result = $stmt->fetch();

        if ($result) {
            $metrics[] = [
                'name' => 'storage_used',
                'value' => round($result['total_size'] / (1024 * 1024 * 1024), 2), // GB
                'type' => 'gauge',
                'source' => 'system'
            ];

            $metrics[] = [
                'name' => 'file_count',
                'value' => $result['file_count'],
                'type' => 'gauge',
                'source' => 'system'
            ];
        }

        return $metrics;
    }

    /**
     * Raccoglie metriche degli utenti
     */
    private function collectUserMetrics(int $tenantId): array {
        $db = $this->db->getConnection();

        $metrics = [];

        // Daily active users
        $sql = "SELECT COUNT(DISTINCT user_id) as dau
                FROM activity_log
                WHERE tenant_id = :tenant_id
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";

        $stmt = $db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);
        $result = $stmt->fetch();

        if ($result) {
            $metrics[] = [
                'name' => 'daily_active_users',
                'value' => $result['dau'],
                'type' => 'gauge',
                'source' => 'system'
            ];
        }

        // Monthly active users
        $sql = "SELECT COUNT(DISTINCT user_id) as mau
                FROM activity_log
                WHERE tenant_id = :tenant_id
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

        $stmt = $db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);
        $result = $stmt->fetch();

        if ($result) {
            $metrics[] = [
                'name' => 'monthly_active_users',
                'value' => $result['mau'],
                'type' => 'gauge',
                'source' => 'system'
            ];
        }

        return $metrics;
    }

    /**
     * Raccoglie metriche API
     */
    private function collectApiMetrics(int $tenantId): array {
        $db = $this->db->getConnection();

        $metrics = [];

        // API request count
        $sql = "SELECT
                    COUNT(*) as request_count,
                    AVG(response_time) as avg_response_time
                FROM api_logs
                WHERE tenant_id = :tenant_id
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";

        $stmt = $db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);
        $result = $stmt->fetch();

        if ($result) {
            $metrics[] = [
                'name' => 'api_requests_hourly',
                'value' => $result['request_count'],
                'type' => 'counter',
                'source' => 'api'
            ];

            if ($result['avg_response_time']) {
                $metrics[] = [
                    'name' => 'api_response_time',
                    'value' => round($result['avg_response_time'], 2),
                    'type' => 'gauge',
                    'source' => 'api'
                ];
            }
        }

        return $metrics;
    }

    /**
     * Raccoglie metriche dei task
     */
    private function collectTaskMetrics(int $tenantId): array {
        $db = $this->db->getConnection();

        $metrics = [];

        // Task completion rate
        $sql = "SELECT
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                    COUNT(*) as total
                FROM tasks
                WHERE tenant_id = :tenant_id
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

        $stmt = $db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);
        $result = $stmt->fetch();

        if ($result && $result['total'] > 0) {
            $completionRate = ($result['completed'] / $result['total']) * 100;

            $metrics[] = [
                'name' => 'task_completion_rate',
                'value' => round($completionRate, 2),
                'type' => 'gauge',
                'source' => 'tasks'
            ];
        }

        return $metrics;
    }

    /**
     * Raccoglie metriche di sicurezza
     */
    private function collectSecurityMetrics(int $tenantId): array {
        $db = $this->db->getConnection();

        $metrics = [];

        // Failed login attempts
        $sql = "SELECT COUNT(*) as failed_logins
                FROM login_attempts
                WHERE tenant_id = :tenant_id
                    AND success = FALSE
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";

        $stmt = $db->prepare($sql);
        $stmt->execute([':tenant_id' => $tenantId]);
        $result = $stmt->fetch();

        if ($result) {
            $metrics[] = [
                'name' => 'failed_login_attempts',
                'value' => $result['failed_logins'],
                'type' => 'counter',
                'source' => 'security'
            ];
        }

        return $metrics;
    }

    /**
     * Genera cache key
     */
    private function generateCacheKey(string $prefix, array $params): string {
        return $prefix . ':' . md5(json_encode($params));
    }

    /**
     * Recupera dalla cache
     */
    private function getFromCache(string $key): mixed {
        // Controlla cache in memoria
        if (isset($this->memoryCache[$key])) {
            $cached = $this->memoryCache[$key];
            if ($cached['expires'] > time()) {
                return $cached['data'];
            }
            unset($this->memoryCache[$key]);
        }

        // Controlla Redis
        if ($this->redis) {
            try {
                $cached = $this->redis->get($key);
                if ($cached !== false) {
                    return json_decode($cached, true);
                }
            } catch (Exception $e) {
                // Fallback silenzioso
            }
        }

        // Controlla Memcached
        if ($this->memcached) {
            try {
                $cached = $this->memcached->get($key);
                if ($cached !== false) {
                    return $cached;
                }
            } catch (Exception $e) {
                // Fallback silenzioso
            }
        }

        return null;
    }

    /**
     * Salva in cache
     */
    private function saveToCache(string $key, mixed $data, int $ttl): void {
        // Salva in memoria
        $this->memoryCache[$key] = [
            'data' => $data,
            'expires' => time() + $ttl
        ];

        // Limita dimensione cache in memoria
        if (count($this->memoryCache) > 100) {
            array_shift($this->memoryCache);
        }

        // Salva in Redis
        if ($this->redis) {
            try {
                $this->redis->setex($key, $ttl, json_encode($data));
            } catch (Exception $e) {
                // Fallback silenzioso
            }
        }

        // Salva in Memcached
        if ($this->memcached) {
            try {
                $this->memcached->set($key, $data, $ttl);
            } catch (Exception $e) {
                // Fallback silenzioso
            }
        }
    }

    /**
     * Invalida cache
     */
    private function invalidateCache(int $tenantId, string $metricName): void {
        // Pattern per le chiavi da invalidare
        $patterns = [
            "metrics:*tenant:$tenantId*metric:$metricName*",
            "aggregations:*tenant:$tenantId*metric:$metricName*"
        ];

        // Pulisci memoria cache
        foreach ($this->memoryCache as $key => $value) {
            if (str_contains($key, "tenant:$tenantId") && str_contains($key, "metric:$metricName")) {
                unset($this->memoryCache[$key]);
            }
        }

        // Pulisci Redis
        if ($this->redis) {
            try {
                foreach ($patterns as $pattern) {
                    $keys = $this->redis->keys($pattern);
                    if (!empty($keys)) {
                        $this->redis->del($keys);
                    }
                }
            } catch (Exception $e) {
                // Fallback silenzioso
            }
        }
    }

    /**
     * Aggiunge metrica al buffer
     */
    private function addToBuffer(array $metric): void {
        $this->batchBuffer[] = $metric;
    }

    /**
     * Svuota il buffer salvando le metriche
     */
    private function flushBuffer(): void {
        if (empty($this->batchBuffer)) {
            return;
        }

        try {
            $db = $this->db->getConnection();

            $sql = "INSERT INTO metrics (
                        tenant_id, metric_name, metric_type, value,
                        dimensions, tags, source, timestamp, aggregation_level
                    ) VALUES ";

            $values = [];
            $params = [];

            foreach ($this->batchBuffer as $index => $metric) {
                $values[] = "(
                    :tenant_id_$index, :metric_name_$index, :metric_type_$index, :value_$index,
                    :dimensions_$index, :tags_$index, :source_$index, :timestamp_$index, :aggregation_level_$index
                )";

                $params[":tenant_id_$index"] = $metric['tenant_id'];
                $params[":metric_name_$index"] = $metric['metric_name'];
                $params[":metric_type_$index"] = $metric['metric_type'];
                $params[":value_$index"] = $metric['value'];
                $params[":dimensions_$index"] = $metric['dimensions'];
                $params[":tags_$index"] = $metric['tags'];
                $params[":source_$index"] = $metric['source'];
                $params[":timestamp_$index"] = $metric['timestamp'];
                $params[":aggregation_level_$index"] = $metric['aggregation_level'];
            }

            $sql .= implode(', ', $values);

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            // Svuota buffer
            $this->batchBuffer = [];

        } catch (Exception $e) {
            $this->logError("Failed to flush buffer: " . $e->getMessage());
        }
    }

    /**
     * Recupera definizione metrica
     */
    private function getMetricDefinition(int $tenantId, string $metricName): ?array {
        $db = $this->db->getConnection();

        $sql = "SELECT * FROM metric_definitions
                WHERE tenant_id = :tenant_id
                    AND metric_name = :metric_name
                    AND is_active = TRUE";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':metric_name' => $metricName
        ]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Calcola periodi di aggregazione
     */
    private function calculateAggregationPeriods(
        string $level,
        DateTime $startDate,
        DateTime $endDate
    ): array {
        $periods = [];
        $current = clone $startDate;

        while ($current < $endDate) {
            $periodEnd = clone $current;

            switch ($level) {
                case 'minute':
                    $periodEnd->modify('+1 minute');
                    break;
                case 'hour':
                    $periodEnd->modify('+1 hour');
                    break;
                case 'day':
                    $periodEnd->modify('+1 day');
                    break;
                case 'week':
                    $periodEnd->modify('+1 week');
                    break;
                case 'month':
                    $periodEnd->modify('+1 month');
                    break;
            }

            $periods[] = [
                'start' => clone $current,
                'end' => clone $periodEnd
            ];

            $current = $periodEnd;
        }

        return $periods;
    }

    /**
     * Processa risultati metriche
     */
    private function processMetricResults(array $results): array {
        foreach ($results as &$result) {
            // Decodifica JSON fields
            if (isset($result['dimensions']) && is_string($result['dimensions'])) {
                $result['dimensions'] = json_decode($result['dimensions'], true);
            }

            if (isset($result['tags']) && is_string($result['tags'])) {
                $result['tags'] = json_decode($result['tags'], true);
            }

            // Formatta valore se ci sono informazioni di formattazione
            if (isset($result['format_pattern'])) {
                $result['formatted_value'] = $this->formatValue(
                    $result['value'],
                    $result['format_pattern'],
                    $result['decimal_places'] ?? 2
                );
            }

            // Aggiungi unità se presente
            if (isset($result['unit'])) {
                $result['value_with_unit'] = $result['value'] . ' ' . $result['unit'];
            }
        }

        return $results;
    }

    /**
     * Formatta un valore secondo il pattern
     */
    private function formatValue(float $value, ?string $pattern, int $decimals): string {
        if (!$pattern) {
            return number_format($value, $decimals);
        }

        // Implementazione semplificata - estendere per pattern complessi
        return number_format($value, $decimals);
    }

    /**
     * Valida tipo di metrica
     */
    private function validateMetricType(string $type): bool {
        return in_array($type, self::METRIC_TYPES);
    }

    /**
     * Valida nome metrica
     */
    private function validateMetricName(string $name): bool {
        return preg_match('/^[a-z0-9_]+$/i', $name) === 1;
    }

    /**
     * Rileva sorgente automaticamente
     */
    private function detectSource(): string {
        // Implementazione semplificata
        return $_SERVER['SCRIPT_NAME'] ?? 'unknown';
    }

    /**
     * Ottiene timestamp corrente con microsecondi
     */
    private function getCurrentTimestamp(): string {
        return date('Y-m-d H:i:s.v');
    }

    /**
     * Verifica se è una metrica realtime
     */
    private function isRealtimeMetric(string $metricName): bool {
        // Lista di metriche che richiedono aggregazione realtime
        $realtimeMetrics = [
            'api_response_time',
            'active_users',
            'error_rate',
            'cpu_usage',
            'memory_usage'
        ];

        return in_array($metricName, $realtimeMetrics);
    }

    /**
     * Trigger aggregazione realtime
     */
    private function triggerRealtimeAggregation(
        int $tenantId,
        string $metricName,
        float $value
    ): void {
        // Implementazione asincrona se necessario
        // Per ora, marca solo per aggregazione immediata
        $this->aggregate(
            $tenantId,
            $metricName,
            'minute',
            new DateTime('-1 minute'),
            new DateTime()
        );
    }

    /**
     * Log error
     */
    private function logError(string $message, array $context = []): void {
        error_log("[MetricsCollector ERROR] $message " . json_encode($context));
    }

    /**
     * Log warning
     */
    private function logWarning(string $message, array $context = []): void {
        error_log("[MetricsCollector WARNING] $message " . json_encode($context));
    }

    /**
     * Log info
     */
    private function logInfo(string $message, array $context = []): void {
        if ($this->debug) {
            error_log("[MetricsCollector INFO] $message " . json_encode($context));
        }
    }

    /**
     * Distruttore - assicura flush del buffer
     */
    public function __destruct() {
        $this->flushBuffer();
    }
}

/**
 * Helper class per aggregazioni asincrone
 */
class MetricsAggregator {

    /**
     * Esegue aggregazioni schedulate
     */
    public static function runScheduledAggregations(): void {
        $collector = MetricsCollector::getInstance();
        $db = Database::getInstance()->getConnection();

        // Recupera metriche che necessitano aggregazione
        $sql = "SELECT DISTINCT tenant_id, metric_name
                FROM metric_definitions
                WHERE is_active = TRUE
                    AND can_aggregate = TRUE";

        $stmt = $db->prepare($sql);
        $stmt->execute();
        $metrics = $stmt->fetchAll();

        $now = new DateTime();

        foreach ($metrics as $metric) {
            // Aggrega ultimo minuto
            $collector->aggregate(
                $metric['tenant_id'],
                $metric['metric_name'],
                'minute',
                new DateTime('-2 minutes'),
                $now
            );

            // Aggrega ultima ora (ogni 5 minuti)
            if (date('i') % 5 == 0) {
                $collector->aggregate(
                    $metric['tenant_id'],
                    $metric['metric_name'],
                    'hour',
                    new DateTime('-2 hours'),
                    $now
                );
            }

            // Aggrega ultimo giorno (ogni ora)
            if (date('i') == '00') {
                $collector->aggregate(
                    $metric['tenant_id'],
                    $metric['metric_name'],
                    'day',
                    new DateTime('-2 days'),
                    $now
                );
            }
        }
    }
}

/**
 * Helper class per export avanzati
 */
class MetricsExporter {

    /**
     * Esporta dashboard completo
     */
    public static function exportDashboard(
        int $tenantId,
        int $dashboardId,
        string $format,
        DateTime $startDate,
        DateTime $endDate
    ): string {
        $collector = MetricsCollector::getInstance();
        $db = Database::getInstance()->getConnection();

        // Recupera widget del dashboard
        $sql = "SELECT * FROM dashboard_widgets
                WHERE tenant_id = :tenant_id
                    AND dashboard_id = :dashboard_id
                    AND is_visible = TRUE
                ORDER BY sort_order";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':dashboard_id' => $dashboardId
        ]);

        $widgets = $stmt->fetchAll();
        $data = [];

        foreach ($widgets as $widget) {
            $config = json_decode($widget['config'], true);

            if (isset($config['metric'])) {
                $metricData = $collector->getMetrics(
                    $tenantId,
                    $config['metric'],
                    $startDate,
                    $endDate
                );

                $data[$widget['title']] = $metricData;
            }
        }

        return $collector->exportMetrics(
            $tenantId,
            array_map(fn($title) => ['name' => $title], array_keys($data)),
            $format,
            $startDate,
            $endDate
        );
    }
}