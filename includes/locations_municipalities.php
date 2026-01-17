<?php
/**
 * Italian municipalities helper (read-only).
 *
 * Used to validate that a city/comune exists in the Italian reference dataset
 * (tables: italian_municipalities + italian_provinces).
 *
 * NOTE:
 * - These tables are system reference data (no tenant_id).
 * - This helper is schema-drift safe: if tables are missing, it degrades gracefully.
 */
declare(strict_types=1);

/**
 * Return true if the Italian locations tables are available.
 */
function cnx_locations_has_italian_tables(Database $db): bool
{
    try {
        $hasM = (bool)$db->fetchOne("SHOW TABLES LIKE 'italian_municipalities'");
        $hasP = (bool)$db->fetchOne("SHOW TABLES LIKE 'italian_provinces'");
        return $hasM && $hasP;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Best-effort normalize a city string coming from UI.
 * - trims
 * - collapses multiple spaces
 * - strips trailing province in parentheses: "Milano (MI)" -> "Milano"
 */
function cnx_locations_normalize_city_input(string $city): string
{
    $v = trim($city);
    $v = preg_replace('/\s+/', ' ', $v) ?? $v;
    if (preg_match('/^(.*)\s*\([A-Z]{2}\)\s*$/u', $v, $m)) {
        $v = trim((string)($m[1] ?? $v));
    }
    return $v;
}

/**
 * Return true if at least one municipality with this name exists (case-insensitive).
 */
function cnx_locations_city_exists(Database $db, string $city): bool
{
    $city = cnx_locations_normalize_city_input($city);
    if ($city === '') return false;
    if (!cnx_locations_has_italian_tables($db)) return true; // degrade gracefully when reference tables are missing

    try {
        $row = $db->fetchOne(
            "SELECT 1 AS ok
             FROM italian_municipalities
             WHERE LOWER(name) = LOWER(?)
             LIMIT 1",
            [$city]
        );
        return (bool)($row['ok'] ?? false);
    } catch (Throwable $e) {
        // If reference DB is temporarily unavailable, don't block writes
        return true;
    }
}

/**
 * Return suggestions for a city query (used when validation fails).
 *
 * @return array<int, array{name:string, province_code:string, province_name:string, region:string}>
 */
function cnx_locations_city_suggestions(Database $db, string $query, int $limit = 5): array
{
    $query = cnx_locations_normalize_city_input($query);
    if ($query === '') return [];
    if ($limit < 1) $limit = 5;
    if ($limit > 20) $limit = 20;
    if (!cnx_locations_has_italian_tables($db)) return [];

    try {
        $searchPattern = '%' . $query . '%';
        $startsWithPattern = $query . '%';

        $rows = $db->fetchAll(
            "SELECT
                m.name,
                m.province_code,
                p.name AS province_name,
                p.region
             FROM italian_municipalities m
             JOIN italian_provinces p ON p.code = m.province_code
             WHERE LOWER(m.name) LIKE LOWER(?)
             ORDER BY
                CASE
                    WHEN LOWER(m.name) = LOWER(?) THEN 1
                    WHEN LOWER(m.name) LIKE LOWER(?) THEN 2
                    ELSE 3
                END,
                m.name ASC
             LIMIT " . (int)$limit,
            [$searchPattern, $query, $startsWithPattern]
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $name = (string)($r['name'] ?? '');
            $pc = (string)($r['province_code'] ?? '');
            if ($name === '' || $pc === '') continue;
            $out[] = [
                'name' => $name,
                'province_code' => $pc,
                'province_name' => (string)($r['province_name'] ?? ''),
                'region' => (string)($r['region'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Return true if municipality name belongs to the given province code (case-insensitive).
 */
function cnx_locations_city_in_province_exists(Database $db, string $city, string $provinceCode): bool
{
    $city = cnx_locations_normalize_city_input($city);
    $provinceCode = strtoupper(trim($provinceCode));
    if ($city === '' || $provinceCode === '' || strlen($provinceCode) !== 2) return false;
    if (!cnx_locations_has_italian_tables($db)) return true; // degrade gracefully when reference tables are missing

    try {
        $row = $db->fetchOne(
            "SELECT 1 AS ok
             FROM italian_municipalities
             WHERE LOWER(name) = LOWER(?)
               AND province_code = ?
             LIMIT 1",
            [$city, $provinceCode]
        );
        return (bool)($row['ok'] ?? false);
    } catch (Throwable $e) {
        return true;
    }
}

