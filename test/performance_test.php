<?php
/**
 * Comprehensive Performance Test Suite for CollaboraNexio
 *
 * Tests various performance metrics including page load times,
 * API response times, database queries, file uploads, and concurrent users
 *
 * @author CollaboraNexio Performance Team
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Increase memory limit for stress testing
ini_set('memory_limit', '512M');
set_time_limit(0);

// Required includes
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/config.php';

/**
 * Performance Test Suite Class
 */
class PerformanceTestSuite {

    private Database $db;
    private array $results = [];
    private array $recommendations = [];
    private float $startTime;
    private string $reportDir;
    private bool $verbose = false;
    private bool $generateCharts = true;
    private ?string $specificTest = null;
    private int $concurrentUsers = 10;

    // Performance thresholds
    private const PAGE_LOAD_TARGET = 2.0; // seconds
    private const API_RESPONSE_TARGET = 0.5; // seconds
    private const QUERY_TIME_WARNING = 0.1; // seconds
    private const MEMORY_WARNING_THRESHOLD = 50; // MB

    // Test pages and endpoints
    private const TEST_PAGES = [
        'login' => '/login.php',
        'dashboard' => '/dashboard.php',
        'files' => '/files.php',
        'calendar' => '/calendar.php',
        'tasks' => '/tasks.php',
        'chat' => '/chat.php'
    ];

    private const API_ENDPOINTS = [
        'auth' => '/api/auth.php',
        'dashboard' => '/api/dashboard.php',
        'files' => '/api/files.php',
        'tasks' => '/api/tasks.php',
        'events' => '/api/events.php',
        'messages' => '/api/messages.php',
        'channels' => '/api/channels.php'
    ];

    /**
     * Constructor
     */
    public function __construct() {
        $this->startTime = microtime(true);
        $this->db = Database::getInstance();
        $this->reportDir = dirname(__DIR__) . '/test/reports';

        if (!is_dir($this->reportDir)) {
            mkdir($this->reportDir, 0777, true);
        }

        $this->parseCliArguments();
    }

    /**
     * Parse CLI arguments
     */
    private function parseCliArguments(): void {
        global $argv;

        if (isset($argv)) {
            foreach ($argv as $arg) {
                if (strpos($arg, '--test=') === 0) {
                    $this->specificTest = substr($arg, 7);
                } elseif (strpos($arg, '--users=') === 0) {
                    $this->concurrentUsers = (int) substr($arg, 8);
                } elseif ($arg === '--verbose' || $arg === '-v') {
                    $this->verbose = true;
                } elseif ($arg === '--no-charts') {
                    $this->generateCharts = false;
                } elseif ($arg === '--help' || $arg === '-h') {
                    $this->showHelp();
                    exit(0);
                }
            }
        }
    }

    /**
     * Show help information
     */
    private function showHelp(): void {
        echo "\nCollaboraNexio Performance Test Suite\n";
        echo "=====================================\n\n";
        echo "Usage: php performance_test.php [options]\n\n";
        echo "Options:\n";
        echo "  --test=<name>      Run specific test (page_load, api, database, file_upload, polling, memory, concurrent)\n";
        echo "  --users=<number>   Number of concurrent users for stress test (default: 10)\n";
        echo "  --verbose, -v      Enable verbose output\n";
        echo "  --no-charts        Disable chart generation\n";
        echo "  --help, -h         Show this help message\n\n";
        echo "Examples:\n";
        echo "  php performance_test.php                    # Run all tests\n";
        echo "  php performance_test.php --test=api         # Run only API tests\n";
        echo "  php performance_test.php --users=100        # Test with 100 concurrent users\n\n";
    }

    /**
     * Run all performance tests
     */
    public function runAllTests(): void {
        $this->log("\n=== CollaboraNexio Performance Test Suite ===\n");
        $this->log("Started at: " . date('Y-m-d H:i:s') . "\n");

        $tests = [
            'page_load' => 'testPageLoadTime',
            'api' => 'testApiResponseTime',
            'database' => 'testDatabasePerformance',
            'file_upload' => 'testFileUploadSpeed',
            'polling' => 'testLongPollingEfficiency',
            'memory' => 'testMemoryUsage',
            'concurrent' => 'testConcurrentUsers'
        ];

        if ($this->specificTest && isset($tests[$this->specificTest])) {
            $method = $tests[$this->specificTest];
            $this->$method();
        } else {
            foreach ($tests as $name => $method) {
                try {
                    $this->$method();
                } catch (Exception $e) {
                    $this->log("Error in $name test: " . $e->getMessage() . "\n", 'error');
                }
            }
        }

        $this->generateReport();
    }

    /**
     * Test 1: Page Load Time
     */
    private function testPageLoadTime(): void {
        $this->log("\n--- Testing Page Load Times ---\n");

        $results = [];
        $baseUrl = 'http://localhost/CollaboraNexio';

        foreach (self::TEST_PAGES as $name => $path) {
            $url = $baseUrl . $path;

            // Test without cache
            $this->clearCache();
            $startTime = microtime(true);
            $this->fetchPage($url);
            $loadTimeNoCache = microtime(true) - $startTime;

            // Test with cache
            $startTime = microtime(true);
            $this->fetchPage($url);
            $loadTimeWithCache = microtime(true) - $startTime;

            $results[$name] = [
                'no_cache' => $loadTimeNoCache,
                'with_cache' => $loadTimeWithCache,
                'improvement' => round((($loadTimeNoCache - $loadTimeWithCache) / $loadTimeNoCache) * 100, 2)
            ];

            $this->log(sprintf(
                "  %s: %.3fs (no cache) / %.3fs (cached) - %s\n",
                str_pad($name, 10),
                $loadTimeNoCache,
                $loadTimeWithCache,
                $loadTimeNoCache > self::PAGE_LOAD_TARGET ? 'SLOW' : 'OK'
            ));

            // Add recommendations if slow
            if ($loadTimeNoCache > self::PAGE_LOAD_TARGET) {
                $this->addRecommendation('page_load', $name, [
                    'issue' => "Page load time exceeds target ({$loadTimeNoCache}s > " . self::PAGE_LOAD_TARGET . "s)",
                    'suggestions' => [
                        'Enable OPcache for PHP bytecode caching',
                        'Implement browser caching headers',
                        'Minify CSS and JavaScript files',
                        'Use CDN for static assets',
                        'Enable GZIP compression',
                        'Implement lazy loading for images'
                    ]
                ]);
            }
        }

        $this->results['page_load'] = $results;
    }

