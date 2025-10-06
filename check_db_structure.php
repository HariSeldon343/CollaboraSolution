<?php
/**
 * Script per verificare e correggere la struttura del database
 * Eseguire dal browser: http://localhost:8888/CollaboraNexio/check_db_structure.php
 */

require_once 'config.php';
require_once 'includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== VERIFICA STRUTTURA DATABASE ===\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    echo "✓ Connessione database OK\n\n";

    // Verifica se la tabella files esiste
    $stmt = $pdo->query("SHOW TABLES LIKE 'files'");
    if (!$stmt->fetch()) {
        echo "✗ Tabella 'files' non esiste. Creazione in corso...\n";

        $createTableSQL = "
        CREATE TABLE IF NOT EXISTS `files` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` INT(11) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `file_path` VARCHAR(500) NULL,
            `file_type` VARCHAR(50) NULL,
            `file_size` BIGINT NULL,
            `mime_type` VARCHAR(100) NULL,
            `is_folder` TINYINT(1) DEFAULT 0,
            `folder_id` INT(11) NULL,
            `uploaded_by` INT(11) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_tenant_folder` (`tenant_id`, `folder_id`),
            INDEX `idx_deleted` (`deleted_at`),
            INDEX `idx_name` (`name`),
            CONSTRAINT `fk_files_tenant` FOREIGN KEY (`tenant_id`)
                REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_files_user` FOREIGN KEY (`uploaded_by`)
                REFERENCES `users` (`id`) ON DELETE RESTRICT,
            CONSTRAINT `fk_files_folder` FOREIGN KEY (`folder_id`)
                REFERENCES `files` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $pdo->exec($createTableSQL);
        echo "✓ Tabella 'files' creata con successo\n";
    } else {
        echo "✓ Tabella 'files' esiste\n";
    }

    // Mostra struttura attuale
    echo "\nStruttura attuale tabella 'files':\n";
    echo str_repeat('=', 80) . "\n";

    $stmt = $pdo->query("DESCRIBE files");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $requiredColumns = [
        'id' => 'INT',
        'tenant_id' => 'INT',
        'name' => 'VARCHAR',
        'file_path' => 'VARCHAR',
        'file_type' => 'VARCHAR',
        'file_size' => 'BIGINT',
        'mime_type' => 'VARCHAR',
        'is_folder' => 'TINYINT',
        'folder_id' => 'INT',
        'uploaded_by' => 'INT',
        'created_at' => 'TIMESTAMP',
        'updated_at' => 'TIMESTAMP',
        'deleted_at' => 'TIMESTAMP'
    ];

    $existingColumns = [];
    foreach ($columns as $column) {
        $existingColumns[$column['Field']] = $column['Type'];
        echo sprintf("%-20s %-30s %-10s\n",
            $column['Field'],
            $column['Type'],
            $column['Null'] === 'YES' ? 'NULL' : 'NOT NULL'
        );
    }

    // Verifica colonne mancanti
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "Verifica colonne:\n\n";

    $missingColumns = [];
    foreach ($requiredColumns as $colName => $colType) {
        if (isset($existingColumns[$colName])) {
            echo "✓ Colonna '$colName' presente\n";
        } else {
            echo "✗ Colonna '$colName' MANCANTE\n";
            $missingColumns[] = $colName;
        }
    }

    // Aggiungi colonne mancanti
    if (!empty($missingColumns)) {
        echo "\nAggiunta colonne mancanti:\n";

        foreach ($missingColumns as $colName) {
            $alterSQL = match($colName) {
                'name' => "ALTER TABLE files ADD COLUMN `name` VARCHAR(255) NOT NULL AFTER `tenant_id`",
                'file_path' => "ALTER TABLE files ADD COLUMN `file_path` VARCHAR(500) NULL",
                'file_type' => "ALTER TABLE files ADD COLUMN `file_type` VARCHAR(50) NULL",
                'file_size' => "ALTER TABLE files ADD COLUMN `file_size` BIGINT NULL",
                'mime_type' => "ALTER TABLE files ADD COLUMN `mime_type` VARCHAR(100) NULL",
                'is_folder' => "ALTER TABLE files ADD COLUMN `is_folder` TINYINT(1) DEFAULT 0",
                'folder_id' => "ALTER TABLE files ADD COLUMN `folder_id` INT(11) NULL",
                'uploaded_by' => "ALTER TABLE files ADD COLUMN `uploaded_by` INT(11) NOT NULL",
                'created_at' => "ALTER TABLE files ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
                'updated_at' => "ALTER TABLE files ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                'deleted_at' => "ALTER TABLE files ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL",
                default => null
            };

            if ($alterSQL) {
                try {
                    $pdo->exec($alterSQL);
                    echo "✓ Colonna '$colName' aggiunta\n";
                } catch (Exception $e) {
                    echo "✗ Errore aggiungendo '$colName': " . $e->getMessage() . "\n";
                }
            }
        }
    }

    // Verifica tabella users
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "Verifica tabella 'users':\n\n";

    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->fetch()) {
        echo "✓ Tabella 'users' esiste\n";

        // Verifica colonne necessarie
        $stmt = $pdo->query("DESCRIBE users");
        $userColumns = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        $requiredUserColumns = ['id', 'tenant_id', 'first_name', 'last_name', 'email', 'role'];

        foreach ($requiredUserColumns as $col) {
            if (in_array($col, $userColumns)) {
                echo "✓ Colonna 'users.$col' presente\n";
            } else {
                echo "✗ Colonna 'users.$col' MANCANTE\n";
            }
        }
    } else {
        echo "✗ Tabella 'users' non esiste\n";
    }

    // Verifica tabella tenants
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "Verifica tabella 'tenants':\n\n";

    $stmt = $pdo->query("SHOW TABLES LIKE 'tenants'");
    if ($stmt->fetch()) {
        echo "✓ Tabella 'tenants' esiste\n";
    } else {
        echo "✗ Tabella 'tenants' non esiste\n";
    }

    // Test query API
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "Test query API files:\n\n";

    $testSQL = "
        SELECT f.*,
               u.first_name, u.last_name,
               CONCAT(u.first_name, ' ', u.last_name) as uploaded_by_name,
               (SELECT COUNT(*) FROM files WHERE folder_id = f.id AND deleted_at IS NULL) as item_count
        FROM files f
        LEFT JOIN users u ON f.uploaded_by = u.id
        WHERE f.tenant_id = 1
        AND f.deleted_at IS NULL
        AND f.folder_id IS NULL
        LIMIT 1
    ";

    try {
        $stmt = $pdo->prepare($testSQL);
        $stmt->execute();
        echo "✓ Query API eseguibile\n";

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            echo "✓ Dati recuperati con successo\n";
        } else {
            echo "ℹ Nessun file trovato (normale se database vuoto)\n";
        }
    } catch (Exception $e) {
        echo "✗ Errore nella query: " . $e->getMessage() . "\n";
    }

    echo "\n=== VERIFICA COMPLETATA ===\n";

} catch (Exception $e) {
    echo "\n✗ ERRORE CRITICO: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}