<?php
/**
 * Rimuove vecchie configurazioni email duplicate
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "=== Pulizia Configurazioni Email Duplicate ===\n\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    // Trova tutte le chiavi email
    $stmt = $conn->prepare("
        SELECT id, setting_key, setting_value, updated_at
        FROM system_settings
        WHERE category = 'email'
        ORDER BY setting_key, updated_at DESC
    ");
    $stmt->execute();
    $allSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Configurazioni email trovate: " . count($allSettings) . "\n\n";

    // Identifica i duplicati (vecchie chiavi Infomaniak/Fortibyte)
    $oldSettings = [
        'smtp_from_email',  // Vecchia chiave, ora è 'from_email'
        'smtp_from_name',   // Vecchia chiave, ora è 'from_name'
        'smtp_secure'       // Vecchia chiave, ora è 'smtp_encryption'
    ];

    // Record da eliminare
    $toDelete = [];

    // Trova duplicati: stesso setting_key con date diverse
    $grouped = [];
    foreach ($allSettings as $setting) {
        $key = $setting['setting_key'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = $setting;
    }

    // Per ogni chiave duplicata, mantieni solo la più recente
    foreach ($grouped as $key => $records) {
        if (count($records) > 1) {
            // Ordina per data decrescente
            usort($records, function($a, $b) {
                return strtotime($b['updated_at']) - strtotime($a['updated_at']);
            });

            // Mantieni solo il primo (più recente), elimina gli altri
            for ($i = 1; $i < count($records); $i++) {
                $toDelete[] = $records[$i];
                echo "  ⚠ Duplicato trovato: {$key} (ID: {$records[$i]['id']}, data: {$records[$i]['updated_at']})\n";
            }
        }
    }

    // Elimina vecchie chiavi non più utilizzate
    foreach ($allSettings as $setting) {
        if (in_array($setting['setting_key'], $oldSettings)) {
            $toDelete[] = $setting;
            echo "  ⚠ Chiave obsoleta: {$setting['setting_key']} (ID: {$setting['id']})\n";
        }
    }

    // Elimina vecchia configurazione Infomaniak/Fortibyte
    foreach ($allSettings as $setting) {
        if (in_array($setting['setting_key'], ['smtp_host', 'smtp_username']) &&
            (strpos($setting['setting_value'], 'infomaniak') !== false ||
             strpos($setting['setting_value'], 'fortibyte') !== false)) {

            // Verifica che esista una versione più recente con nexiosolution
            $hasNewer = false;
            foreach ($grouped[$setting['setting_key']] as $rec) {
                if (strpos($rec['setting_value'], 'nexiosolution') !== false &&
                    strtotime($rec['updated_at']) > strtotime($setting['updated_at'])) {
                    $hasNewer = true;
                    break;
                }
            }

            if ($hasNewer) {
                $alreadyMarked = false;
                foreach ($toDelete as $del) {
                    if ($del['id'] === $setting['id']) {
                        $alreadyMarked = true;
                        break;
                    }
                }
                if (!$alreadyMarked) {
                    $toDelete[] = $setting;
                    echo "  ⚠ Vecchia configurazione: {$setting['setting_key']} = {$setting['setting_value']} (ID: {$setting['id']})\n";
                }
            }
        }
    }

    if (empty($toDelete)) {
        echo "\n✓ Nessun record da eliminare, configurazione già pulita!\n";
    } else {
        echo "\n\nRecord da eliminare: " . count($toDelete) . "\n";
        echo "Procedere con l'eliminazione? (y/n): ";

        // In script automatico, procedi direttamente
        $proceed = 'y';

        if ($proceed === 'y') {
            $conn->beginTransaction();

            foreach ($toDelete as $setting) {
                $stmt = $conn->prepare("DELETE FROM system_settings WHERE id = ?");
                $stmt->execute([$setting['id']]);
                echo "  ✓ Eliminato: {$setting['setting_key']} (ID: {$setting['id']})\n";
            }

            $conn->commit();
            echo "\n✓ Pulizia completata con successo!\n";
        }
    }

    // Mostra configurazione finale
    echo "\nConfigurazione email finale:\n";
    $stmt = $conn->prepare("
        SELECT setting_key, setting_value, updated_at
        FROM system_settings
        WHERE category = 'email' AND tenant_id IS NULL
        ORDER BY setting_key
    ");
    $stmt->execute();
    $finalSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($finalSettings as $setting) {
        $value = $setting['setting_value'];
        if ($setting['setting_key'] === 'smtp_password') {
            $value = str_repeat('*', strlen($value));
        }
        echo "  ✓ {$setting['setting_key']}: {$value}\n";
    }

    echo "\n";

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "❌ ERRORE: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    exit(1);
}
?>
