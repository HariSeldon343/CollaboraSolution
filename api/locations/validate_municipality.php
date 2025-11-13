<?php
/**
 * API: Validazione Comune-Provincia v2.0.0
 *
 * Valida che un comune appartenga effettivamente a una provincia specifica
 * Versione 2.0.0: Database completo 7,895 comuni italiani
 *
 * Method: GET
 * Auth: NO - Public endpoint (validation only)
 * CSRF: NO - GET request, read-only
 *
 * Query Parameters:
 * - municipality (required): Nome del comune (es. "Roma")
 * - province (required): Sigla della provincia (es. "RM")
 *
 * Response Format (valid):
 * {
 *   "success": true,
 *   "data": {
 *     "valid": true,
 *     "municipality": {
 *       "id": 1,
 *       "istat_code": "058091",
 *       "name": "Roma",
 *       "province_code": "RM",
 *       "cadastral_code": "H501",
 *       "postal_code_prefix": "00100"
 *     },
 *     "province": {
 *       "code": "RM",
 *       "name": "Roma",
 *       "region": "Lazio"
 *     }
 *   },
 *   "message": "Comune valido per la provincia specificata"
 * }
 *
 * Response Format (invalid with suggestions):
 * {
 *   "success": true,
 *   "data": {
 *     "valid": false,
 *     "searched": "Rma",
 *     "province": "RM",
 *     "suggestions": [
 *       {"name": "Roma", "istat_code": "058091", "cadastral_code": "H501"},
 *       ...
 *     ]
 *   },
 *   "message": "Comune non trovato. Verifica il nome o seleziona un suggerimento"
 * }
 *
 * @author CollaboraNexio Development Team
 * @version 2.0.0 - Database Edition
 */

declare(strict_types=1);

// Set JSON headers and error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour (static data)

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
    $municipality = $_GET['municipality'] ?? null;
    $province = $_GET['province'] ?? null;

    // Validation: required parameters
    if (empty($municipality)) {
        sendError('Parametro "municipality" richiesto', 400);
    }

    if (empty($province)) {
        sendError('Parametro "province" richiesto', 400);
    }

    // Normalize inputs
    $municipality = trim($municipality);
    $province = strtoupper(trim($province));

    // Get database connection
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Step 1: Validate province code exists
    $stmt = $conn->prepare("
        SELECT code, name, region
        FROM italian_provinces
        WHERE code = ?
        LIMIT 1
    ");
    $stmt->execute([$province]);
    $provinceData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$provinceData) {
        sendError('Codice provincia non valido: ' . $province, 400);
    }

    // Step 2: Try exact match (case-insensitive, accent-tolerant using LOWER)
    $stmt = $conn->prepare("
        SELECT
            id,
            istat_code,
            name,
            province_code,
            cadastral_code,
            postal_code_prefix
        FROM italian_municipalities
        WHERE LOWER(name) = LOWER(?)
        AND province_code = ?
        LIMIT 1
    ");
    $stmt->execute([$municipality, $province]);
    $matchedMunicipality = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($matchedMunicipality) {
        // VALID: Exact match found
        $responseData = [
            'valid' => true,
            'municipality' => [
                'id' => (int)$matchedMunicipality['id'],
                'istat_code' => $matchedMunicipality['istat_code'],
                'name' => $matchedMunicipality['name'],
                'province_code' => $matchedMunicipality['province_code'],
                'cadastral_code' => $matchedMunicipality['cadastral_code'],
                'postal_code_prefix' => $matchedMunicipality['postal_code_prefix']
            ],
            'province' => [
                'code' => $provinceData['code'],
                'name' => $provinceData['name'],
                'region' => $provinceData['region']
            ]
        ];

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        $responseData['_meta'] = [
            'execution_time_ms' => $executionTime,
            'version' => '2.0.0'
        ];

        sendSuccess($responseData, 'Comune valido per la provincia specificata');
    }

    // Step 3: INVALID - Find similar suggestions using LIKE
    $stmt = $conn->prepare("
        SELECT
            name,
            istat_code,
            cadastral_code,
            postal_code_prefix
        FROM italian_municipalities
        WHERE LOWER(name) LIKE LOWER(?)
        AND province_code = ?
        ORDER BY
            CASE
                WHEN LOWER(name) = LOWER(?) THEN 1
                WHEN LOWER(name) LIKE LOWER(?) THEN 2
                ELSE 3
            END,
            name ASC
        LIMIT 5
    ");

    $searchPattern = '%' . $municipality . '%';
    $startsWithPattern = $municipality . '%';
    $stmt->execute([
        $searchPattern,
        $province,
        $municipality,
        $startsWithPattern
    ]);
    $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format suggestions
    $formattedSuggestions = array_map(function($item) {
        return [
            'name' => $item['name'],
            'istat_code' => $item['istat_code'],
            'cadastral_code' => $item['cadastral_code'],
            'postal_code_prefix' => $item['postal_code_prefix']
        ];
    }, $suggestions);

    $responseData = [
        'valid' => false,
        'searched' => $municipality,
        'province' => [
            'code' => $provinceData['code'],
            'name' => $provinceData['name'],
            'region' => $provinceData['region']
        ],
        'suggestions' => $formattedSuggestions
    ];

    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    $responseData['_meta'] = [
        'execution_time_ms' => $executionTime,
        'suggestions_count' => count($formattedSuggestions),
        'version' => '2.0.0'
    ];

    $message = empty($formattedSuggestions)
        ? 'Nessun comune trovato nella provincia ' . $provinceData['name']
        : 'Comune non trovato. Verifica il nome o seleziona un suggerimento';

    sendSuccess($responseData, $message);

} catch (PDOException $e) {
    // Log database errors
    error_log(sprintf(
        '[%s] Database Error in locations/validate_municipality: %s',
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));

    sendError('Errore database. Riprova più tardi.', 500);

} catch (Exception $e) {
    // Log general errors
    error_log(sprintf(
        '[%s] API Error in locations/validate_municipality: %s',
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));

    sendError('Errore interno del server', 500);
}
