<?php
/**
 * Script per eseguire la migrazione del sistema di prima password
 * Eseguire da browser: http://localhost:8888/CollaboraNexio/run_first_password_migration.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Set headers
header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>
<html>
<head>
    <title>Migrazione Sistema Prima Password</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #333; }
        .success { color: green; }
        .error { color: red; }
        .skip { color: orange; }
        .info { color: blue; }
        pre { background: #f0f0f0; padding: 10px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Migrazione Sistema Prima Password</h1>';

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    echo '<h2>Esecuzione migrazione...</h2>';

    // Array di query SQL da eseguire
    $queries = [
        "Aggiunta colonna password_reset_token" => "
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(255) DEFAULT NULL AFTER password_hash
        ",

        "Aggiunta colonna password_reset_expires" => "
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS password_reset_expires DATETIME DEFAULT NULL AFTER password_reset_token
        ",

        "Aggiunta colonna first_login" => "
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS first_login BOOLEAN DEFAULT TRUE AFTER password_reset_expires
        ",

        "Aggiunta colonna password_set_at" => "
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS password_set_at DATETIME DEFAULT NULL AFTER first_login
        ",

        "Aggiunta colonna welcome_email_sent_at" => "
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS welcome_email_sent_at DATETIME DEFAULT NULL AFTER password_set_at
        ",

        "Aggiunta indice su password_reset_token" => "
            CREATE INDEX IF NOT EXISTS idx_password_reset_token ON users(password_reset_token)
        ",

        "Aggiornamento utenti esistenti" => "
            UPDATE users
            SET first_login = FALSE
            WHERE password_hash IS NOT NULL AND password_hash != ''
        ",

        "Creazione tabella password_reset_attempts" => "
            CREATE TABLE IF NOT EXISTS password_reset_attempts (
                id INT(11) AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                success BOOLEAN DEFAULT FALSE,
                INDEX idx_email_attempts (email),
                INDEX idx_ip_attempts (ip_address),
                INDEX idx_attempted_at (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        "
    ];

    $success_count = 0;
    $skip_count = 0;
    $error_count = 0;

    foreach ($queries as $description => $query) {
        echo "<p><strong>$description</strong>: ";
        try {
            $conn->exec($query);
            echo '<span class="success">✓ Eseguita con successo</span>';
            $success_count++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false ||
                strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'Duplicate key') !== false) {
                echo '<span class="skip">⚠ Già presente (saltata)</span>';
                $skip_count++;
            } else {
                echo '<span class="error">✗ Errore: ' . htmlspecialchars($e->getMessage()) . '</span>';
                $error_count++;
            }
        }
        echo "</p>";
    }

    echo '<h2>Riepilogo Migrazione</h2>';
    echo '<ul>';
    echo '<li class="success">Query eseguite con successo: ' . $success_count . '</li>';
    echo '<li class="skip">Query saltate (già presenti): ' . $skip_count . '</li>';
    echo '<li class="error">Query con errori: ' . $error_count . '</li>';
    echo '</ul>';

    // Verifica struttura finale
    echo '<h2>Verifica Struttura Database</h2>';

    $stmt = $conn->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo '<h3>Colonne della tabella users:</h3>';
    echo '<pre>';

    $required_columns = [
        'password_reset_token' => false,
        'password_reset_expires' => false,
        'first_login' => false,
        'password_set_at' => false,
        'welcome_email_sent_at' => false
    ];

    foreach ($columns as $column) {
        $col_name = $column['Field'];
        if (isset($required_columns[$col_name])) {
            $required_columns[$col_name] = true;
            echo '<span class="success">✓ ' . $col_name . ' (' . $column['Type'] . ')</span>' . "\n";
        }
    }

    foreach ($required_columns as $col => $found) {
        if (!$found) {
            echo '<span class="error">✗ ' . $col . ' - NON TROVATA</span>' . "\n";
        }
    }
    echo '</pre>';

    // Verifica tabella password_reset_attempts
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM password_reset_attempts");
        echo '<p class="success">✓ Tabella password_reset_attempts creata correttamente</p>';
    } catch (PDOException $e) {
        echo '<p class="error">✗ Tabella password_reset_attempts non trovata</p>';
    }

    // Test invio email
    echo '<h2>Test Configurazione Email</h2>';

    if (file_exists(__DIR__ . '/includes/EmailSender.php')) {
        echo '<p class="success">✓ Classe EmailSender trovata</p>';

        require_once __DIR__ . '/includes/EmailSender.php';
        $emailSender = new EmailSender();

        // Verifica che il metodo esista
        if (method_exists($emailSender, 'sendWelcomeEmail')) {
            echo '<p class="success">✓ Metodo sendWelcomeEmail disponibile</p>';
        } else {
            echo '<p class="error">✗ Metodo sendWelcomeEmail non trovato</p>';
        }
    } else {
        echo '<p class="error">✗ Classe EmailSender non trovata</p>';
    }

    // Verifica file set_password.php
    if (file_exists(__DIR__ . '/set_password.php')) {
        echo '<p class="success">✓ Pagina set_password.php trovata</p>';
    } else {
        echo '<p class="error">✗ Pagina set_password.php non trovata</p>';
    }

    // Verifica template email
    if (file_exists(__DIR__ . '/templates/email/welcome.html')) {
        echo '<p class="success">✓ Template email welcome.html trovato</p>';
    } else {
        echo '<p class="error">✗ Template email welcome.html non trovato</p>';
    }

    if ($error_count === 0) {
        echo '<div style="margin-top: 20px; padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">
                <h3>✅ Migrazione completata con successo!</h3>
                <p>Il sistema di prima password è ora attivo. Gli utenti creati d\'ora in poi riceveranno un\'email per impostare la loro password.</p>
                <p><strong>Caratteristiche implementate:</strong></p>
                <ul>
                    <li>Rimozione campo password dal form di creazione utente</li>
                    <li>Invio automatico email di benvenuto con link sicuro</li>
                    <li>Token di reset con scadenza 24 ore</li>
                    <li>Pagina dedicata per impostazione password sicura</li>
                    <li>Validazione password forte (8+ caratteri, maiuscole, minuscole, numeri)</li>
                    <li>Rate limiting per prevenire abusi</li>
                </ul>
              </div>';
    } else {
        echo '<div style="margin-top: 20px; padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">
                <h3>⚠️ Migrazione completata con alcuni errori</h3>
                <p>Verificare gli errori sopra riportati e correggerli manualmente se necessario.</p>
              </div>';
    }

} catch (Exception $e) {
    echo '<div class="error">';
    echo '<h2>Errore durante la migrazione:</h2>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</div>';
}

echo '
    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
        <a href="utenti.php" style="padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 4px;">
            Vai a Gestione Utenti
        </a>
    </div>
</div>
</body>
</html>';
?>