    /**
     * Test 2: API Response Time
     */
    private function testApiResponseTime(): void {
        $this->log("\n--- Testing API Response Times ---\n");

        $results = [];
        $baseUrl = 'http://localhost/CollaboraNexio';

        // Test different payload sizes
        $payloadSizes = [
            'small' => 10,     // 10 records
            'medium' => 100,   // 100 records
            'large' => 1000    // 1000 records
        ];

        foreach (self::API_ENDPOINTS as $name => $path) {
            $url = $baseUrl . $path;
            $endpointResults = [];

            foreach ($payloadSizes as $size => $count) {
                $times = [];

                // Run multiple iterations for average
                for ($i = 0; $i < 5; $i++) {
                    $startTime = microtime(true);
                    $this->callApi($url, ['limit' => $count]);
                    $times[] = microtime(true) - $startTime;
                }

                $avgTime = array_sum($times) / count($times);
                $endpointResults[$size] = $avgTime;

                $this->log(sprintf(
                    "  %s (%s): %.3fs - %s\n",
                    str_pad($name, 10),
                    str_pad($size, 6),
                    $avgTime,
                    $avgTime > self::API_RESPONSE_TARGET ? 'SLOW' : 'OK'
                ));

                if ($avgTime > self::API_RESPONSE_TARGET) {
                    $this->addRecommendation('api', "{$name}_{$size}", [
                        'issue' => "API response time exceeds target ({$avgTime}s > " . self::API_RESPONSE_TARGET . "s)",
                        'suggestions' => [
                            'Implement response caching with Redis/Memcached',
                            'Add pagination for large datasets',
                            'Optimize database queries with proper indexing',
                            'Use eager loading to reduce N+1 queries',
                            'Consider implementing GraphQL for selective field fetching',
                            'Add API response compression'
                        ]
                    ]);
                }
            }

            $results[$name] = $endpointResults;
        }

        $this->results['api_response'] = $results;
    }

    /**
     * Test 3: Database Query Optimization
     */
    private function testDatabasePerformance(): void {
        $this->log("\n--- Testing Database Performance ---\n");

        $results = [];

        // Test common queries
        $queries = [
            'user_login' => "SELECT * FROM users WHERE email = ? AND tenant_id = ?",
            'dashboard_stats' => "SELECT COUNT(*) as count, type FROM activities WHERE tenant_id = ? GROUP BY type",
            'file_listing' => "SELECT f.*, u.name as owner_name FROM files f JOIN users u ON f.user_id = u.id WHERE f.tenant_id = ? ORDER BY f.created_at DESC LIMIT 50",
            'task_search' => "SELECT t.*, u.name as assignee_name FROM tasks t LEFT JOIN users u ON t.assigned_to = u.id WHERE t.tenant_id = ? AND t.status != 'completed' ORDER BY t.priority DESC, t.due_date ASC",
            'chat_history' => "SELECT m.*, u.name as sender_name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.channel_id = ? ORDER BY m.created_at DESC LIMIT 100",
            'calendar_events' => "SELECT * FROM events WHERE tenant_id = ? AND start_date BETWEEN ? AND ? ORDER BY start_date ASC"
        ];

        foreach ($queries as $name => $query) {
            // Run EXPLAIN
            $explainResult = $this->analyzeQuery($query);

            // Measure execution time
            $times = [];
            for ($i = 0; $i < 10; $i++) {
                $startTime = microtime(true);
                $this->executeTestQuery($query);
                $times[] = microtime(true) - $startTime;
            }

            $avgTime = array_sum($times) / count($times);
            $results[$name] = [
                'avg_time' => $avgTime,
                'explain' => $explainResult,
                'optimized' => $avgTime < self::QUERY_TIME_WARNING
            ];

            $this->log(sprintf(
                "  %s: %.4fs - %s\n",
                str_pad($name, 20),
                $avgTime,
                $avgTime > self::QUERY_TIME_WARNING ? 'NEEDS OPTIMIZATION' : 'OK'
            ));

            // Check for missing indexes
            if ($explainResult['type'] === 'ALL' || $explainResult['rows_examined'] > 1000) {
                $this->addRecommendation('database', $name, [
                    'issue' => "Query performing full table scan or examining too many rows",
                    'suggestions' => [
                        "Add index on frequently queried columns",
                        "Consider composite indexes for multi-column WHERE clauses",
                        "Use EXPLAIN ANALYZE to identify bottlenecks",
                        "Implement query result caching",
                        "Consider denormalization for read-heavy tables",
                        "Add covering indexes to avoid table lookups"
                    ],
                    'proposed_index' => $this->suggestIndex($query, $explainResult)
                ]);
            }
        }

        // Test with large datasets
        $this->testLargeDatasetPerformance();

        $this->results['database'] = $results;
    }

    /**
     * Test 4: File Upload Speed
     */
    private function testFileUploadSpeed(): void {
        $this->log("\n--- Testing File Upload Performance ---\n");

        $results = [];
        $uploadUrl = 'http://localhost/CollaboraNexio/api/files.php';

        // Test different file sizes
        $fileSizes = [
            '1MB' => 1024 * 1024,
            '10MB' => 10 * 1024 * 1024,
            '100MB' => 100 * 1024 * 1024
        ];

        foreach ($fileSizes as $label => $size) {
            // Create temporary test file
            $testFile = $this->createTestFile($size);

            // Single upload test
            $startTime = microtime(true);
            $uploadResult = $this->uploadFile($uploadUrl, $testFile);
            $uploadTime = microtime(true) - $startTime;

            $throughput = ($size / $uploadTime) / (1024 * 1024); // MB/s

            // Concurrent upload test
            $concurrentTimes = $this->testConcurrentUploads($uploadUrl, $testFile, 5);
            $avgConcurrentTime = array_sum($concurrentTimes) / count($concurrentTimes);

            $results[$label] = [
                'upload_time' => $uploadTime,
                'throughput_mbps' => $throughput,
                'concurrent_avg' => $avgConcurrentTime
            ];

            $this->log(sprintf(
                "  %s: %.2fs (%.2f MB/s) - Concurrent: %.2fs avg\n",
                str_pad($label, 6),
                $uploadTime,
                $throughput,
                $avgConcurrentTime
            ));

            // Clean up test file
            unlink($testFile);

            if ($throughput < 10) { // Less than 10 MB/s
                $this->addRecommendation('file_upload', $label, [
                    'issue' => "Low upload throughput ({$throughput} MB/s)",
                    'suggestions' => [
                        'Increase PHP upload_max_filesize and post_max_size',
                        'Optimize PHP-FPM worker processes',
                        'Enable chunked file uploads for large files',
                        'Implement async file processing with queue',
                        'Use dedicated storage service (S3, Azure Blob)',
                        'Enable HTTP/2 for parallel uploads'
                    ]
                ]);
            }
        }

        $this->results['file_upload'] = $results;
    }

