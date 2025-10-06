<?php
/**
 * Script di setup per il sistema di gestione file
 * Esegue la migrazione del database e popola con dati di esempio
 */

require_once 'config.php';
require_once 'includes/db.php';

// Inizializza database
$db = Database::getInstance();
$pdo = $db->getConnection();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Setup File System</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            border: 1px solid #f5c6cb;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            border: 1px solid #bee5eb;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
        }
        .button {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            display: inline-block;
            margin-top: 20px;
        }
        .button:hover {
            background: #45a049;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Setup Sistema File Management</h1>";

try {
    // Leggi il file di migrazione SQL
    $migrationFile = __DIR__ . '/migrations/create_files_table.sql';

    if (!file_exists($migrationFile)) {
        throw new Exception("File di migrazione non trovato: $migrationFile");
    }

    echo "<div class='info'>Lettura file di migrazione...</div>";
    $sql = file_get_contents($migrationFile);

    // Dividi il file SQL in singole query
    $queries = array_filter(array_map('trim', explode(';', $sql)));

    $successCount = 0;
    $errorCount = 0;

    foreach ($queries as $query) {
        if (empty($query)) continue;

        try {
            // Esegui query
            $pdo->exec($query);
            $successCount++;

            // Estrai il nome della tabella per il log
            if (preg_match('/CREATE TABLE.*?`(\w+)`/i', $query, $matches)) {
                echo "<div class='success'>✓ Tabella `{$matches[1]}` creata con successo</div>";
            } elseif (preg_match('/DROP TABLE.*?`(\w+)`/i', $query, $matches)) {
                echo "<div class='info'>✓ Tabella `{$matches[1]}` eliminata (se esistente)</div>";
            } elseif (preg_match('/CREATE.*?VIEW.*?`(\w+)`/i', $query, $matches)) {
                echo "<div class='success'>✓ Vista `{$matches[1]}` creata con successo</div>";
            } elseif (preg_match('/INSERT INTO.*?`(\w+)`/i', $query, $matches)) {
                echo "<div class='success'>✓ Dati inseriti in `{$matches[1]}`</div>";
            } else {
                echo "<div class='success'>✓ Query eseguita con successo</div>";
            }
        } catch (Exception $e) {
            $errorCount++;
            echo "<div class='error'>✗ Errore: " . htmlspecialchars($e->getMessage()) . "</div>";
            // Continua con le altre query
        }
    }

    echo "<h2>Popolamento dati di esempio</h2>";

    // Verifica se esistono tenant e utenti
    $stmt = $pdo->query("SELECT id FROM tenants WHERE status = 'active' LIMIT 1");
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant) {
        echo "<div class='error'>Nessun tenant attivo trovato. Esegui prima lo script di setup principale.</div>";
    } else {
        $tenant_id = $tenant['id'];

        // Ottieni un utente admin del tenant
        $stmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id = :tenant_id AND role IN ('admin', 'super_admin') LIMIT 1");
        $stmt->execute([':tenant_id' => $tenant_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $user_id = $user['id'];

            // Inserisci file di esempio
            $sampleFiles = [
                [
                    'name' => 'Contratto_2024.pdf',
                    'type' => 'pdf',
                    'size' => 2457600, // 2.4MB
                    'mime' => 'application/pdf'
                ],
                [
                    'name' => 'Report_Vendite_Q1.xlsx',
                    'type' => 'xlsx',
                    'size' => 876544, // 856KB
                    'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ],
                [
                    'name' => 'Presentazione_Progetto.pptx',
                    'type' => 'pptx',
                    'size' => 12902400, // 12.3MB
                    'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
                ],
                [
                    'name' => 'Note_Riunione.docx',
                    'type' => 'docx',
                    'size' => 46080, // 45KB
                    'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ],
                [
                    'name' => 'Budget_2024.xls',
                    'type' => 'xls',
                    'size' => 524288, // 512KB
                    'mime' => 'application/vnd.ms-excel'
                ],
                [
                    'name' => 'Manuale_Utente.pdf',
                    'type' => 'pdf',
                    'size' => 5242880, // 5MB
                    'mime' => 'application/pdf'
                ]
            ];

            // Verifica se ci sono già file per questo tenant
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM files WHERE tenant_id = :tenant_id AND is_folder = 0");
            $stmt->execute([':tenant_id' => $tenant_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] == 0) {
                // Ottieni ID della cartella Documents
                $stmt = $pdo->prepare("SELECT id FROM files WHERE tenant_id = :tenant_id AND file_name = 'Documents' AND is_folder = 1 LIMIT 1");
                $stmt->execute([':tenant_id' => $tenant_id]);
                $documentsFolder = $stmt->fetch(PDO::FETCH_ASSOC);
                $folder_id = $documentsFolder ? $documentsFolder['id'] : null;

                $insertedCount = 0;
                foreach ($sampleFiles as $file) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO files (
                                tenant_id, file_name, original_name, file_path,
                                file_type, file_size, mime_type, folder_id,
                                is_folder, uploaded_by, created_at, updated_at
                            ) VALUES (
                                :tenant_id, :file_name, :original_name, :file_path,
                                :file_type, :file_size, :mime_type, :folder_id,
                                0, :uploaded_by,
                                DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 30) DAY),
                                DATE_SUB(NOW(), INTERVAL FLOOR(RAND() * 7) DAY)
                            )
                        ");

                        $file_path = '/uploads/tenant_' . $tenant_id . '/sample/' . uniqid() . '.' . $file['type'];

                        $stmt->execute([
                            ':tenant_id' => $tenant_id,
                            ':file_name' => $file['name'],
                            ':original_name' => $file['name'],
                            ':file_path' => $file_path,
                            ':file_type' => $file['type'],
                            ':file_size' => $file['size'],
                            ':mime_type' => $file['mime'],
                            ':folder_id' => $folder_id,
                            ':uploaded_by' => $user_id
                        ]);

                        $insertedCount++;
                        echo "<div class='success'>✓ File di esempio inserito: {$file['name']}</div>";
                    } catch (Exception $e) {
                        echo "<div class='error'>✗ Errore inserimento {$file['name']}: " . htmlspecialchars($e->getMessage()) . "</div>";
                    }
                }

                if ($insertedCount > 0) {
                    echo "<div class='info'>Inseriti $insertedCount file di esempio</div>";
                }
            } else {
                echo "<div class='info'>File di esempio già presenti nel database</div>";
            }
        } else {
            echo "<div class='error'>Nessun utente admin trovato per il tenant</div>";
        }
    }

    // Riepilogo finale
    echo "<h2>Riepilogo</h2>";

    // Conta tabelle create
    $tables = ['files', 'file_permissions', 'file_activity_logs'];
    $existingTables = 0;

    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->fetch()) {
            $existingTables++;
            echo "<div class='success'>✓ Tabella `$table` presente nel database</div>";
        } else {
            echo "<div class='error'>✗ Tabella `$table` non trovata</div>";
        }
    }

    // Conta file nel database
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM files WHERE deleted_at IS NULL");
    $totalFiles = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo "<div class='info'>Totale file nel database: $totalFiles</div>";

    if ($existingTables == count($tables)) {
        echo "<div class='success'><strong>✓ Setup completato con successo!</strong></div>";
    } else {
        echo "<div class='error'><strong>⚠ Setup completato con alcuni problemi</strong></div>";
    }

} catch (Exception $e) {
    echo "<div class='error'><strong>Errore critico:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "
        <a href='files.php' class='button'>Vai al File Manager</a>
    </div>
</body>
</html>";
?>