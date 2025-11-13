<?php
/**
 * API: Ricerca Comuni Italiani (Autocomplete)
 *
 * Endpoint per ricerca as-you-type dei comuni italiani
 * Supporta filtro per provincia e ordinamento intelligente dei risultati
 *
 * Method: GET
 * Auth: NO - Public endpoint (read-only search)
 * CSRF: NO - GET request, read-only
 *
 * Query Parameters:
 * - q (required): Query di ricerca (minimo 2 caratteri)
 * - province (optional): Filtra per codice provincia (es. "RM", "MI")
 * - limit (optional): Numero massimo risultati (default: 20, max: 50)
 *
 * Response Format:
 * {
 *   "success": true,
 *   "data": {
 *     "results": [
 *       {
 *         "id": 1,
 *         "name": "Roma",
 *         "province_code": "RM",
 *         "province_name": "Roma",
 *         "region": "Lazio",
 *         "istat_code": "058091",
 *         "cadastral_code": "H501",
 *         "postal_code_prefix": "00100",
 *         "match_type": "exact"
 *       },
 *       ...
 *     ],
 *     "total": 1,
 *     "query": "Roma",
 *     "province_filter": "RM"
 *   },
 *   "message": "Trovato 1 comune"
 * }
 *
 * Match Types:
 * - exact: Nome esatto (case-insensitive)
 * - prefix: Nome inizia con query
 * - contains: Nome contiene query
 *
 * Performance Target: <100ms response time
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 */

declare(strict_types=1);

// Set JSON headers and error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

// Load dependencies
require_once __DIR__ . '/../../includes/api_response.php';
require_once __DIR__ . '/../../includes/db.php';

/**
 * Clean output buffer and send JSON success response
 */
function sendSuccess($data, string $message): void {
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Clean output buffer and send JSON error response
 */
function sendError(string $message, int $httpCode = 400): void {
    ob_clean();
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => $message
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $startTime = microtime(true);

    // Validate HTTP method
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Metodo non consentito. Usare GET.', 405);
    }

    // Get and validate parameters
    $query = $_GET['q'] ?? null;
    $provinceFilter = isset($_GET['province']) ? strtoupper(trim($_GET['province'])) : null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

    // Validation: required query parameter
    if (empty($query)) {
        sendError('Parametro "q" richiesto (query di ricerca)', 400);
    }

    // Normalize query
    $query = trim($query);

    // Validate minimum query length
    if (mb_strlen($query) < 2) {
        sendError('La query deve contenere almeno 2 caratteri', 400);
    }

    // Validate limit range
    if ($limit < 1) {
        $limit = 20;
    }
    if ($limit > 50) {
        $limit = 50;
    }

    // Get database connection
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Validate province filter if provided
    if ($provinceFilter !== null) {
        $stmt = $conn->prepare("SELECT code FROM italian_provinces WHERE code = ? LIMIT 1");
        $stmt->execute([$provinceFilter]);
        if (!$stmt->fetch()) {
            sendError('Codice provincia non valido: ' . $provinceFilter, 400);
        }
    }

    // Build search query
    // Search pattern for LIKE queries
    $searchPattern = '%' . $query . '%';
    $startsWithPattern = $query . '%';

    // Build SQL with optional province filter
    $sql = "
        SELECT
            m.id,
            m.name,
            m.province_code,
            m.istat_code,
            m.cadastral_code,
            m.postal_code_prefix,
            p.name as province_name,
            p.region,
            CASE
                WHEN LOWER(m.name) = LOWER(?) THEN 'exact'
                WHEN LOWER(m.name) LIKE LOWER(?) THEN 'prefix'
                ELSE 'contains'
            END as match_type,
            CASE
                WHEN LOWER(m.name) = LOWER(?) THEN 1
                WHEN LOWER(m.name) LIKE LOWER(?) THEN 2
                ELSE 3
            END as sort_order
        FROM italian_municipalities m
        JOIN italian_provinces p ON m.province_code = p.code
        WHERE LOWER(m.name) LIKE LOWER(?)
    ";

    $params = [
        $query,              // exact match check
        $startsWithPattern,  // prefix match check
        $query,              // exact match sort
        $startsWithPattern,  // prefix match sort
        $searchPattern       // LIKE search
    ];

    // Add province filter if specified
    if ($provinceFilter !== null) {
        $sql .= " AND m.province_code = ?";
        $params[] = $provinceFilter;
    }

    $sql .= "
        ORDER BY sort_order ASC, m.name ASC
        LIMIT ?
    ";
    $params[] = $limit;

    // Execute search
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format results
    $formattedResults = array_map(function($item) {
        return [
            'id' => (int)$item['id'],
            'name' => $item['name'],
            'province_code' => $item['province_code'],
            'province_name' => $item['province_name'],
            'region' => $item['region'],
            'istat_code' => $item['istat_code'],
            'cadastral_code' => $item['cadastral_code'],
            'postal_code_prefix' => $item['postal_code_prefix'],
            'match_type' => $item['match_type']
        ];
    }, $results);

    $total = count($formattedResults);
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);

    // Prepare response
    $responseData = [
        'results' => $formattedResults,
        'total' => $total,
        'query' => $query,
        'province_filter' => $provinceFilter,
        '_meta' => [
            'execution_time_ms' => $executionTime,
            'limit' => $limit,
            'version' => '1.0.0'
        ]
    ];

    // Generate message
    if ($total === 0) {
        $message = $provinceFilter
            ? "Nessun comune trovato per '{$query}' nella provincia {$provinceFilter}"
            : "Nessun comune trovato per '{$query}'";
    } elseif ($total === 1) {
        $message = "Trovato 1 comune";
    } else {
        $message = "Trovati {$total} comuni";
        if ($total === $limit) {
            $message .= " (limitati a {$limit})";
        }
    }

    sendSuccess($responseData, $message);

} catch (PDOException $e) {
    // Log database errors
    error_log(sprintf(
        '[%s] Database Error in locations/search_municipalities: %s',
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));

    sendError('Errore database. Riprova più tardi.', 500);

} catch (Exception $e) {
    // Log general errors
    error_log(sprintf(
        '[%s] API Error in locations/search_municipalities: %s',
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));

    sendError('Errore interno del server', 500);
}
