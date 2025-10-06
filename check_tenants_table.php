<?php
/**
 * Script per verificare la struttura della tabella tenants
 * e confrontarla con ciò che il codice si aspetta
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Get database connection
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    die('Errore connessione database: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica Struttura Tabella Tenants</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        h2 { color: #666; margin-top: 30px; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table th, table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }
        table th {
            background: #f5f5f5;
            font-weight: bold;
        }
        .success { color: #4CAF50; }
        .error { color: #f44336; }
        .warning { color: #ff9800; }
        .issue {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            margin: 10px 0;
        }
        .fixed {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 10px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Verifica Struttura Tabella Tenants</h1>

        <?php
        try {
            // Verifica esistenza tabella
            $stmt = $pdo->query("SHOW TABLES LIKE 'tenants'");
            if ($stmt->rowCount() == 0) {
                echo '<p class="error">❌ La tabella tenants NON esiste!</p>';
                exit;
            }
            echo '<p class="success">✓ La tabella tenants esiste</p>';

            // Ottieni struttura della tabella
            echo '<h2>Struttura attuale della tabella:</h2>';
            $stmt = $pdo->query("DESCRIBE tenants");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo '<table>';
            echo '<thead><tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>';
            echo '<tbody>';

            $columnNames = [];
            foreach ($columns as $column) {
                $columnNames[] = $column['Field'];
                echo '<tr>';
                echo '<td>' . htmlspecialchars($column['Field']) . '</td>';
                echo '<td>' . htmlspecialchars($column['Type']) . '</td>';
                echo '<td>' . htmlspecialchars($column['Null']) . '</td>';
                echo '<td>' . htmlspecialchars($column['Key']) . '</td>';
                echo '<td>' . htmlspecialchars($column['Default'] ?? 'NULL') . '</td>';
                echo '<td>' . htmlspecialchars($column['Extra']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            // Verifica colonne richieste
            echo '<h2>Verifica colonne richieste dal codice:</h2>';

            $requiredColumns = [
                'id' => 'Chiave primaria',
                'name' => 'Nome del tenant',
                'status' => 'Stato del tenant (active/inactive/suspended)',
            ];

            $problemColumns = [
                'deleted_at' => 'Usata nel codice originale ma NON esiste nello schema',
                'is_active' => 'Usata nel codice originale ma NON esiste nello schema'
            ];

            echo '<h3>Colonne richieste:</h3>';
            foreach ($requiredColumns as $col => $desc) {
                if (in_array($col, $columnNames)) {
                    echo '<p class="success">✓ ' . $col . ' - ' . $desc . '</p>';
                } else {
                    echo '<p class="error">✗ ' . $col . ' - ' . $desc . ' (MANCANTE!)</p>';
                }
            }

            echo '<h3>Problemi risolti nel codice:</h3>';
            foreach ($problemColumns as $col => $desc) {
                if (in_array($col, $columnNames)) {
                    echo '<p class="warning">⚠ ' . $col . ' esiste - ' . $desc . '</p>';
                } else {
                    echo '<p class="success">✓ ' . $col . ' NON esiste - ' . $desc . ' (CORRETTO)</p>';
                }
            }

            // Test query corretta
            echo '<h2>Test query corretta:</h2>';
            $testQuery = "
                SELECT id, name,
                       CASE WHEN status = 'active' THEN '1' ELSE '0' END as is_active,
                       status
                FROM tenants
                WHERE status != 'suspended'
                ORDER BY name
            ";

            echo '<pre>' . htmlspecialchars($testQuery) . '</pre>';

            $stmt = $pdo->query($testQuery);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($results) > 0) {
                echo '<p class="success">✓ Query eseguita con successo! Trovati ' . count($results) . ' tenant.</p>';

                echo '<table>';
                echo '<thead><tr><th>ID</th><th>Nome</th><th>is_active (calcolato)</th><th>Status</th></tr></thead>';
                echo '<tbody>';
                foreach ($results as $row) {
                    echo '<tr>';
                    echo '<td>' . $row['id'] . '</td>';
                    echo '<td>' . htmlspecialchars($row['name']) . '</td>';
                    echo '<td>' . ($row['is_active'] === '1' ? '✓ Attivo' : '✗ Non attivo') . '</td>';
                    echo '<td>' . htmlspecialchars($row['status']) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p class="warning">⚠ Query eseguita ma nessun tenant trovato.</p>';
            }

            // Riepilogo
            echo '<h2>Riepilogo correzioni applicate:</h2>';
            echo '<div class="fixed">';
            echo '<h3>✓ Correzioni applicate in files_tenant_fixed.php:</h3>';
            echo '<ol>';
            echo '<li>Rimosso riferimento a <code>deleted_at</code> (che non esiste nella tabella tenants)</li>';
            echo '<li>Cambiato <code>is_active</code> con calcolo basato su <code>status</code> usando CASE WHEN</li>';
            echo '<li>Filtro per <code>status != \'suspended\'</code> invece di <code>deleted_at IS NULL</code></li>';
            echo '<li>Aggiunto controllo isset per $stmt nel catch per evitare errori</li>';
            echo '</ol>';
            echo '</div>';

        } catch (PDOException $e) {
            echo '<div class="error">';
            echo '<h3>Errore SQL:</h3>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        } catch (Exception $e) {
            echo '<div class="error">';
            echo '<h3>Errore:</h3>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
        ?>

        <h2>Come testare la correzione:</h2>
        <ol>
            <li>Accedi come utente admin o super_admin</li>
            <li>Vai alla pagina <a href="/CollaboraNexio/files.php">/CollaboraNexio/files.php</a></li>
            <li>Clicca sul pulsante "Cartella Tenant"</li>
            <li>Il modal dovrebbe aprirsi correttamente con la lista dei tenant</li>
        </ol>

        <p>Oppure usa il <a href="/CollaboraNexio/test_tenant_api.php">Test API dedicato</a> (richiede login come admin/super_admin)</p>
    </div>
</body>
</html>