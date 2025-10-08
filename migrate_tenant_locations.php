<?php
/**
 * Tenant Locations Migration Helper
 *
 * This script helps migrate existing JSON sedi_operative data
 * from tenants table to the new tenant_locations table
 *
 * Run this AFTER executing tenant_locations_schema.sql
 *
 * @author Database Architect
 * @version 1.0.0
 * @date 2025-10-07
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

// Configurazione
$DRY_RUN = true; // Set to false to actually perform migration
$VERBOSE = true;

// Output helper
function output(string $message, string $type = 'info'): void {
    $colors = [
        'success' => "\033[32m",
        'error' => "\033[31m",
        'warning' => "\033[33m",
        'info' => "\033[36m",
        'reset' => "\033[0m"
    ];

    $prefix = [
        'success' => '[OK]',
        'error' => '[ERROR]',
        'warning' => '[WARN]',
        'info' => '[INFO]'
    ];

    echo $colors[$type] . $prefix[$type] . " " . $message . $colors['reset'] . PHP_EOL;
}

// Main execution
try {
    output("===========================================", 'info');
    output("TENANT LOCATIONS MIGRATION HELPER", 'info');
    output("===========================================", 'info');
    output("", 'info');

    if ($DRY_RUN) {
        output("DRY RUN MODE: No data will be modified", 'warning');
    } else {
        output("LIVE MODE: Data will be migrated", 'success');
    }

    output("", 'info');

    // Get database connection
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Check if tenant_locations table exists
    $tableExists = $db->fetchOne(
        "SELECT COUNT(*) as count
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'tenant_locations'"
    );

    if (!$tableExists || $tableExists['count'] == 0) {
        output("ERROR: tenant_locations table does not exist!", 'error');
        output("Please run tenant_locations_schema.sql first", 'error');
        exit(1);
    }

    output("tenant_locations table found", 'success');
    output("", 'info');

    // Get all tenants with JSON sedi_operative data
    $tenants = $db->fetchAll(
        "SELECT id, denominazione, sedi_operative
         FROM tenants
         WHERE sedi_operative IS NOT NULL
         AND sedi_operative != ''
         AND sedi_operative != '[]'
         AND deleted_at IS NULL"
    );

    if (empty($tenants)) {
        output("No tenants with JSON sedi_operative data found", 'info');
        output("Migration not needed", 'success');
        exit(0);
    }

    output("Found " . count($tenants) . " tenants with JSON sedi_operative data", 'info');
    output("", 'info');

    $stats = [
        'total_tenants' => count($tenants),
        'successful_tenants' => 0,
        'failed_tenants' => 0,
        'total_locations_migrated' => 0,
        'errors' => []
    ];

    // Process each tenant
    foreach ($tenants as $tenant) {
        output("Processing tenant: {$tenant['denominazione']} (ID: {$tenant['id']})", 'info');

        try {
            // Decode JSON
            $sedi = json_decode($tenant['sedi_operative'], true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON: " . json_last_error_msg());
            }

            if (!is_array($sedi) || empty($sedi)) {
                output("  No valid sedi operative data", 'warning');
                continue;
            }

            output("  Found " . count($sedi) . " sedi operative in JSON", 'info');

            // Process each sede operativa
            foreach ($sedi as $index => $sede) {
                $sedeNum = $index + 1;

                // Validate required fields
                $requiredFields = ['indirizzo', 'civico', 'cap', 'comune', 'provincia'];
                $missingFields = [];

                foreach ($requiredFields as $field) {
                    if (empty($sede[$field])) {
                        $missingFields[] = $field;
                    }
                }

                if (!empty($missingFields)) {
                    $error = "Sede #{$sedeNum} missing fields: " . implode(', ', $missingFields);
                    output("  {$error}", 'warning');
                    $stats['errors'][] = "Tenant {$tenant['id']}: {$error}";
                    continue;
                }

                // Prepare location data
                $locationData = [
                    'tenant_id' => $tenant['id'],
                    'location_type' => 'sede_operativa',
                    'indirizzo' => trim($sede['indirizzo']),
                    'civico' => trim($sede['civico']),
                    'cap' => trim($sede['cap']),
                    'comune' => trim($sede['comune']),
                    'provincia' => strtoupper(trim($sede['provincia'])),
                    'telefono' => !empty($sede['telefono']) ? trim($sede['telefono']) : null,
                    'email' => !empty($sede['email']) ? trim($sede['email']) : null,
                    'manager_nome' => !empty($sede['manager_nome']) ? trim($sede['manager_nome']) : null,
                    'note' => !empty($sede['note']) ? trim($sede['note']) : null,
                    'is_primary' => false,
                    'is_active' => true
                ];

                // Check if location already exists
                $exists = $db->fetchOne(
                    "SELECT id FROM tenant_locations
                     WHERE tenant_id = ?
                     AND location_type = 'sede_operativa'
                     AND indirizzo = ?
                     AND civico = ?
                     AND comune = ?
                     AND deleted_at IS NULL",
                    [
                        $locationData['tenant_id'],
                        $locationData['indirizzo'],
                        $locationData['civico'],
                        $locationData['comune']
                    ]
                );

                if ($exists) {
                    output("  Sede #{$sedeNum} already exists (ID: {$exists['id']}) - skipping", 'warning');
                    continue;
                }

                // Insert location
                if (!$DRY_RUN) {
                    try {
                        $locationId = $db->insert('tenant_locations', $locationData);
                        output("  Sede #{$sedeNum} migrated successfully (ID: {$locationId})", 'success');
                        $stats['total_locations_migrated']++;
                    } catch (Exception $e) {
                        $error = "Failed to insert sede #{$sedeNum}: " . $e->getMessage();
                        output("  {$error}", 'error');
                        $stats['errors'][] = "Tenant {$tenant['id']}: {$error}";
                    }
                } else {
                    output("  [DRY RUN] Would migrate sede #{$sedeNum}: {$locationData['indirizzo']} {$locationData['civico']}, {$locationData['comune']} ({$locationData['provincia']})", 'info');
                    $stats['total_locations_migrated']++;
                }
            }

            $stats['successful_tenants']++;

        } catch (Exception $e) {
            output("  ERROR: " . $e->getMessage(), 'error');
            $stats['failed_tenants']++;
            $stats['errors'][] = "Tenant {$tenant['id']}: " . $e->getMessage();
        }

        output("", 'info');
    }

    // Summary
    output("===========================================", 'info');
    output("MIGRATION SUMMARY", 'info');
    output("===========================================", 'info');
    output("Total tenants processed: {$stats['total_tenants']}", 'info');
    output("Successful: {$stats['successful_tenants']}", 'success');
    output("Failed: {$stats['failed_tenants']}", ($stats['failed_tenants'] > 0 ? 'error' : 'success'));
    output("Total locations migrated: {$stats['total_locations_migrated']}", 'success');
    output("", 'info');

    if (!empty($stats['errors'])) {
        output("ERRORS ENCOUNTERED:", 'error');
        foreach ($stats['errors'] as $error) {
            output("  - {$error}", 'error');
        }
        output("", 'info');
    }

    if ($DRY_RUN) {
        output("THIS WAS A DRY RUN - NO DATA WAS MODIFIED", 'warning');
        output("To perform actual migration, set \$DRY_RUN = false", 'warning');
    } else {
        output("Migration completed successfully!", 'success');

        // Update tenant cached counts
        output("Updating tenant location counts...", 'info');
        $conn->exec("
            UPDATE tenants t
            SET t.total_locations = (
                SELECT COUNT(*)
                FROM tenant_locations tl
                WHERE tl.tenant_id = t.id
                  AND tl.deleted_at IS NULL
                  AND tl.is_active = TRUE
            )
        ");
        output("Tenant counts updated", 'success');
    }

    output("", 'info');
    output("===========================================", 'info');

} catch (Exception $e) {
    output("FATAL ERROR: " . $e->getMessage(), 'error');
    output("Stack trace: " . $e->getTraceAsString(), 'error');
    exit(1);
}
