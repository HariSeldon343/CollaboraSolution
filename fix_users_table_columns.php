<?php
/**
 * Script per aggiungere le colonne mancanti alla tabella users
 * Necessarie per il sistema di prima password
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

echo "=== FIX TABELLA USERS - COLONNE MANCANTI ===\n\n";

try {
    $db = Database::getInstance();

    // Lista delle colonne necessarie con le loro definizioni
    $required_columns = [
        'password_reset_token' => "VARCHAR(255) DEFAULT NULL",
        'password_reset_expires' => "DATETIME DEFAULT NULL",
        'first_login' => "TINYINT(1) DEFAULT 0",
        'welcome_email_sent_at' => "DATETIME DEFAULT NULL"
    ];

    // Ottieni le colonne esistenti
    $existing = $db->query("SHOW COLUMNS FROM users");
    $existing_columns = [];
    foreach ($existing as $col) {
        $existing_columns[] = $col['Field'];
    }

    echo "Colonne esistenti: " . implode(', ', $existing_columns) . "\n\n";

    $added_columns = [];
    $errors = [];

    foreach ($required_columns as $column_name => $column_definition) {
        if (!in_array($column_name, $existing_columns)) {
            echo "Aggiunta colonna '$column_name'... ";

            try {
                $sql = "ALTER TABLE users ADD COLUMN $column_name $column_definition";
                $db->query($sql);
                echo "✓ OK\n";
                $added_columns[] = $column_name;
            } catch (Exception $e) {
                echo "✗ ERRORE: " . $e->getMessage() . "\n";
                $errors[] = "$column_name: " . $e->getMessage();
            }
        } else {
            echo "Colonna '$column_name' già esiste - SKIP\n";
        }
    }

    echo "\n=== RISULTATO ===\n";

    if (count($added_columns) > 0) {
        echo "✓ Colonne aggiunte con successo:\n";
        foreach ($added_columns as $col) {
            echo "  - $col\n";
        }
    } else {
        echo "Nessuna colonna aggiunta (tutte già esistenti)\n";
    }

    if (count($errors) > 0) {
        echo "\n✗ Errori riscontrati:\n";
        foreach ($errors as $err) {
            echo "  - $err\n";
        }
    }

    // Verifica finale
    echo "\n=== VERIFICA FINALE ===\n";
    $final_check = $db->query("SHOW COLUMNS FROM users");
    $all_ok = true;

    foreach ($required_columns as $column_name => $def) {
        $found = false;
        foreach ($final_check as $col) {
            if ($col['Field'] === $column_name) {
                $found = true;
                break;
            }
        }

        if ($found) {
            echo "✓ $column_name - PRESENTE\n";
        } else {
            echo "✗ $column_name - ANCORA MANCANTE!\n";
            $all_ok = false;
        }
    }

    if ($all_ok) {
        echo "\n✓ La tabella users è ora completa e pronta per il sistema di prima password!\n";
    } else {
        echo "\n⚠️ Alcune colonne sono ancora mancanti. Verifica manualmente.\n";
    }

} catch (Exception $e) {
    echo "✗ Errore critico: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nPuoi ora testare la creazione utenti da utenti.php\n";
?>