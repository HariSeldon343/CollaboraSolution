<?php
/**
 * API: Lista Province
 *
 * Restituisce l'elenco completo delle province italiane con codice e nome
 *
 * Method: GET
 * Auth: NO - Public endpoint (static data)
 * CSRF: NO - GET request, read-only
 *
 * Query Parameters:
 * - format (optional): 'full' (default) o 'simple'
 *   - full: Restituisce oggetti con code e name
 *   - simple: Restituisce solo array di codici
 *
 * Response Format (format=full):
 * {
 *   "success": true,
 *   "data": {
 *     "provinces": [
 *       {"code": "AG", "name": "Agrigento"},
 *       {"code": "AL", "name": "Alessandria"}
 *     ],
 *     "total": 110
 *   },
 *   "message": "Lista province recuperata con successo"
 * }
 *
 * Response Format (format=simple):
 * {
 *   "success": true,
 *   "data": {
 *     "provinces": ["AG", "AL", "AN", ...],
 *     "total": 110
 *   },
 *   "message": "Lista province recuperata con successo"
 * }
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
header('Cache-Control: public, max-age=86400'); // Cache for 24 hours (very static data)

// Load dependencies
require_once __DIR__ . '/../../includes/italian_provinces.php';

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
    // Validate HTTP method
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendError('Metodo non consentito. Usare GET.', 405);
    }

    // Get format parameter
    $format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'full';

    // Validate format
    if (!in_array($format, ['full', 'simple'], true)) {
        sendError('Formato non valido. Usare "full" o "simple".', 400);
    }

    // Get provinces data
    $provincesData = getItalianProvinces();

    // Format response based on requested format
    if ($format === 'simple') {
        // Simple format: just array of codes
        $provinces = array_keys($provincesData);
        sort($provinces); // Sort alphabetically by code
    } else {
        // Full format: array of objects with code and name
        $provinces = [];
        foreach ($provincesData as $code => $name) {
            $provinces[] = [
                'code' => $code,
                'name' => $name
            ];
        }

        // Sort by code
        usort($provinces, function($a, $b) {
            return strcmp($a['code'], $b['code']);
        });
    }

    // Prepare response
    $responseData = [
        'provinces' => $provinces,
        'total' => count($provinces)
    ];

    // Add metadata for full format
    if ($format === 'full') {
        $responseData['metadata'] = [
            'format' => 'full',
            'source' => 'ISTAT',
            'last_updated' => '2025-01-01'
        ];
    }

    sendSuccess($responseData, 'Lista province recuperata con successo');

} catch (Exception $e) {
    error_log(sprintf(
        '[%s] API Error in locations/list_provinces: %s',
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));

    sendError('Errore interno del server', 500);
}
