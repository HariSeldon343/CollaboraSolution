<?php
/**
 * Script per correggere la struttura della tabella users
 * Aggiunge le colonne necessarie per il sistema di prima password
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Abilita visualizzazione errori
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Fix Users Table Structure</h1>";
echo "<pre>";

try {
    $db = Database::getInstance();
    echo "✓ Connesso al database\n\n";

    // Verifica struttura attuale
    echo "=== Struttura Attuale Tabella Users ===\n";
    $result = $db->query("DESCRIBE users");
    $existing_columns = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $existing_columns[] = $row['Field'];
        echo sprintf("  %-30s %s\n", $row['Field'], $row['Type']);
    }

    // Colonne necessarie per il sistema di prima password
    $required_columns = [
        'password_reset_token' => "VARCHAR(255) DEFAULT NULL",
        'password_reset_expires' => "DATETIME DEFAULT NULL",
        'first_login' => "TINYINT(1) DEFAULT 1",
        'welcome_email_sent_at' => "DATETIME DEFAULT NULL"
    ];

    echo "\n=== Verifica e Aggiunta Colonne Mancanti ===\n";
    $columns_added = 0;

    foreach ($required_columns as $column => $definition) {
        if (!in_array($column, $existing_columns)) {
            echo "✗ Colonna '$column' mancante - aggiunta in corso...\n";

            try {
                $sql = "ALTER TABLE users ADD COLUMN $column $definition";
                $db->query($sql);
                echo "  ✓ Colonna '$column' aggiunta con successo\n";
                $columns_added++;
            } catch (Exception $e) {
                echo "  ✗ Errore aggiunta colonna '$column': " . $e->getMessage() . "\n";
            }
        } else {
            echo "✓ Colonna '$column' già presente\n";
        }
    }

    if ($columns_added > 0) {
        echo "\n✓ Aggiunte $columns_added colonne alla tabella users\n";
    } else {
        echo "\n✓ Tutte le colonne necessarie sono già presenti\n";
    }

    // Verifica e crea tabella user_companies se non esiste
    echo "\n=== Verifica Tabella user_companies ===\n";
    try {
        $result = $db->query("SELECT 1 FROM user_companies LIMIT 1");
        echo "✓ Tabella user_companies esiste\n";
    } catch (Exception $e) {
        echo "✗ Tabella user_companies non esiste - creazione in corso...\n";

        $create_table = "CREATE TABLE user_companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            company_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_company (user_id, company_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (company_id) REFERENCES tenants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $db->query($create_table);
            echo "  ✓ Tabella user_companies creata con successo\n";
        } catch (Exception $e) {
            echo "  ✗ Errore creazione tabella: " . $e->getMessage() . "\n";
        }
    }

    // Verifica e crea tabella audit_logs se non esiste
    echo "\n=== Verifica Tabella audit_logs ===\n";
    try {
        $result = $db->query("SELECT 1 FROM audit_logs LIMIT 1");
        echo "✓ Tabella audit_logs esiste\n";
    } catch (Exception $e) {
        echo "✗ Tabella audit_logs non esiste - creazione in corso...\n";

        $create_table = "CREATE TABLE audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant_user (tenant_id, user_id),
            INDEX idx_action (action),
            INDEX idx_created (created_at),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        try {
            $db->query($create_table);
            echo "  ✓ Tabella audit_logs creata con successo\n";
        } catch (Exception $e) {
            echo "  ✗ Errore creazione tabella: " . $e->getMessage() . "\n";
        }
    }

    // Test creazione utente
    echo "\n=== Test Creazione Utente ===\n";
    try {
        $test_email = 'test_structure_' . time() . '@example.com';
        $reset_token = bin2hex(random_bytes(32));

        $db->beginTransaction();

        $user_data = [
            'first_name' => 'Test',
            'last_name' => 'Structure',
            'email' => $test_email,
            'password_hash' => null,
            'role' => 'user',
            'tenant_id' => 1,
            'status' => 'active',
            'password_reset_token' => $reset_token,
            'password_reset_expires' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'first_login' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $new_id = $db->insert('users', $user_data);
        echo "✓ Test inserimento riuscito (ID: $new_id)\n";

        $db->rollback();
        echo "✓ Rollback eseguito (solo test)\n";
    } catch (Exception $e) {
        $db->rollback();
        echo "✗ Test inserimento fallito: " . $e->getMessage() . "\n";
    }

    echo "\n=== Completato ===\n";
    echo "La struttura del database è stata verificata e corretta.\n";
    echo "Ora puoi riprovare a creare un utente.\n";

} catch (Exception $e) {
    echo "✗ Errore generale: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";

// Link utili
echo '<div style="margin-top: 20px;">';
echo '<a href="utenti.php">← Torna a Gestione Utenti</a> | ';
echo '<a href="debug_create_user.php">Debug Dettagliato</a> | ';
echo '<a href="test_create_user_api.php">Test API</a>';
echo '</div>';
?>