<?php
/**
 * Italian location distance helpers (offline).
 *
 * Goals:
 * - No external runtime dependencies (no external geocoding/routing APIs)
 * - Best-effort KM estimation for travel planning:
 *   - Uses province centroid coordinates dataset (offline CSV) when lat/lng are not available.
 *   - Supports parsing "City (PR)" forms to infer province code.
 *
 * Data source (offline, vendored):
 * - includes/data/province_centroids_ddmmss.csv
 *   (province centroid coordinates in DMS format; converted to decimal degrees here)
 */
declare(strict_types=1);

require_once __DIR__ . '/locations_municipalities.php';

/**
 * Parse a DMS coordinate (e.g. "7°26′20.5″E") into decimal degrees.
 */
function cnx_locations_parse_dms_to_decimal(string $dms): ?float
{
    $s = trim($dms);
    if ($s === '') return null;

    // Match: degrees, minutes, seconds, direction (N/S/E/W)
    // Works with unicode symbols (°, ′, ″) and also tolerates other separators.
    if (!preg_match('/^\s*([0-9]{1,3})\D+([0-9]{1,2})\D+([0-9]{1,2}(?:\.[0-9]+)?)\D*([NSEW])\s*$/u', $s, $m)) {
        return null;
    }

    $deg = (int)$m[1];
    $min = (int)$m[2];
    $sec = (float)$m[3];
    $dir = strtoupper((string)$m[4]);

    if ($deg < 0 || $min < 0 || $sec < 0) return null;

    $val = (float)$deg + ((float)$min / 60.0) + ((float)$sec / 3600.0);
    if (in_array($dir, ['S', 'W'], true)) $val *= -1.0;
    return $val;
}

/**
 * Load province centroid coordinates (sigla -> [lat,lng,name,region]).
 *
 * @return array<string,array{lat:float,lng:float,name:string,region:string}>
 */
function cnx_locations_province_centroids(): array
{
    static $cache = null;
    if (is_array($cache)) return $cache;

    $cache = [];
    $file = __DIR__ . '/data/province_centroids_ddmmss.csv';
    if (!is_file($file)) return $cache;

    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return $cache;

    $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
    if (count($lines) < 2) return $cache;

    $header = str_getcsv((string)array_shift($lines));
    $idx = [];
    foreach ($header as $i => $h) {
        $k = trim((string)$h);
        if ($k !== '') $idx[$k] = (int)$i;
    }

    $siglaI = $idx['sigla'] ?? null;
    $nameI = $idx['den_uts'] ?? null;
    $regI = $idx['den_reg'] ?? null;
    $lngI = $idx['long'] ?? null;
    $latI = $idx['lat'] ?? null;
    if ($siglaI === null || $lngI === null || $latI === null) return $cache;

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $cols = str_getcsv($line);
        if (!is_array($cols) || count($cols) < max($siglaI, $lngI, $latI) + 1) continue;

        $code = strtoupper(trim((string)($cols[$siglaI] ?? '')));
        if ($code === '' || strlen($code) !== 2) continue;

        $lngDms = (string)($cols[$lngI] ?? '');
        $latDms = (string)($cols[$latI] ?? '');
        $lng = cnx_locations_parse_dms_to_decimal($lngDms);
        $lat = cnx_locations_parse_dms_to_decimal($latDms);
        if ($lng === null || $lat === null) continue;

        $cache[$code] = [
            'lat' => $lat,
            'lng' => $lng,
            'name' => $nameI !== null ? (string)($cols[$nameI] ?? '') : '',
            'region' => $regI !== null ? (string)($cols[$regI] ?? '') : '',
        ];
    }

    return $cache;
}

/**
 * Extract province code from a freeform city string:
 * - "Milano (MI)" => "MI"
 * - "Milano, MI" => "MI"
 */
function cnx_locations_extract_province_code_from_city(string $city): ?string
{
    $s = strtoupper(trim($city));
    if ($s === '') return null;
    if (preg_match('/\(([A-Z]{2})\)\s*$/', $s, $m)) return (string)$m[1];
    if (preg_match('/,\s*([A-Z]{2})\s*$/', $s, $m)) return (string)$m[1];
    return null;
}

/**
 * Best-effort: infer province code from municipality name using italian_municipalities table (if present).
 */
function cnx_locations_infer_province_code(Database $db, string $city): ?string
{
    $city = cnx_locations_normalize_city_input($city);
    if ($city === '') return null;
    if (!cnx_locations_has_italian_tables($db)) return null;

    try {
        $rows = $db->fetchAll(
            "SELECT DISTINCT province_code
             FROM italian_municipalities
             WHERE LOWER(name) = LOWER(?)
             LIMIT 2",
            [$city]
        ) ?: [];
        if (count($rows) === 1) {
            $pc = strtoupper(trim((string)($rows[0]['province_code'] ?? '')));
            return (strlen($pc) === 2) ? $pc : null;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

function cnx_locations_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0; // earth radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $r * $c;
}

/**
 * Estimate road km between two provinces using centroids (offline).
 *
 * @return float|null
 */
function cnx_locations_estimate_road_km_by_province(
    Database $db,
    string $fromProvince,
    string $toProvince,
    bool $roundTrip = true,
    float $roadFactor = 1.25
): ?float {
    $fromProvince = strtoupper(trim($fromProvince));
    $toProvince = strtoupper(trim($toProvince));
    if (strlen($fromProvince) !== 2 || strlen($toProvince) !== 2) return null;

    $centroids = cnx_locations_province_centroids();
    $a = $centroids[$fromProvince] ?? null;
    $b = $centroids[$toProvince] ?? null;
    if (!is_array($a) || !is_array($b)) return null;

    $km = cnx_locations_haversine_km((float)$a['lat'], (float)$a['lng'], (float)$b['lat'], (float)$b['lng']);
    if ($roadFactor < 1.0) $roadFactor = 1.0;
    $km *= $roadFactor;
    if ($roundTrip) $km *= 2.0;
    return round($km, 1);
}

