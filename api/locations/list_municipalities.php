<?php
/**
 * API: Lista Comuni
 *
 * Restituisce l'elenco dei comuni, opzionalmente filtrati per provincia
 *
 * Method: GET
 * Auth: NO - Public endpoint (static data)
 * CSRF: NO - GET request, read-only
 *
 * Query Parameters:
 * - province (optional): Sigla provincia per filtrare i comuni (es. "RM", "MI")
 * - search (optional): Cerca comuni per nome (partial match, case-insensitive)
 * - limit (optional): Limita numero risultati (default: 100, max: 500)
 *
 * Response Format:
 * {
 *   "success": true,
 *   "data": {
 *     "municipalities": [
 *       {"name": "Roma", "province": "RM", "province_name": "Roma"},
 *       {"name": "Milano", "province": "MI", "province_name": "Milano"}
 *     ],
 *     "total": 2,
 *     "filters": {
 *       "province": "RM",
 *       "search": null,
 *       "limit": 100
 *     }
 *   },
 *   "message": "Lista comuni recuperata con successo"
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
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour (static data)

// Load dependencies
require_once __DIR__ . '/../../includes/italian_provinces.php';
require_once __DIR__ . '/municipalities_data.php';

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

    // Get parameters
    $provinceFilter = isset($_GET['province']) ? strtoupper(trim($_GET['province'])) : null;
    $searchQuery = isset($_GET['search']) ? trim($_GET['search']) : null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

    // Validate limit
    if ($limit < 1) {
        $limit = 100;
    } elseif ($limit > 500) {
        $limit = 500; // Max limit to prevent performance issues
    }

    // Get province data
    $provinces = getItalianProvinces();

    // Validate province code if provided
    if ($provinceFilter && !isset($provinces[$provinceFilter])) {
        sendError('Codice provincia non valido: ' . $provinceFilter, 400);
    }

    // Get municipalities data
    $municipalitiesData = getItalianMunicipalities();
    $results = [];

    // If province filter is set, get only that province's municipalities
    if ($provinceFilter) {
        if (isset($municipalitiesData[$provinceFilter])) {
            foreach ($municipalitiesData[$provinceFilter] as $municipality) {
                $results[] = [
                    'name' => $municipality,
                    'province' => $provinceFilter,
                    'province_name' => $provinces[$provinceFilter]
                ];
            }
        }
    }
    // If search query is provided
    elseif ($searchQuery) {
        $searchLower = strtolower($searchQuery);
        $count = 0;

        foreach ($municipalitiesData as $provinceCode => $municipalities) {
            if ($count >= $limit) {
                break;
            }

            foreach ($municipalities as $municipality) {
                if ($count >= $limit) {
                    break;
                }

                // Case-insensitive partial match
                if (stripos($municipality, $searchQuery) !== false) {
                    $results[] = [
                        'name' => $municipality,
                        'province' => $provinceCode,
                        'province_name' => $provinces[$provinceCode]
                    ];
                    $count++;
                }
            }
        }
    }
    // No filters - return all municipalities (with limit)
    else {
        $count = 0;

        foreach ($municipalitiesData as $provinceCode => $municipalities) {
            if ($count >= $limit) {
                break;
            }

            foreach ($municipalities as $municipality) {
                if ($count >= $limit) {
                    break;
                }

                $results[] = [
                    'name' => $municipality,
                    'province' => $provinceCode,
                    'province_name' => $provinces[$provinceCode]
                ];
                $count++;
            }
        }
    }

    // Sort by municipality name
    usort($results, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    // Prepare response
    $responseData = [
        'municipalities' => $results,
        'total' => count($results),
        'filters' => [
            'province' => $provinceFilter,
            'search' => $searchQuery,
            'limit' => $limit
        ]
    ];

    sendSuccess($responseData, 'Lista comuni recuperata con successo');

} catch (Exception $e) {
    error_log(sprintf(
        '[%s] API Error in locations/list_municipalities: %s',
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));

    sendError('Errore interno del server', 500);
}