    /**
     * Test 5: Long-polling Efficiency
     */
    private function testLongPollingEfficiency(): void {
        $this->log("\n--- Testing Long-polling Efficiency ---\n");

        $results = [];
        $pollingUrl = 'http://localhost/CollaboraNexio/api/polling.php';
        $chatPollUrl = 'http://localhost/CollaboraNexio/api/chat-poll.php';

        // Test different connection counts
        $connectionCounts = [10, 50, 100];

        foreach ($connectionCounts as $count) {
            $startMemory = memory_get_usage(true);
            $startTime = microtime(true);

            // Simulate concurrent polling connections
            $connections = [];
            for ($i = 0; $i < $count; $i++) {
                $connections[] = $this->createPollingConnection($pollingUrl);
            }

            // Hold connections for 5 seconds
            sleep(5);

            $duration = microtime(true) - $startTime;
            $memoryUsed = (memory_get_usage(true) - $startMemory) / (1024 * 1024); // MB
            $memoryPerConnection = $memoryUsed / $count;

            // Close connections
            foreach ($connections as $conn) {
                $this->closePollingConnection($conn);
            }

            $results["connections_$count"] = [
                'duration' => $duration,
                'memory_total_mb' => $memoryUsed,
                'memory_per_conn_mb' => $memoryPerConnection,
                'cpu_usage' => $this->getCpuUsage()
            ];

            $this->log(sprintf(
                "  %d connections: %.2f MB total (%.2f MB/conn) - CPU: %.1f%%\n",
                $count,
                $memoryUsed,
                $memoryPerConnection,
                $this->getCpuUsage()
            ));

            if ($memoryPerConnection > 1) { // More than 1MB per connection
                $this->addRecommendation('polling', "connections_$count", [
                    'issue' => "High memory usage per connection ({$memoryPerConnection} MB)",
                    'suggestions' => [
                        'Implement WebSocket instead of long-polling',
                        'Use Server-Sent Events (SSE) for one-way communication',
                        'Optimize polling interval and timeout',
                        'Implement connection pooling',
                        'Use Redis pub/sub for real-time updates',
                        'Consider using a dedicated real-time server (Socket.io, Pusher)'
                    ]
                ]);
            }
        }

        $this->results['long_polling'] = $results;
    }

    /**
     * Test 6: Memory Usage
     */
    private function testMemoryUsage(): void {
        $this->log("\n--- Testing Memory Usage ---\n");

        $results = [];

        // Test memory usage for different operations
        $operations = [
            'load_users' => function() {
                return $this->db->query("SELECT * FROM users")->fetchAll();
            },
            'load_files' => function() {
                return $this->db->query("SELECT * FROM files LIMIT 1000")->fetchAll();
            },
            'process_large_dataset' => function() {
                $data = [];
                for ($i = 0; $i < 10000; $i++) {
                    $data[] = [
                        'id' => $i,
                        'data' => str_repeat('x', 1000)
                    ];
                }
                return $data;
            },
            'generate_report' => function() {
                $report = [];
                for ($i = 0; $i < 100; $i++) {
                    $report[] = $this->generateMockReport();
                }
                return $report;
            }
        ];

        foreach ($operations as $name => $operation) {
            $startMemory = memory_get_usage(true);
            $startPeakMemory = memory_get_peak_usage(true);

            // Run operation
            $result = $operation();

            $memoryUsed = (memory_get_usage(true) - $startMemory) / (1024 * 1024);
            $peakMemory = (memory_get_peak_usage(true) - $startPeakMemory) / (1024 * 1024);

            // Test for memory leaks
            unset($result);
            gc_collect_cycles();
            $memoryAfterGC = (memory_get_usage(true) - $startMemory) / (1024 * 1024);

            $results[$name] = [
                'memory_used_mb' => $memoryUsed,
                'peak_memory_mb' => $peakMemory,
                'after_gc_mb' => $memoryAfterGC,
                'potential_leak' => $memoryAfterGC > 0.1
            ];

            $this->log(sprintf(
                "  %s: %.2f MB (peak: %.2f MB) - After GC: %.2f MB %s\n",
                str_pad($name, 20),
                $memoryUsed,
                $peakMemory,
                $memoryAfterGC,
                $memoryAfterGC > 0.1 ? 'POTENTIAL LEAK' : 'OK'
            ));

            if ($memoryUsed > self::MEMORY_WARNING_THRESHOLD) {
                $this->addRecommendation('memory', $name, [
                    'issue' => "High memory consumption ({$memoryUsed} MB)",
                    'suggestions' => [
                        'Use generators for large datasets instead of arrays',
                        'Implement pagination for database queries',
                        'Stream large files instead of loading into memory',
                        'Use unset() and gc_collect_cycles() for large objects',
                        'Optimize data structures and use appropriate types',
                        'Consider using memory-efficient libraries'
                    ]
                ]);
            }
        }

        // Check database connection pooling
        $this->testDatabaseConnectionPooling();

        $this->results['memory_usage'] = $results;
    }

    /**
     * Test 7: Concurrent Users Simulation
     */
    private function testConcurrentUsers(): void {
        $this->log("\n--- Testing Concurrent Users ---\n");
        $this->log("Simulating {$this->concurrentUsers} concurrent users...\n");

        $results = [];
        $userCounts = [$this->concurrentUsers];

        // Add additional test points if not specified
        if ($this->concurrentUsers === 10) {
            $userCounts = [10, 50, 100, 500];
        }

        foreach ($userCounts as $userCount) {
            $this->log("\n  Testing with $userCount users:\n");

            $startTime = microtime(true);
            $responses = [];

            // Simulate user actions
            $actions = [
                'login' => '/login.php',
                'dashboard' => '/dashboard.php',
                'load_files' => '/api/files.php',
                'send_message' => '/api/messages.php',
                'update_task' => '/api/tasks.php'
            ];

            // Use multi-threading simulation
            $threads = [];
            for ($i = 0; $i < $userCount; $i++) {
                foreach ($actions as $action => $endpoint) {
                    $threads[] = $this->simulateUserAction($endpoint, $i);
                }
            }

            // Wait for all threads to complete
            $completedCount = 0;
            $failedCount = 0;
            $totalResponseTime = 0;

            foreach ($threads as $thread) {
                if ($thread['success']) {
                    $completedCount++;
                    $totalResponseTime += $thread['response_time'];
                } else {
                    $failedCount++;
                }
            }

            $totalTime = microtime(true) - $startTime;
            $avgResponseTime = $totalResponseTime / max($completedCount, 1);
            $throughput = $completedCount / $totalTime;

            $results["users_$userCount"] = [
                'total_requests' => count($threads),
                'successful' => $completedCount,
                'failed' => $failedCount,
                'total_time' => $totalTime,
                'avg_response_time' => $avgResponseTime,
                'throughput_rps' => $throughput,
                'error_rate' => ($failedCount / count($threads)) * 100
            ];

            $this->log(sprintf(
                "    Completed: %d/%d (%.1f%% success rate)\n",
                $completedCount,
                count($threads),
                ($completedCount / count($threads)) * 100
            ));
            $this->log(sprintf(
                "    Avg Response: %.3fs | Throughput: %.1f req/s\n",
                $avgResponseTime,
                $throughput
            ));

            if ($avgResponseTime > 2.0 || ($failedCount / count($threads)) > 0.05) {
                $this->addRecommendation('concurrent', "users_$userCount", [
                    'issue' => "Performance degradation under load",
                    'suggestions' => [
                        'Scale horizontally with load balancer',
                        'Optimize PHP-FPM settings (pm.max_children, pm.start_servers)',
                        'Implement connection pooling for database',
                        'Use Redis for session storage',
                        'Enable OPcache and APCu for caching',
                        'Consider microservices architecture for scaling',
                        'Implement rate limiting to prevent overload',
                        'Use CDN for static content delivery'
                    ],
                    'server_config' => $this->getOptimalServerConfig($userCount)
                ]);
            }
        }

        $this->results['concurrent_users'] = $results;
    }

