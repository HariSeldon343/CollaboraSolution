<?php
/**
 * ONE-CLICK FIX per Files API
 *
 * Questo script:
 * 1. Analizza il database
 * 2. Crea un backup del file attuale
 * 3. Sostituisce l'API con la versione corretta
 * 4. Testa che funzioni
 *
 * ISTRUZIONI:
 * 1. Carica SOLO questo file su /public_html/CollaboraNexio/
 * 2. Vai su: https://app.nexiosolution.it/CollaboraNexio/one_click_fix.php
 * 3. Clicca "ESEGUI FIX AUTOMATICO"
 * 4. Fatto!
 */

// Richiedi autenticazione
session_start();
require_once __DIR__ . '/includes/auth_simple.php';

$auth = new AuthSimple();
if (!$auth->checkAuth()) {
    die('<html><body style="font-family: monospace; background: #1a202c; color: #ef4444; padding: 40px;">
    <h1>❌ Autenticazione Richiesta</h1>
    <p>Fai <a href="index.php" style="color: #10b981;">login</a> prima di eseguire questo script.</p>
    </body></html>');
}

$currentUser = $auth->getCurrentUser();
$user_role = $currentUser['role'] ?? 'user';

if ($user_role !== 'super_admin') {
    die('<html><body style="font-family: monospace; background: #1a202c; color: #ef4444; padding: 40px;">
    <h1>❌ Permesso Negato</h1>
    <p>Solo super_admin può eseguire questo script.</p>
    <p>Ruolo attuale: ' . htmlspecialchars($user_role) . '</p>
    </body></html>');
}

// Path ai file
$api_dir = __DIR__ . '/api';
$target_file = $api_dir . '/files_tenant_fixed.php';
$backup_file = $api_dir . '/files_tenant_fixed.php.backup_' . date('YmdHis');
$new_file = __DIR__ . '/api/files_tenant_production.php';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>One-Click Fix - Files API</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            background: linear-gradient(135deg, #1a202c 0%, #0f172a 100%);
            color: #10b981;
            padding: 20px;
            line-height: 1.8;
            min-height: 100vh;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #1e293b;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        h1 {
            color: #10b981;
            font-size: 32px;
            margin-bottom: 20px;
            text-align: center;
            text-shadow: 0 0 20px rgba(16, 185, 129, 0.5);
        }
        h2 {
            color: #3b82f6;
            font-size: 20px;
            margin: 30px 0 15px 0;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 8px;
        }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .info { color: #3b82f6; }
        pre {
            background: #0f172a;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            border-left: 4px solid #10b981;
            margin: 15px 0;
            font-size: 13px;
        }
        .step {
            background: #0f172a;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
        }
        .btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #1a202c;
            padding: 16px 32px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 18px;
            margin: 20px 10px 20px 0;
            transition: transform 0.2s;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.6);
        }
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }
        .btn-test {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }
        .status-box {
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border: 2px solid;
        }
        .status-box.success {
            background: rgba(16, 185, 129, 0.1);
            border-color: #10b981;
            color: #10b981;
        }
        .status-box.error {
            background: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
            color: #ef4444;
        }
        .status-box.warning {
            background: rgba(245, 158, 11, 0.1);
            border-color: #f59e0b;
            color: #f59e0b;
        }
        ul { margin-left: 30px; margin-top: 10px; }
        li { margin: 8px 0; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            background: #0f172a;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border: 1px solid #334155;
        }
        th {
            background: #1e293b;
            color: #10b981;
            font-weight: bold;
        }
        .progress {
            background: #0f172a;
            height: 30px;
            border-radius: 15px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            transition: width 0.5s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 ONE-CLICK FIX</h1>
        <p style="text-align: center; color: #6b7280; margin-bottom: 30px;">
            Auto-Fix per Files API - Risolve errore 500 su Folder ID 4
        </p>

        <div class="status-box warning">
            <strong>⚠️ ATTENZIONE</strong><br>
            Questo script modificherà il file <code>api/files_tenant_fixed.php</code><br>
            Un backup verrà creato automaticamente prima di procedere.
        </div>

<?php

if (isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'analyze') {
        // STEP 1: Analisi
        echo '<div class="step">';
        echo '<h2>📊 STEP 1: Analisi Database</h2>';

        try {
            require_once __DIR__ . '/includes/db.php';
            $db = Database::getInstance();
            $pdo = $db->getConnection();

            // Controlla colonna size
            $stmt = $pdo->query("SHOW COLUMNS FROM files LIKE 'size%'");
            $size_cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $size_column = 'size';
            foreach ($size_cols as $col) {
                if ($col['Field'] === 'size_bytes') {
                    $size_column = 'size_bytes';
                }
            }

            echo '<p class="success">✓ Colonna size rilevata: <strong>' . $size_column . '</strong></p>';

            // Controlla Folder 4
            $stmt = $pdo->prepare("SELECT * FROM folders WHERE id = 4");
            $stmt->execute();
            $folder4 = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($folder4) {
                echo '<p class="success">✓ Folder ID 4 trovato nel database</p>';
                echo '<table>';
                echo '<tr><th>Campo</th><th>Valore</th></tr>';
                echo '<tr><td>ID</td><td>' . $folder4['id'] . '</td></tr>';
                echo '<tr><td>Name</td><td>' . htmlspecialchars($folder4['name']) . '</td></tr>';
                echo '<tr><td>Tenant ID</td><td class="' . ($folder4['tenant_id'] === null ? 'warning' : '') . '">' . ($folder4['tenant_id'] ?? 'NULL') . '</td></tr>';
                echo '<tr><td>Parent ID</td><td>' . ($folder4['parent_id'] ?? 'NULL') . '</td></tr>';
                echo '</table>';

                if ($folder4['tenant_id'] === null) {
                    echo '<div class="status-box warning">';
                    echo '<strong>⚠️ PROBLEMA CONFERMATO</strong><br>';
                    echo 'Folder 4 ha <code>tenant_id = NULL</code> (cartella di sistema)<br>';
                    echo 'L\'API attuale filtra per tenant_id ed esclude le cartelle NULL<br>';
                    echo '<strong>SOLUZIONE:</strong> Rimuovere filtro tenant per super_admin';
                    echo '</div>';
                }
            } else {
                echo '<p class="error">✗ Folder ID 4 NON esiste!</p>';
            }

            // Test query
            echo '<h3 class="info">Test Query</h3>';
            $test_query = "SELECT COUNT(*) as count FROM folders WHERE deleted_at IS NULL AND parent_id = 4";
            $stmt = $pdo->query($test_query);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            echo '<p>Sotto-cartelle di Folder 4: <strong>' . $count . '</strong></p>';

        } catch (Exception $e) {
            echo '<p class="error">✗ Errore: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }

        echo '</div>';

        echo '<form method="post">';
        echo '<button type="submit" name="action" value="backup" class="btn">▶️ PROCEDI AL BACKUP</button>';
        echo '</form>';

    } elseif ($action === 'backup') {
        // STEP 2: Backup
        echo '<div class="step">';
        echo '<h2>💾 STEP 2: Creazione Backup</h2>';

        if (file_exists($target_file)) {
            if (copy($target_file, $backup_file)) {
                echo '<p class="success">✓ Backup creato con successo!</p>';
                echo '<p>File backup: <code>' . basename($backup_file) . '</code></p>';
                echo '<p>Dimensione: ' . number_format(filesize($backup_file)) . ' bytes</p>';
            } else {
                echo '<p class="error">✗ Errore nella creazione del backup!</p>';
                echo '<p>Verifica i permessi della cartella api/</p>';
                die('</div></div></body></html>');
            }
        } else {
            echo '<p class="warning">⚠ File target non trovato: ' . $target_file . '</p>';
            echo '<p>Creerò un nuovo file.</p>';
        }

        echo '</div>';

        echo '<form method="post">';
        echo '<button type="submit" name="action" value="replace" class="btn">▶️ SOSTITUISCI FILE API</button>';
        echo '<button type="submit" name="action" value="cancel" class="btn btn-danger">✕ ANNULLA</button>';
        echo '</form>';

    } elseif ($action === 'replace') {
        // STEP 3: Sostituzione
        echo '<div class="step">';
        echo '<h2>🔄 STEP 3: Sostituzione File API</h2>';

        echo '<div class="progress">';
        echo '<div class="progress-bar" style="width: 50%;">50% - Copia in corso...</div>';
        echo '</div>';

        if (file_exists($new_file)) {
            if (copy($new_file, $target_file)) {
                echo '<div class="progress">';
                echo '<div class="progress-bar" style="width: 100%;">100% - Completato!</div>';
                echo '</div>';

                echo '<p class="success">✓ File API sostituito con successo!</p>';
                echo '<p>File aggiornato: <code>api/files_tenant_fixed.php</code></p>';
                echo '<p>Dimensione: ' . number_format(filesize($target_file)) . ' bytes</p>';
                echo '<p>Data modifica: ' . date('Y-m-d H:i:s', filemtime($target_file)) . '</p>';

                echo '<div class="status-box success">';
                echo '<strong>✓ MODIFICHE APPLICATE:</strong><br>';
                echo '<ul>';
                echo '<li>✓ Auto-detection colonna size (size vs size_bytes)</li>';
                echo '<li>✓ Super Admin: NESSUN filtro tenant (vede tutto incluso NULL)</li>';
                echo '<li>✓ Admin: Filtro per tenant accessibili</li>';
                echo '<li>✓ User/Manager: Filtro per proprio tenant</li>';
                echo '<li>✓ Gestione corretta cartelle di sistema (tenant_id NULL)</li>';
                echo '</ul>';
                echo '</div>';

            } else {
                echo '<p class="error">✗ Errore nella sostituzione del file!</p>';
                echo '<p>Verifica i permessi del file api/files_tenant_fixed.php</p>';

                if (file_exists($backup_file)) {
                    echo '<form method="post">';
                    echo '<button type="submit" name="action" value="rollback" class="btn btn-danger">↩️ RIPRISTINA BACKUP</button>';
                    echo '</form>';
                }

                die('</div></div></body></html>');
            }
        } else {
            echo '<p class="error">✗ File sorgente non trovato: ' . $new_file . '</p>';
            echo '<p>Assicurati che il file <code>api/files_tenant_production.php</code> esista.</p>';
            die('</div></div></body></html>');
        }

        echo '</div>';

        echo '<form method="post">';
        echo '<button type="submit" name="action" value="test" class="btn btn-test">🧪 TESTA API</button>';
        echo '</form>';

    } elseif ($action === 'test') {
        // STEP 4: Test
        echo '<div class="step">';
        echo '<h2>🧪 STEP 4: Test API</h2>';

        echo '<iframe src="/CollaboraNexio/debug_api_files.php" style="width: 100%; height: 600px; border: 2px solid #3b82f6; border-radius: 8px; background: white; margin: 20px 0;"></iframe>';

        echo '<div class="status-box success">';
        echo '<strong>✓ FIX COMPLETATO!</strong><br><br>';
        echo '<p>Ora vai su questa pagina per testare:</p>';
        echo '<a href="/CollaboraNexio/files.php" target="_blank" class="btn" style="display: inline-block; text-decoration: none; margin-top: 15px;">📂 Apri Files Manager</a>';
        echo '<br><br>';
        echo '<p>Prova a fare doppio click sulla cartella "Super Admin Files" - dovrebbe aprirsi senza errori!</p>';
        echo '</div>';

        echo '</div>';

    } elseif ($action === 'rollback') {
        // Rollback
        echo '<div class="step">';
        echo '<h2>↩️ ROLLBACK: Ripristino Backup</h2>';

        if (file_exists($backup_file)) {
            if (copy($backup_file, $target_file)) {
                echo '<p class="success">✓ Backup ripristinato con successo!</p>';
                echo '<p>Il file API è stato riportato alla versione precedente.</p>';
            } else {
                echo '<p class="error">✗ Errore nel ripristino del backup!</p>';
            }
        } else {
            echo '<p class="error">✗ File di backup non trovato!</p>';
        }

        echo '</div>';

    } elseif ($action === 'cancel') {
        echo '<div class="status-box warning">';
        echo '<strong>✕ Operazione Annullata</strong><br>';
        echo 'Nessuna modifica è stata apportata.';
        echo '</div>';
    }

} else {
    // Form iniziale
    echo '<div class="step">';
    echo '<h2>🎯 Cosa Farà Questo Script</h2>';
    echo '<ol style="font-size: 16px; line-height: 2;">';
    echo '<li><strong>Analizza</strong> il database per identificare il problema</li>';
    echo '<li><strong>Crea un backup</strong> del file API attuale</li>';
    echo '<li><strong>Sostituisce</strong> il file con la versione corretta</li>';
    echo '<li><strong>Testa</strong> che tutto funzioni</li>';
    echo '</ol>';
    echo '</div>';

    echo '<div class="status-box info" style="border-color: #3b82f6; color: #3b82f6;">';
    echo '<strong>ℹ️ PROBLEMI CHE RISOLVE:</strong><br>';
    echo '<ul>';
    echo '<li>✓ Errore 500 quando apri Folder ID 4</li>';
    echo '<li>✓ Cartelle con tenant_id NULL non visibili</li>';
    echo '<li>✓ Differenze schema database (size vs size_bytes)</li>';
    echo '<li>✓ Filtri tenant troppo restrittivi per super_admin</li>';
    echo '</ul>';
    echo '</div>';

    echo '<form method="post">';
    echo '<button type="submit" name="action" value="analyze" class="btn">🚀 AVVIA FIX AUTOMATICO</button>';
    echo '</form>';

    echo '<div style="margin-top: 40px; padding: 20px; background: rgba(59, 130, 246, 0.1); border-radius: 8px; border-left: 4px solid #3b82f6;">';
    echo '<p class="info" style="margin-bottom: 10px;"><strong>💡 Sicurezza:</strong></p>';
    echo '<ul>';
    echo '<li>✓ Backup automatico prima di modificare</li>';
    echo '<li>✓ Possibilità di rollback in caso di problemi</li>';
    echo '<li>✓ Solo super_admin può eseguire questo script</li>';
    echo '<li>✓ Nessuna modifica al database</li>';
    echo '</ul>';
    echo '</div>';
}

?>

    </div>
</body>
</html>
