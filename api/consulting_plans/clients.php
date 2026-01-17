<?php
// GET: list allowed client tenants for planning tool
require_once __DIR__ . '/_common.php';

try {
    $clients = cnx_consulting_allowed_clients($db, $userInfo);
    if (!$clients) {
        api_success(['clients' => []]);
    }

    // Enrich clients with "aziende.php" data (best-effort) for Planning Estimate Wizard prefill.
    $ids = [];
    foreach ($clients as $c) {
        $id = (int)($c['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    if (empty($ids)) {
        api_success(['clients' => $clients]);
    }

    // Detect available columns on tenants (schema-drift safe)
    $tenantColsRows = $db->fetchAll(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'tenants'"
    ) ?: [];
    $tenantCols = [];
    foreach ($tenantColsRows as $r) {
        $k = (string)($r['COLUMN_NAME'] ?? '');
        if ($k !== '') $tenantCols[$k] = true;
    }

    $select = "SELECT id";
    if (!empty($tenantCols['settore_merceologico'])) $select .= ", settore_merceologico";
    if (!empty($tenantCols['numero_dipendenti'])) $select .= ", numero_dipendenti";
    if (!empty($tenantCols['sedi_operative'])) $select .= ", sedi_operative";
    // Optional: website/domain (not always present in UI, but useful for planning wizard prefill)
    if (!empty($tenantCols['website'])) $select .= ", website";
    if (!empty($tenantCols['domain'])) $select .= ", domain";

    // Prefer tenant_locations count when available (more reliable than JSON legacy)
    $hasTenantLocations = (bool)$db->fetchOne("SHOW TABLES LIKE 'tenant_locations'");
    if ($hasTenantLocations) {
        $select .= ",
            (SELECT COUNT(*)
             FROM tenant_locations
             WHERE tenant_id = tenants.id
               AND location_type = 'sede_operativa'
               AND deleted_at IS NULL
               AND is_active = 1) AS sedi_operative_count";
    }

    $where = "id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
    if (!empty($tenantCols['deleted_at'])) $where = "deleted_at IS NULL AND " . $where;
    $select .= " FROM tenants WHERE " . $where;

    $tenantRows = $db->fetchAll($select, $ids) ?: [];
    $byId = [];
    foreach ($tenantRows as $r) {
        $byId[(int)($r['id'] ?? 0)] = $r;
    }

    foreach ($clients as &$c) {
        $id = (int)($c['id'] ?? 0);
        $row = $byId[$id] ?? null;
        if (!$row) continue;

        $c['settore_merceologico'] = !empty($tenantCols['settore_merceologico']) ? (($row['settore_merceologico'] ?? null) ?: null) : null;
        $c['numero_dipendenti'] = !empty($tenantCols['numero_dipendenti']) ? (((int)($row['numero_dipendenti'] ?? 0)) > 0 ? (int)$row['numero_dipendenti'] : null) : null;
        // Website/domain best-effort (used by planning wizard "Sito web" field)
        $web = null;
        if (!empty($tenantCols['website'])) {
            $t = trim((string)($row['website'] ?? ''));
            $web = ($t !== '') ? $t : null;
        }
        if ($web === null && !empty($tenantCols['domain'])) {
            $t = trim((string)($row['domain'] ?? ''));
            $web = ($t !== '') ? $t : null;
        }
        $c['website'] = $web;
        $c['domain'] = (!empty($tenantCols['domain']) && isset($row['domain'])) ? (($row['domain'] ?? null) ?: null) : ($c['domain'] ?? null);

        $opCount = null;
        if (isset($row['sedi_operative_count'])) {
            $opCount = (int)$row['sedi_operative_count'];
        }
        if ($opCount === null && !empty($tenantCols['sedi_operative']) && isset($row['sedi_operative']) && $row['sedi_operative'] !== null && $row['sedi_operative'] !== '') {
            try {
                $decoded = json_decode((string)$row['sedi_operative'], true);
                if (is_array($decoded)) $opCount = count($decoded);
            } catch (Throwable $e) {
                $opCount = null;
            }
        }
        $c['sedi_operative_count'] = ($opCount !== null) ? (int)$opCount : null;
        $c['sites_count'] = ($opCount !== null) ? ((int)$opCount + 1) : null; // include sede legale
    }
    unset($c);

    api_success(['clients' => $clients]);
} catch (Exception $e) {
    error_log('[CONSULTING_CLIENTS] ' . $e->getMessage());
    api_error('Errore caricamento aziende', 500);
}