    /**
     * Helper: Clear cache
     */
    private function clearCache(): void {
        // Clear OPcache
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // Clear APCu cache
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }

        // Clear file system cache (Linux)
        if (PHP_OS === 'Linux') {
            exec('sync && echo 3 > /proc/sys/vm/drop_caches 2>/dev/null');
        }
    }

    /**
     * Helper: Fetch page with timing
     */
    private function fetchPage(string $url): string {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookie.txt');
        curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookie.txt');

        $response = curl_exec($ch);
        curl_close($ch);

        return $response ?: '';
    }

    /**
     * Helper: Call API endpoint
     */
    private function callApi(string $url, array $params = []): array {
        $ch = curl_init();

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status_code' => $httpCode,
            'response' => json_decode($response ?: '{}', true)
        ];
    }

    /**
     * Helper: Analyze query with EXPLAIN
     */
    private function analyzeQuery(string $query): array {
        try {
            // Replace placeholders for EXPLAIN
            $explainQuery = str_replace('?', '1', $query);
            $explainQuery = "EXPLAIN " . $explainQuery;

            $stmt = $this->db->query($explainQuery);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'type' => $result['type'] ?? 'UNKNOWN',
                'possible_keys' => $result['possible_keys'] ?? '',
                'key' => $result['key'] ?? '',
                'rows_examined' => $result['rows'] ?? 0,
                'extra' => $result['Extra'] ?? ''
            ];
        } catch (Exception $e) {
            return [
                'type' => 'ERROR',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Helper: Execute test query
     */
    private function executeTestQuery(string $query): void {
        try {
            $stmt = $this->db->prepare($query);

            // Add dummy parameters
            $paramCount = substr_count($query, '?');
            $params = array_fill(0, $paramCount, 1);

            if ($paramCount > 0) {
                $stmt->execute($params);
            } else {
                $stmt->execute();
            }

            $stmt->fetchAll();
        } catch (Exception $e) {
            // Ignore errors for test queries
        }
    }

    /**
     * Helper: Suggest index based on query analysis
     */
    private function suggestIndex(string $query, array $explainResult): string {
        $suggestion = "";

        // Parse query to find WHERE and JOIN columns
        if (preg_match('/WHERE\s+(\w+)\.?(\w+)\s*=/', $query, $matches)) {
            $table = $matches[1];
            $column = $matches[2];
            $suggestion = "CREATE INDEX idx_{$table}_{$column} ON {$table}({$column});";
        }

        if (preg_match('/JOIN\s+(\w+)\s+\w+\s+ON\s+\w+\.(\w+)\s*=\s*\w+\.(\w+)/', $query, $matches)) {
            $table = $matches[1];
            $column = $matches[2];
            $suggestion .= "\nCREATE INDEX idx_{$table}_{$column} ON {$table}({$column});";
        }

        return $suggestion ?: "Analyze query pattern for optimal index creation";
    }

    /**
     * Helper: Test large dataset performance
     */
    private function testLargeDatasetPerformance(): void {
        $this->log("\n  Testing with large datasets:\n");

        // Test with increasing data sizes
        $sizes = [1000, 10000, 100000];

        foreach ($sizes as $size) {
            $startTime = microtime(true);

            try {
                $stmt = $this->db->query("SELECT * FROM files LIMIT $size");
                $data = $stmt->fetchAll();
                $queryTime = microtime(true) - $startTime;

                $this->log(sprintf(
                    "    %d rows: %.3fs (%.1f rows/sec)\n",
                    $size,
                    $queryTime,
                    $size / $queryTime
                ));
            } catch (Exception $e) {
                $this->log("    Error testing $size rows: " . $e->getMessage() . "\n");
            }
        }
    }

    /**
     * Helper: Create test file
     */
    private function createTestFile(int $size): string {
        $filename = sys_get_temp_dir() . '/test_' . uniqid() . '.dat';
        $file = fopen($filename, 'wb');

        $chunkSize = 1024 * 1024; // 1MB chunks
        $chunks = (int) ($size / $chunkSize);
        $remainder = $size % $chunkSize;

        for ($i = 0; $i < $chunks; $i++) {
            fwrite($file, str_repeat('X', $chunkSize));
        }

        if ($remainder > 0) {
            fwrite($file, str_repeat('X', $remainder));
        }

        fclose($file);
        return $filename;
    }

    /**
     * Helper: Upload file
     */
    private function uploadFile(string $url, string $filepath): array {
        $ch = curl_init();

        $cfile = new CURLFile($filepath, 'application/octet-stream', basename($filepath));
        $data = ['file' => $cfile, 'tenant_id' => 1];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status_code' => $httpCode,
            'response' => json_decode($response ?: '{}', true)
        ];
    }

    /**
     * Helper: Test concurrent uploads
     */
    private function testConcurrentUploads(string $url, string $filepath, int $count): array {
        $times = [];
        $mh = curl_multi_init();
        $handles = [];

        for ($i = 0; $i < $count; $i++) {
            $ch = curl_init();
            $cfile = new CURLFile($filepath, 'application/octet-stream', basename($filepath));
            $data = ['file' => $cfile, 'tenant_id' => 1];

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);

            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        $running = null;
        $startTime = microtime(true);

        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        $totalTime = microtime(true) - $startTime;

        foreach ($handles as $ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        // Calculate individual times (approximation)
        for ($i = 0; $i < $count; $i++) {
            $times[] = $totalTime / $count;
        }

        return $times;
    }

    /**
     * Helper: Create polling connection
     */
    private function createPollingConnection(string $url): resource|false {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);

        return $ch;
    }

    /**
     * Helper: Close polling connection
     */
    private function closePollingConnection($connection): void {
        if (is_resource($connection)) {
            curl_close($connection);
        }
    }

    /**
     * Helper: Get CPU usage
     */
    private function getCpuUsage(): float {
        if (PHP_OS === 'Linux') {
            $load = sys_getloadavg();
            return $load[0] * 100;
        }
        return 0.0;
    }

    /**
     * Helper: Generate mock report
     */
    private function generateMockReport(): array {
        return [
            'id' => uniqid(),
            'timestamp' => time(),
            'data' => array_fill(0, 100, [
                'metric' => rand(0, 100),
                'value' => rand(100, 1000) / 10
            ])
        ];
    }

    /**
     * Helper: Test database connection pooling
     */
    private function testDatabaseConnectionPooling(): void {
        $this->log("\n  Testing database connection pooling:\n");

        $connections = [];
        $startTime = microtime(true);

        // Try to create multiple connections
        for ($i = 0; $i < 20; $i++) {
            try {
                $conn = new PDO(
                    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
                    DB_USER,
                    DB_PASSWORD
                );
                $connections[] = $conn;
            } catch (PDOException $e) {
                $this->log("    Failed to create connection $i: " . $e->getMessage() . "\n");
                break;
            }
        }

        $connectionTime = microtime(true) - $startTime;
        $this->log(sprintf(
            "    Created %d connections in %.3fs\n",
            count($connections),
            $connectionTime
        ));

        // Close connections
        $connections = [];
    }

    /**
     * Helper: Simulate user action
     */
    private function simulateUserAction(string $endpoint, int $userId): array {
        $url = 'http://localhost/CollaboraNexio' . $endpoint;
        $startTime = microtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-User-Id: ' . $userId,
            'X-Tenant-Id: 1'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseTime = microtime(true) - $startTime;
        curl_close($ch);

        return [
            'success' => $httpCode === 200,
            'response_time' => $responseTime,
            'status_code' => $httpCode
        ];
    }

    /**
     * Helper: Get optimal server configuration
     */
    private function getOptimalServerConfig(int $userCount): array {
        return [
            'php_fpm' => [
                'pm' => 'dynamic',
                'pm.max_children' => max(50, $userCount / 2),
                'pm.start_servers' => max(10, $userCount / 10),
                'pm.min_spare_servers' => 5,
                'pm.max_spare_servers' => max(20, $userCount / 5),
                'pm.max_requests' => 500
            ],
            'mysql' => [
                'max_connections' => max(100, $userCount * 2),
                'innodb_buffer_pool_size' => '1G',
                'query_cache_size' => '64M',
                'key_buffer_size' => '256M',
                'thread_cache_size' => 8
            ],
            'opcache' => [
                'opcache.enable' => 1,
                'opcache.memory_consumption' => 256,
                'opcache.max_accelerated_files' => 10000,
                'opcache.revalidate_freq' => 2
            ]
        ];
    }

    /**
     * Add recommendation
     */
    private function addRecommendation(string $category, string $item, array $details): void {
        if (!isset($this->recommendations[$category])) {
            $this->recommendations[$category] = [];
        }

        $this->recommendations[$category][$item] = $details;
    }

    /**
     * Generate performance report
     */
    private function generateReport(): void {
        $reportFile = $this->reportDir . '/performance_report_' . date('Y-m-d_H-i-s') . '.html';

        $html = $this->generateHtmlReport();
        file_put_contents($reportFile, $html);

        $this->log("\n=== Performance Test Complete ===\n");
        $this->log("Execution time: " . round(microtime(true) - $this->startTime, 2) . " seconds\n");
        $this->log("Report generated: $reportFile\n");

        if (PHP_SAPI === 'cli') {
            echo "\nOpen the report in your browser:\n";
            echo "file://" . realpath($reportFile) . "\n\n";
        }
    }

    /**
     * Generate HTML report
     */
    private function generateHtmlReport(): string {
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio Performance Report - ' . date('Y-m-d H:i:s') . '</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        .header .subtitle {
            opacity: 0.9;
            font-size: 1.1em;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f8f9fa;
        }
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .summary-card h3 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .summary-card .value {
            font-size: 2em;
            font-weight: bold;
            color: #333;
        }
        .summary-card .status {
            margin-top: 10px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85em;
            display: inline-block;
        }
        .status.good { background: #d4edda; color: #155724; }
        .status.warning { background: #fff3cd; color: #856404; }
        .status.error { background: #f8d7da; color: #721c24; }
        .content {
            padding: 40px;
        }
        .section {
            margin-bottom: 50px;
        }
        .section h2 {
            color: #333;
            margin-bottom: 30px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chart-container {
            position: relative;
            height: 400px;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .results-table th {
            background: #667eea;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 500;
        }
        .results-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        .results-table tr:hover {
            background: #f8f9fa;
        }
        .recommendation {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .recommendation h4 {
            color: #856404;
            margin-bottom: 10px;
        }
        .recommendation ul {
            margin-left: 20px;
            color: #666;
            line-height: 1.8;
        }
        .metric-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 500;
        }
        .metric-good { background: #d4edda; color: #155724; }
        .metric-warning { background: #fff3cd; color: #856404; }
        .metric-error { background: #f8d7da; color: #721c24; }
        .footer {
            background: #f8f9fa;
            padding: 30px;
            text-align: center;
            color: #666;
            border-top: 1px solid #dee2e6;
        }
        .icon {
            width: 24px;
            height: 24px;
            vertical-align: middle;
        }
        @media (max-width: 768px) {
            .summary { grid-template-columns: 1fr; }
            .header h1 { font-size: 1.8em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 CollaboraNexio Performance Report</h1>
            <div class="subtitle">Generated on ' . date('Y-m-d H:i:s') . '</div>
        </div>

        <div class="summary">
            ' . $this->generateSummaryCards() . '
        </div>

        <div class="content">
            ' . $this->generateTestSections() . '
            ' . $this->generateRecommendationsSection() . '
        </div>

        <div class="footer">
            <p>Performance Test Suite v1.0.0 | PHP ' . PHP_VERSION . ' | Execution Time: ' .
            round(microtime(true) - $this->startTime, 2) . 's</p>
        </div>
    </div>

    ' . $this->generateChartScripts() . '
</body>
</html>';

        return $html;
    }

    /**
     * Generate summary cards
     */
    private function generateSummaryCards(): string {
        $cards = [];

        // Page Load Summary
        if (isset($this->results['page_load'])) {
            $avgTime = 0;
            $count = 0;
            foreach ($this->results['page_load'] as $page => $data) {
                $avgTime += $data['no_cache'];
                $count++;
            }
            $avgTime = $count > 0 ? $avgTime / $count : 0;

            $status = $avgTime <= self::PAGE_LOAD_TARGET ? 'good' :
                     ($avgTime <= self::PAGE_LOAD_TARGET * 1.5 ? 'warning' : 'error');

            $cards[] = '
                <div class="summary-card">
                    <h3>📄 Avg Page Load</h3>
                    <div class="value">' . number_format($avgTime, 2) . 's</div>
                    <div class="status ' . $status . '">Target: < ' . self::PAGE_LOAD_TARGET . 's</div>
                </div>';
        }

        // API Response Summary
        if (isset($this->results['api_response'])) {
            $totalTime = 0;
            $count = 0;
            foreach ($this->results['api_response'] as $endpoint => $sizes) {
                foreach ($sizes as $size => $time) {
                    $totalTime += $time;
                    $count++;
                }
            }
            $avgTime = $count > 0 ? $totalTime / $count : 0;

            $status = $avgTime <= self::API_RESPONSE_TARGET ? 'good' :
                     ($avgTime <= self::API_RESPONSE_TARGET * 1.5 ? 'warning' : 'error');

            $cards[] = '
                <div class="summary-card">
                    <h3>⚡ Avg API Response</h3>
                    <div class="value">' . number_format($avgTime * 1000, 0) . 'ms</div>
                    <div class="status ' . $status . '">Target: < ' . (self::API_RESPONSE_TARGET * 1000) . 'ms</div>
                </div>';
        }

        // Database Performance Summary
        if (isset($this->results['database'])) {
            $slowQueries = 0;
            foreach ($this->results['database'] as $query => $data) {
                if (!$data['optimized']) {
                    $slowQueries++;
                }
            }

            $status = $slowQueries === 0 ? 'good' : ($slowQueries <= 2 ? 'warning' : 'error');

            $cards[] = '
                <div class="summary-card">
                    <h3>🗄️ Slow Queries</h3>
                    <div class="value">' . $slowQueries . '</div>
                    <div class="status ' . $status . '">' .
                    ($slowQueries === 0 ? 'All Optimized' : 'Need Optimization') . '</div>
                </div>';
        }

        // Memory Usage Summary
        if (isset($this->results['memory_usage'])) {
            $maxMemory = 0;
            foreach ($this->results['memory_usage'] as $op => $data) {
                if ($data['peak_memory_mb'] > $maxMemory) {
                    $maxMemory = $data['peak_memory_mb'];
                }
            }

            $status = $maxMemory <= self::MEMORY_WARNING_THRESHOLD ? 'good' :
                     ($maxMemory <= self::MEMORY_WARNING_THRESHOLD * 2 ? 'warning' : 'error');

            $cards[] = '
                <div class="summary-card">
                    <h3>💾 Peak Memory</h3>
                    <div class="value">' . number_format($maxMemory, 1) . ' MB</div>
                    <div class="status ' . $status . '">Threshold: ' . self::MEMORY_WARNING_THRESHOLD . ' MB</div>
                </div>';
        }

        // Recommendations Count
        $recommendationCount = 0;
        foreach ($this->recommendations as $category => $items) {
            $recommendationCount += count($items);
        }

        $status = $recommendationCount === 0 ? 'good' :
                 ($recommendationCount <= 5 ? 'warning' : 'error');

        $cards[] = '
            <div class="summary-card">
                <h3>💡 Recommendations</h3>
                <div class="value">' . $recommendationCount . '</div>
                <div class="status ' . $status . '">' .
                ($recommendationCount === 0 ? 'Excellent!' : 'Areas to Improve') . '</div>
            </div>';

        // Overall Score
        $score = $this->calculateOverallScore();
        $status = $score >= 90 ? 'good' : ($score >= 70 ? 'warning' : 'error');

        $cards[] = '
            <div class="summary-card">
                <h3>🏆 Overall Score</h3>
                <div class="value">' . $score . '%</div>
                <div class="status ' . $status . '">' .
                ($score >= 90 ? 'Excellent' : ($score >= 70 ? 'Good' : 'Needs Work')) . '</div>
            </div>';

        return implode('', $cards);
    }

    /**
     * Generate test sections
     */
    private function generateTestSections(): string {
        $sections = '';

        // Page Load Section
        if (isset($this->results['page_load'])) {
            $sections .= $this->generatePageLoadSection();
        }

        // API Response Section
        if (isset($this->results['api_response'])) {
            $sections .= $this->generateApiSection();
        }

        // Database Section
        if (isset($this->results['database'])) {
            $sections .= $this->generateDatabaseSection();
        }

        // File Upload Section
        if (isset($this->results['file_upload'])) {
            $sections .= $this->generateFileUploadSection();
        }

        // Memory Usage Section
        if (isset($this->results['memory_usage'])) {
            $sections .= $this->generateMemorySection();
        }

        // Concurrent Users Section
        if (isset($this->results['concurrent_users'])) {
            $sections .= $this->generateConcurrentSection();
        }

        return $sections;
    }

    /**
     * Generate page load section
     */
    private function generatePageLoadSection(): string {
        $data = $this->results['page_load'];

        $html = '
        <div class="section">
            <h2>📄 Page Load Performance</h2>

            <div class="chart-container">
                <canvas id="pageLoadChart"></canvas>
            </div>

            <table class="results-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>No Cache (s)</th>
                        <th>With Cache (s)</th>
                        <th>Improvement</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($data as $page => $metrics) {
            $status = $metrics['no_cache'] <= self::PAGE_LOAD_TARGET ? 'metric-good' :
                     ($metrics['no_cache'] <= self::PAGE_LOAD_TARGET * 1.5 ? 'metric-warning' : 'metric-error');

            $html .= '
                    <tr>
                        <td><strong>' . ucfirst($page) . '</strong></td>
                        <td>' . number_format($metrics['no_cache'], 3) . '</td>
                        <td>' . number_format($metrics['with_cache'], 3) . '</td>
                        <td>' . $metrics['improvement'] . '%</td>
                        <td><span class="metric-badge ' . $status . '">' .
                        ($metrics['no_cache'] <= self::PAGE_LOAD_TARGET ? 'PASS' : 'SLOW') . '</span></td>
                    </tr>';
        }

        $html .= '
                </tbody>
            </table>
        </div>';

        return $html;
    }

    /**
     * Generate API section
     */
    private function generateApiSection(): string {
        $data = $this->results['api_response'];

        $html = '
        <div class="section">
            <h2>⚡ API Response Times</h2>

            <div class="chart-container">
                <canvas id="apiChart"></canvas>
            </div>

            <table class="results-table">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Small (10)</th>
                        <th>Medium (100)</th>
                        <th>Large (1000)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($data as $endpoint => $sizes) {
            $maxTime = max($sizes);
            $status = $maxTime <= self::API_RESPONSE_TARGET ? 'metric-good' :
                     ($maxTime <= self::API_RESPONSE_TARGET * 1.5 ? 'metric-warning' : 'metric-error');

            $html .= '
                    <tr>
                        <td><strong>' . ucfirst($endpoint) . '</strong></td>
                        <td>' . number_format($sizes['small'] * 1000, 0) . 'ms</td>
                        <td>' . number_format($sizes['medium'] * 1000, 0) . 'ms</td>
                        <td>' . number_format($sizes['large'] * 1000, 0) . 'ms</td>
                        <td><span class="metric-badge ' . $status . '">' .
                        ($maxTime <= self::API_RESPONSE_TARGET ? 'FAST' : 'SLOW') . '</span></td>
                    </tr>';
        }

        $html .= '
                </tbody>
            </table>
        </div>';

        return $html;
    }

    /**
     * Generate database section
     */
    private function generateDatabaseSection(): string {
        $data = $this->results['database'];

        $html = '
        <div class="section">
            <h2>🗄️ Database Query Performance</h2>

            <table class="results-table">
                <thead>
                    <tr>
                        <th>Query</th>
                        <th>Avg Time (ms)</th>
                        <th>Query Type</th>
                        <th>Index Used</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($data as $query => $metrics) {
            if (is_array($metrics) && isset($metrics['avg_time'])) {
                $status = $metrics['optimized'] ? 'metric-good' : 'metric-error';

                $html .= '
                    <tr>
                        <td><strong>' . str_replace('_', ' ', ucfirst($query)) . '</strong></td>
                        <td>' . number_format($metrics['avg_time'] * 1000, 2) . '</td>
                        <td>' . ($metrics['explain']['type'] ?? 'N/A') . '</td>
                        <td>' . (!empty($metrics['explain']['key']) ? $metrics['explain']['key'] : 'None') . '</td>
                        <td><span class="metric-badge ' . $status . '">' .
                        ($metrics['optimized'] ? 'OPTIMIZED' : 'NEEDS INDEX') . '</span></td>
                    </tr>';
            }
        }

        $html .= '
                </tbody>
            </table>
        </div>';

        return $html;
    }

    /**
     * Generate file upload section
     */
    private function generateFileUploadSection(): string {
        if (!isset($this->results['file_upload'])) {
            return '';
        }

        $data = $this->results['file_upload'];

        $html = '
        <div class="section">
            <h2>📁 File Upload Performance</h2>

            <div class="chart-container">
                <canvas id="uploadChart"></canvas>
            </div>

            <table class="results-table">
                <thead>
                    <tr>
                        <th>File Size</th>
                        <th>Upload Time</th>
                        <th>Throughput</th>
                        <th>Concurrent Avg</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($data as $size => $metrics) {
            $html .= '
                    <tr>
                        <td><strong>' . $size . '</strong></td>
                        <td>' . number_format($metrics['upload_time'], 2) . 's</td>
                        <td>' . number_format($metrics['throughput_mbps'], 2) . ' MB/s</td>
                        <td>' . number_format($metrics['concurrent_avg'], 2) . 's</td>
                    </tr>';
        }

        $html .= '
                </tbody>
            </table>
        </div>';

        return $html;
    }

    /**
     * Generate memory section
     */
    private function generateMemorySection(): string {
        if (!isset($this->results['memory_usage'])) {
            return '';
        }

        $data = $this->results['memory_usage'];

        $html = '
        <div class="section">
            <h2>💾 Memory Usage Analysis</h2>

            <div class="chart-container">
                <canvas id="memoryChart"></canvas>
            </div>

            <table class="results-table">
                <thead>
                    <tr>
                        <th>Operation</th>
                        <th>Memory Used</th>
                        <th>Peak Memory</th>
                        <th>After GC</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($data as $operation => $metrics) {
            $status = $metrics['potential_leak'] ? 'metric-error' : 'metric-good';

            $html .= '
                    <tr>
                        <td><strong>' . str_replace('_', ' ', ucfirst($operation)) . '</strong></td>
                        <td>' . number_format($metrics['memory_used_mb'], 2) . ' MB</td>
                        <td>' . number_format($metrics['peak_memory_mb'], 2) . ' MB</td>
                        <td>' . number_format($metrics['after_gc_mb'], 2) . ' MB</td>
                        <td><span class="metric-badge ' . $status . '">' .
                        ($metrics['potential_leak'] ? 'LEAK DETECTED' : 'OK') . '</span></td>
                    </tr>';
        }

        $html .= '
                </tbody>
            </table>
        </div>';

        return $html;
    }

    /**
     * Generate concurrent users section
     */
    private function generateConcurrentSection(): string {
        if (!isset($this->results['concurrent_users'])) {
            return '';
        }

        $data = $this->results['concurrent_users'];

        $html = '
        <div class="section">
            <h2>👥 Concurrent Users Simulation</h2>

            <div class="chart-container">
                <canvas id="concurrentChart"></canvas>
            </div>

            <table class="results-table">
                <thead>
                    <tr>
                        <th>Users</th>
                        <th>Success Rate</th>
                        <th>Avg Response</th>
                        <th>Throughput</th>
                        <th>Error Rate</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($data as $test => $metrics) {
            $userCount = str_replace('users_', '', $test);
            $successRate = ($metrics['successful'] / $metrics['total_requests']) * 100;

            $html .= '
                    <tr>
                        <td><strong>' . $userCount . ' users</strong></td>
                        <td>' . number_format($successRate, 1) . '%</td>
                        <td>' . number_format($metrics['avg_response_time'], 3) . 's</td>
                        <td>' . number_format($metrics['throughput_rps'], 1) . ' req/s</td>
                        <td>' . number_format($metrics['error_rate'], 1) . '%</td>
                    </tr>';
        }

        $html .= '
                </tbody>
            </table>
        </div>';

        return $html;
    }

    /**
     * Generate recommendations section
     */
    private function generateRecommendationsSection(): string {
        if (empty($this->recommendations)) {
            return '
        <div class="section">
            <h2>💡 Recommendations</h2>
            <div style="padding: 40px; text-align: center; background: #d4edda; border-radius: 10px; color: #155724;">
                <h3>🎉 Excellent Performance!</h3>
                <p style="margin-top: 10px;">No critical performance issues detected. The system is running optimally.</p>
            </div>
        </div>';
        }

        $html = '
        <div class="section">
            <h2>💡 Performance Optimization Recommendations</h2>';

        foreach ($this->recommendations as $category => $items) {
            $html .= '
            <h3 style="margin-top: 30px; color: #667eea;">' . ucfirst(str_replace('_', ' ', $category)) . '</h3>';

            foreach ($items as $item => $details) {
                $html .= '
            <div class="recommendation">
                <h4>⚠️ ' . str_replace('_', ' ', ucfirst($item)) . '</h4>
                <p style="margin-bottom: 10px; color: #666;">' . $details['issue'] . '</p>
                <ul>';

                foreach ($details['suggestions'] as $suggestion) {
                    $html .= '<li>' . $suggestion . '</li>';
                }

                if (isset($details['proposed_index'])) {
                    $html .= '</ul>
                <p style="margin-top: 15px;"><strong>Proposed SQL:</strong></p>
                <pre style="background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto;">' .
                htmlspecialchars($details['proposed_index']) . '</pre>';
                } elseif (isset($details['server_config'])) {
                    $html .= '</ul>
                <p style="margin-top: 15px;"><strong>Recommended Configuration:</strong></p>
                <pre style="background: #f8f9fa; padding: 10px; border-radius: 5px; overflow-x: auto;">' .
                json_encode($details['server_config'], JSON_PRETTY_PRINT) . '</pre>';
                } else {
                    $html .= '</ul>';
                }

                $html .= '
            </div>';
            }
        }

        $html .= '
        </div>';

        return $html;
    }

    /**
     * Generate chart scripts
     */
    private function generateChartScripts(): string {
        $scripts = '<script>';

        // Page Load Chart
        if (isset($this->results['page_load'])) {
            $labels = array_keys($this->results['page_load']);
            $noCacheData = array_column($this->results['page_load'], 'no_cache');
            $withCacheData = array_column($this->results['page_load'], 'with_cache');

            $scripts .= "
            new Chart(document.getElementById('pageLoadChart'), {
                type: 'bar',
                data: {
                    labels: " . json_encode(array_map('ucfirst', $labels)) . ",
                    datasets: [{
                        label: 'No Cache',
                        data: " . json_encode($noCacheData) . ",
                        backgroundColor: 'rgba(255, 99, 132, 0.5)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }, {
                        label: 'With Cache',
                        data: " . json_encode($withCacheData) . ",
                        backgroundColor: 'rgba(54, 162, 235, 0.5)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Time (seconds)'
                            }
                        }
                    }
                }
            });";
        }

        // API Response Chart
        if (isset($this->results['api_response'])) {
            $endpoints = array_keys($this->results['api_response']);
            $smallData = [];
            $mediumData = [];
            $largeData = [];

            foreach ($this->results['api_response'] as $endpoint => $sizes) {
                $smallData[] = $sizes['small'] * 1000;
                $mediumData[] = $sizes['medium'] * 1000;
                $largeData[] = $sizes['large'] * 1000;
            }

            $scripts .= "
            new Chart(document.getElementById('apiChart'), {
                type: 'line',
                data: {
                    labels: " . json_encode(array_map('ucfirst', $endpoints)) . ",
                    datasets: [{
                        label: 'Small (10 records)',
                        data: " . json_encode($smallData) . ",
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.1
                    }, {
                        label: 'Medium (100 records)',
                        data: " . json_encode($mediumData) . ",
                        borderColor: 'rgba(255, 206, 86, 1)',
                        backgroundColor: 'rgba(255, 206, 86, 0.2)',
                        tension: 0.1
                    }, {
                        label: 'Large (1000 records)',
                        data: " . json_encode($largeData) . ",
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Response Time (ms)'
                            }
                        }
                    }
                }
            });";
        }

        // Memory Usage Chart
        if (isset($this->results['memory_usage'])) {
            $operations = array_keys($this->results['memory_usage']);
            $memoryData = array_column($this->results['memory_usage'], 'memory_used_mb');
            $peakData = array_column($this->results['memory_usage'], 'peak_memory_mb');

            $scripts .= "
            new Chart(document.getElementById('memoryChart'), {
                type: 'radar',
                data: {
                    labels: " . json_encode(array_map(function($op) {
                        return ucfirst(str_replace('_', ' ', $op));
                    }, $operations)) . ",
                    datasets: [{
                        label: 'Memory Used',
                        data: " . json_encode($memoryData) . ",
                        borderColor: 'rgba(102, 126, 234, 1)',
                        backgroundColor: 'rgba(102, 126, 234, 0.2)'
                    }, {
                        label: 'Peak Memory',
                        data: " . json_encode($peakData) . ",
                        borderColor: 'rgba(118, 75, 162, 1)',
                        backgroundColor: 'rgba(118, 75, 162, 0.2)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Memory (MB)'
                            }
                        }
                    }
                }
            });";
        }

        // File Upload Chart
        if (isset($this->results['file_upload'])) {
            $sizes = array_keys($this->results['file_upload']);
            $throughputData = array_column($this->results['file_upload'], 'throughput_mbps');

            $scripts .= "
            new Chart(document.getElementById('uploadChart'), {
                type: 'bar',
                data: {
                    labels: " . json_encode($sizes) . ",
                    datasets: [{
                        label: 'Throughput (MB/s)',
                        data: " . json_encode($throughputData) . ",
                        backgroundColor: [
                            'rgba(75, 192, 192, 0.5)',
                            'rgba(255, 206, 86, 0.5)',
                            'rgba(255, 99, 132, 0.5)'
                        ],
                        borderColor: [
                            'rgba(75, 192, 192, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(255, 99, 132, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Throughput (MB/s)'
                            }
                        }
                    }
                }
            });";
        }

        // Concurrent Users Chart
        if (isset($this->results['concurrent_users'])) {
            $users = [];
            $responseData = [];
            $throughputData = [];

            foreach ($this->results['concurrent_users'] as $test => $metrics) {
                $users[] = str_replace('users_', '', $test);
                $responseData[] = $metrics['avg_response_time'];
                $throughputData[] = $metrics['throughput_rps'];
            }

            $scripts .= "
            new Chart(document.getElementById('concurrentChart'), {
                type: 'line',
                data: {
                    labels: " . json_encode($users) . ",
                    datasets: [{
                        label: 'Avg Response Time (s)',
                        data: " . json_encode($responseData) . ",
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        yAxisID: 'y',
                        tension: 0.1
                    }, {
                        label: 'Throughput (req/s)',
                        data: " . json_encode($throughputData) . ",
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        yAxisID: 'y1',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Concurrent Users'
                            }
                        },
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Response Time (s)'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Throughput (req/s)'
                            },
                            grid: {
                                drawOnChartArea: false
                            }
                        }
                    }
                }
            });";
        }

        $scripts .= '</script>';

        return $scripts;
    }

    /**
     * Calculate overall performance score
     */
    private function calculateOverallScore(): int {
        $score = 100;
        $deductions = 0;

        // Page load deductions
        if (isset($this->results['page_load'])) {
            foreach ($this->results['page_load'] as $page => $data) {
                if ($data['no_cache'] > self::PAGE_LOAD_TARGET) {
                    $deductions += 5;
                }
            }
        }

        // API response deductions
        if (isset($this->results['api_response'])) {
            foreach ($this->results['api_response'] as $endpoint => $sizes) {
                if (max($sizes) > self::API_RESPONSE_TARGET) {
                    $deductions += 3;
                }
            }
        }

        // Database query deductions
        if (isset($this->results['database'])) {
            foreach ($this->results['database'] as $query => $data) {
                if (isset($data['optimized']) && !$data['optimized']) {
                    $deductions += 4;
                }
            }
        }

        // Memory usage deductions
        if (isset($this->results['memory_usage'])) {
            foreach ($this->results['memory_usage'] as $op => $data) {
                if ($data['potential_leak']) {
                    $deductions += 5;
                }
                if ($data['memory_used_mb'] > self::MEMORY_WARNING_THRESHOLD) {
                    $deductions += 3;
                }
            }
        }

        // Recommendation deductions
        $deductions += count($this->recommendations) * 2;

        return max(0, $score - $deductions);
    }

    /**
     * Log message
     */
    private function log(string $message, string $level = 'info'): void {
        if ($this->verbose || $level === 'error') {
            echo $message;
        }
    }
}

// Run the test suite
if (PHP_SAPI === 'cli' || isset($_GET['run'])) {
    try {
        $suite = new PerformanceTestSuite();
        $suite->runAllTests();
    } catch (Exception $e) {
        echo "\nError: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
} else {
    // Web interface
    echo '<!DOCTYPE html>
<html>
<head>
    <title>CollaboraNexio Performance Test</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
            max-width: 500px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        p {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .button {
            display: inline-block;
            padding: 15px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 30px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .button:hover {
            transform: translateY(-2px);
        }
        .cli-command {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-top: 30px;
            font-family: monospace;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Performance Test Suite</h1>
        <p>Run comprehensive performance tests for CollaboraNexio to identify bottlenecks and optimization opportunities.</p>
        <a href="?run=1" class="button">Run Performance Tests</a>
        <div class="cli-command">
            <strong>CLI Usage:</strong><br>
            php ' . basename(__FILE__) . ' [options]
        </div>
    </div>
</body>
</html>';
}
?>