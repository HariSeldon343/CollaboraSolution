<?php
/**
 * Verifica configurazione tenant per creazione utenti
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Inizializza sessione
session_start();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Verifica Tenant</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #4CAF50; color: white; }
        tr:hover { background-color: #f5f5f5; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info-box { background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #2196f3; }
        .create-btn { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        .create-btn:hover { background: #45a049; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Verifica Configurazione Tenant</h1>

        <?php
        try {
            $db = Database::getInstance();
            $conn = $db->getConnection();

            // 1. Verifica tabella tenants
            echo "<h2>1. Tenant Esistenti</h2>";

            $stmt = $conn->query("SELECT id, name, created_at, deleted_at FROM tenants ORDER BY id");
            $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($tenants)) {
                echo "<p class='error'>❌ Nessun tenant trovato nel database!</p>";
                echo "<p>È necessario almeno un tenant per creare utenti.</p>";
            } else {
                echo "<table>";
                echo "<tr><th>ID</th><th>Nome</th><th>Creato</th><th>Stato</th></tr>";

                $activeTenants = 0;
                foreach ($tenants as $tenant) {
                    $status = $tenant['deleted_at'] ?
                        "<span class='error'>Eliminato</span>" :
                        "<span class='success'>Attivo</span>";

                    if (!$tenant['deleted_at']) {
                        $activeTenants++;
                    }

                    echo "<tr>";
                    echo "<td>{$tenant['id']}</td>";
                    echo "<td>{$tenant['name']}</td>";
                    echo "<td>{$tenant['created_at']}</td>";
                    echo "<td>{$status}</td>";
                    echo "</tr>";
                }
                echo "</table>";

                echo "<p class='success'>✓ Trovati $activeTenants tenant attivi</p>";
            }

            // 2. Verifica se mancano tenant essenziali
            echo "<h2>2. Verifica Tenant Essenziali</h2>";

            $essentialTenants = [
                1 => "Demo Company",
                2 => "Test Company 2"
            ];

            $missingTenants = [];
            foreach ($essentialTenants as $id => $name) {
                $stmt = $conn->prepare("SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$id]);

                if (!$stmt->fetch()) {
                    $missingTenants[] = ['id' => $id, 'name' => $name];
                    echo "<p class='warning'>⚠ Tenant mancante: ID $id - $name</p>";
                } else {
                    echo "<p class='success'>✓ Tenant ID $id presente</p>";
                }
            }

            // 3. Crea tenant mancanti
            if (!empty($missingTenants)) {
                echo "<div class='info-box'>";
                echo "<h3>Creazione Tenant Mancanti</h3>";

                if (isset($_POST['create_tenants'])) {
                    foreach ($missingTenants as $tenant) {
                        try {
                            $sql = "INSERT INTO tenants (id, name, created_at) VALUES (?, ?, NOW())
                                    ON DUPLICATE KEY UPDATE deleted_at = NULL, name = VALUES(name)";
                            $stmt = $conn->prepare($sql);
                            $stmt->execute([$tenant['id'], $tenant['name']]);
                            echo "<p class='success'>✓ Tenant creato/riattivato: {$tenant['name']}</p>";
                        } catch (Exception $e) {
                            echo "<p class='error'>❌ Errore creazione tenant {$tenant['name']}: " . $e->getMessage() . "</p>";
                        }
                    }
                    echo "<p><a href=''>Ricarica la pagina</a></p>";
                } else {
                    echo "<form method='POST'>";
                    echo "<p>Vuoi creare i tenant mancanti?</p>";
                    echo "<button type='submit' name='create_tenants' class='create-btn'>Crea Tenant Mancanti</button>";
                    echo "</form>";
                }
                echo "</div>";
            }

            // 4. Verifica tabella user_tenant_access
            echo "<h2>3. Verifica Tabella user_tenant_access</h2>";

            $stmt = $conn->query("SHOW TABLES LIKE 'user_tenant_access'");
            if ($stmt->fetch()) {
                echo "<p class='success'>✓ Tabella user_tenant_access esiste</p>";

                // Verifica struttura
                $stmt = $conn->query("DESCRIBE user_tenant_access");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $requiredColumns = ['user_id', 'tenant_id', 'created_at'];
                $missingColumns = array_diff($requiredColumns, $columns);

                if (empty($missingColumns)) {
                    echo "<p class='success'>✓ Struttura tabella corretta</p>";
                } else {
                    echo "<p class='error'>❌ Colonne mancanti: " . implode(', ', $missingColumns) . "</p>";
                }
            } else {
                echo "<p class='error'>❌ Tabella user_tenant_access non trovata!</p>";
                echo "<p>Esegui la migrazione del database per creare questa tabella.</p>";
            }

            // 5. Info sessione corrente
            echo "<h2>4. Sessione Corrente</h2>";
            echo "<div class='info-box'>";
            echo "<p><strong>User ID:</strong> " . ($_SESSION['user_id'] ?? 'Non impostato') . "</p>";
            echo "<p><strong>Tenant ID:</strong> " . ($_SESSION['tenant_id'] ?? 'Non impostato') . "</p>";
            echo "<p><strong>Ruolo:</strong> " . ($_SESSION['role'] ?? 'Non impostato') . "</p>";
            echo "<p><strong>CSRF Token:</strong> " . (isset($_SESSION['csrf_token']) ? 'Presente' : 'Non presente') . "</p>";
            echo "</div>";

            // 6. Test rapido creazione utente
            echo "<h2>5. Test API Creazione Utente</h2>";
            echo "<div class='info-box'>";
            echo "<p>Per testare la creazione utente:</p>";
            echo "<ol>";
            echo "<li>Assicurati di essere loggato come admin o super_admin</li>";
            echo "<li>Vai a <a href='utenti.php'>Gestione Utenti</a></li>";
            echo "<li>Apri la Console del browser (F12)</li>";
            echo "<li>Prova a creare un nuovo utente</li>";
            echo "<li>Controlla i log nella console per dettagli sull'errore</li>";
            echo "</ol>";
            echo "<p>Oppure usa <a href='test_user_creation.php'>Test Creazione Utenti</a> per test automatici</p>";
            echo "</div>";

        } catch (Exception $e) {
            echo "<div class='error'>";
            echo "<h2>Errore Database</h2>";
            echo "<p>" . $e->getMessage() . "</p>";
            echo "</div>";
        }
        ?>

        <hr style="margin-top: 40px;">
        <p><a href="dashboard.php">← Torna al Dashboard</a> | <a href="utenti.php">Gestione Utenti →</a></p>
    </div>
</body>
</html>