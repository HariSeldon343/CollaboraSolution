<?php
/**
 * CollaboraNexio - Script di Test Connessione e Verifica Installazione
 *
 * Questo script verifica tutti i componenti dell'installazione
 * e può essere utilizzato sia via browser che via CLI
 */

// Imposta modalità di output
$is_cli = php_sapi_name() === 'cli';
$output = [];
$all_tests_passed = true;

// Header per output JSON se non CLI
if (!$is_cli) {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache, no-store, must-revalidate');
}

// Funzione helper per aggiungere risultati test
function add_test_result(&$output, $category, $test_name, $status, $message = '', $details = null) {
    global $all_tests_passed;

    if (!isset($output[$category])) {
        $output[$category] = [
            'tests' => [],
            'passed' => 0,
            'failed' => 0,
            'warnings' => 0
        ];
    }

    $result = [
        'name' => $test_name,
        'status' => $status,
        'message' => $message
    ];

    if ($details !== null) {
        $result['details'] = $details;
    }

    $output[$category]['tests'][] = $result;

    switch($status) {
        case 'success':
            $output[$category]['passed']++;
            break;
        case 'error':
            $output[$category]['failed']++;
            $all_tests_passed = false;
            break;
        case 'warning':
            $output[$category]['warnings']++;
            break;
    }
}

// 1. Test File di Configurazione
$config_file = __DIR__ . '/config.php';
if (file_exists($config_file)) {
    add_test_result($output, 'configuration', 'File config.php', 'success', 'File di configurazione trovato');

    // Verifica che sia leggibile
    if (is_readable($config_file)) {
        add_test_result($output, 'configuration', 'Lettura config.php', 'success', 'File leggibile');

        // Include il file di configurazione
        $config_error = false;
        try {
            // Cattura eventuali errori PHP
            $old_error_handler = set_error_handler(function($severity, $message, $file, $line) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            });

            require_once $config_file;

            restore_error_handler();

            add_test_result($output, 'configuration', 'Parsing config.php', 'success', 'Configurazione caricata correttamente');
        } catch (Exception $e) {
            restore_error_handler();
            add_test_result($output, 'configuration', 'Parsing config.php', 'error',
                'Errore nel parsing: ' . $e->getMessage());
            $config_error = true;
        }

        // Verifica costanti definite
        if (!$config_error) {
            $required_constants = [
                'DB_HOST' => 'Host database',
                'DB_NAME' => 'Nome database',
                'DB_USER' => 'Utente database',
                'DB_PASS' => 'Password database',
                'JWT_SECRET' => 'Chiave JWT',
                'UPLOAD_DIR' => 'Directory upload'
            ];

            foreach ($required_constants as $constant => $description) {
                if (defined($constant)) {
                    add_test_result($output, 'configuration', $description, 'success',
                        "Costante $constant definita");
                } else {
                    add_test_result($output, 'configuration', $description, 'error',
                        "Costante $constant non definita");
                }
            }
        }
    } else {
        add_test_result($output, 'configuration', 'Lettura config.php', 'error', 'File non leggibile');
    }
} else {
    add_test_result($output, 'configuration', 'File config.php', 'error', 'File di configurazione non trovato');
}

// 2. Test Connessione Database
if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        add_test_result($output, 'database', 'Connessione MySQL', 'success',
            'Connesso al database ' . DB_NAME);

        // Verifica versione MySQL
        $stmt = $pdo->query("SELECT VERSION() as version");
        $version = $stmt->fetch();
        add_test_result($output, 'database', 'Versione MySQL', 'success',
            'MySQL ' . $version['version']);

        // 3. Verifica Tabelle
        $required_tables = [
            'users' => ['id', 'email', 'password', 'first_name', 'last_name', 'tenant_id'],
            'tenants' => ['id', 'name', 'subdomain', 'created_at'],
            'projects' => ['id', 'tenant_id', 'name', 'description'],
            'tasks' => ['id', 'project_id', 'title', 'status'],
            'messages' => ['id', 'sender_id', 'recipient_id', 'content'],
            'documents' => ['id', 'tenant_id', 'name', 'file_path'],
            'timesheets' => ['id', 'user_id', 'project_id', 'date', 'hours'],
            'roles' => ['id', 'name', 'permissions'],
            'user_roles' => ['user_id', 'role_id']
        ];

        foreach ($required_tables as $table => $required_columns) {
            try {
                // Verifica esistenza tabella
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                if ($stmt->rowCount() > 0) {
                    add_test_result($output, 'tables', "Tabella $table", 'success', 'Tabella presente');

                    // Verifica colonne principali
                    $stmt = $pdo->query("SHOW COLUMNS FROM $table");
                    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

                    $missing_columns = array_diff($required_columns, $columns);
                    if (empty($missing_columns)) {
                        add_test_result($output, 'tables', "Struttura $table", 'success',
                            'Tutte le colonne richieste presenti');
                    } else {
                        add_test_result($output, 'tables', "Struttura $table", 'warning',
                            'Colonne mancanti: ' . implode(', ', $missing_columns));
                    }

                    // Conta record
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                    $count = $stmt->fetch();
                    add_test_result($output, 'tables', "Dati $table", 'success',
                        $count['count'] . ' record presenti');
                } else {
                    add_test_result($output, 'tables', "Tabella $table", 'error', 'Tabella non trovata');
                }
            } catch (PDOException $e) {
                add_test_result($output, 'tables', "Tabella $table", 'error',
                    'Errore verifica: ' . $e->getMessage());
            }
        }

        // 4. Test Utenti Demo
        $demo_users = [
            'asamodeo@fortibyte.it' => 'Ricord@1991',
            'user@demo.com' => 'Demo123!',
            'special@demo.com' => 'Special123!'
        ];

        foreach ($demo_users as $email => $password) {
            try {
                $stmt = $pdo->prepare("SELECT id, email, password, first_name, last_name, is_active
                                      FROM users WHERE email = :email");
                $stmt->execute([':email' => $email]);

                if ($user = $stmt->fetch()) {
                    add_test_result($output, 'users', "Utente $email", 'success',
                        'Utente trovato: ' . $user['first_name'] . ' ' . $user['last_name']);

                    // Test password con bcrypt
                    if (password_verify($password, $user['password'])) {
                        add_test_result($output, 'users', "Password $email", 'success',
                            'Password verificata correttamente');
                    } else {
                        add_test_result($output, 'users', "Password $email", 'error',
                            'Password non corrisponde');
                    }

                    // Verifica stato attivo
                    if ($user['is_active'] == 1) {
                        add_test_result($output, 'users', "Stato $email", 'success', 'Utente attivo');
                    } else {
                        add_test_result($output, 'users', "Stato $email", 'warning', 'Utente non attivo');
                    }
                } else {
                    add_test_result($output, 'users', "Utente $email", 'error', 'Utente non trovato');
                }
            } catch (PDOException $e) {
                add_test_result($output, 'users', "Utente $email", 'error',
                    'Errore query: ' . $e->getMessage());
            }
        }

        // 5. Test integrità referenziale
        try {
            // Verifica foreign keys
            $stmt = $pdo->query("
                SELECT
                    TABLE_NAME,
                    CONSTRAINT_NAME,
                    REFERENCED_TABLE_NAME
                FROM
                    INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                WHERE
                    REFERENCED_TABLE_NAME IS NOT NULL
                    AND CONSTRAINT_SCHEMA = '" . DB_NAME . "'
            ");

            $fk_count = $stmt->rowCount();
            if ($fk_count > 0) {
                add_test_result($output, 'database', 'Foreign Keys', 'success',
                    "$fk_count vincoli di integrità trovati");
            } else {
                add_test_result($output, 'database', 'Foreign Keys', 'warning',
                    'Nessun vincolo foreign key trovato');
            }
        } catch (PDOException $e) {
            add_test_result($output, 'database', 'Foreign Keys', 'warning',
                'Impossibile verificare foreign keys');
        }

    } catch (PDOException $e) {
        add_test_result($output, 'database', 'Connessione MySQL', 'error',
            'Errore connessione: ' . $e->getMessage());
    }
} else {
    add_test_result($output, 'database', 'Connessione MySQL', 'error',
        'Parametri database non configurati');
}

// 6. Test Permessi Directory
$directories_to_check = [
    'uploads' => __DIR__ . '/uploads',
    'logs' => __DIR__ . '/logs',
    'temp' => __DIR__ . '/temp',
    'api' => __DIR__ . '/api',
    'includes' => __DIR__ . '/includes',
    'assets' => __DIR__ . '/assets'
];

foreach ($directories_to_check as $name => $path) {
    if (file_exists($path)) {
        if (is_dir($path)) {
            add_test_result($output, 'filesystem', "Directory $name", 'success', 'Directory esistente');

            // Test scrittura (solo per directory che devono essere scrivibili)
            $writable_dirs = ['uploads', 'logs', 'temp'];
            if (in_array($name, $writable_dirs)) {
                if (is_writable($path)) {
                    add_test_result($output, 'filesystem', "Permessi $name", 'success', 'Directory scrivibile');

                    // Test scrittura effettiva
                    $test_file = $path . '/test_' . time() . '.tmp';
                    if (@file_put_contents($test_file, 'test') !== false) {
                        @unlink($test_file);
                        add_test_result($output, 'filesystem', "Scrittura $name", 'success',
                            'Test scrittura riuscito');
                    } else {
                        add_test_result($output, 'filesystem', "Scrittura $name", 'error',
                            'Impossibile scrivere nella directory');
                    }
                } else {
                    add_test_result($output, 'filesystem', "Permessi $name", 'error',
                        'Directory non scrivibile');
                }
            }
        } else {
            add_test_result($output, 'filesystem', "Directory $name", 'error',
                'Il percorso esiste ma non è una directory');
        }
    } else {
        add_test_result($output, 'filesystem', "Directory $name", 'warning', 'Directory non esistente');
    }
}

// 7. Test PHP Extensions
$required_extensions = [
    'pdo' => 'PDO',
    'pdo_mysql' => 'PDO MySQL',
    'mbstring' => 'Multibyte String',
    'json' => 'JSON',
    'session' => 'Sessions',
    'openssl' => 'OpenSSL',
    'fileinfo' => 'File Info',
    'gd' => 'GD Library'
];

foreach ($required_extensions as $ext => $name) {
    if (extension_loaded($ext)) {
        add_test_result($output, 'php', "Estensione $name", 'success', 'Estensione caricata');
    } else {
        add_test_result($output, 'php', "Estensione $name", 'error', 'Estensione non disponibile');
    }
}

// Test versione PHP
$php_version = phpversion();
if (version_compare($php_version, '8.0.0', '>=')) {
    add_test_result($output, 'php', 'Versione PHP', 'success', "PHP $php_version");
} else {
    add_test_result($output, 'php', 'Versione PHP', 'warning',
        "PHP $php_version (raccomandata 8.0+)");
}

// 8. Test Upload Configuration
$upload_max = ini_get('upload_max_filesize');
$post_max = ini_get('post_max_size');
$memory_limit = ini_get('memory_limit');

add_test_result($output, 'php', 'Upload Max Filesize', 'success', $upload_max, [
    'bytes' => return_bytes($upload_max)
]);
add_test_result($output, 'php', 'Post Max Size', 'success', $post_max, [
    'bytes' => return_bytes($post_max)
]);
add_test_result($output, 'php', 'Memory Limit', 'success', $memory_limit, [
    'bytes' => return_bytes($memory_limit)
]);

// 9. Riepilogo finale
$summary = [
    'timestamp' => date('Y-m-d H:i:s'),
    'overall_status' => $all_tests_passed ? 'success' : 'failed',
    'categories' => []
];

foreach ($output as $category => $results) {
    $summary['categories'][$category] = [
        'passed' => $results['passed'],
        'failed' => $results['failed'],
        'warnings' => $results['warnings'],
        'total' => count($results['tests'])
    ];
}

// Output finale
$final_output = [
    'summary' => $summary,
    'details' => $output
];

// Se CLI, formatta l'output in modo leggibile
if ($is_cli) {
    echo "\n";
    echo "=====================================\n";
    echo " CollaboraNexio - Test di Verifica  \n";
    echo "=====================================\n\n";

    foreach ($output as $category => $results) {
        echo strtoupper($category) . ":\n";
        echo str_repeat('-', 35) . "\n";

        foreach ($results['tests'] as $test) {
            $status_symbol = match($test['status']) {
                'success' => '[✓]',
                'error' => '[✗]',
                'warning' => '[!]',
                default => '[ ]'
            };

            echo "$status_symbol {$test['name']}: {$test['message']}\n";
        }

        echo "\n";
    }

    echo "=====================================\n";
    echo " RIEPILOGO FINALE                   \n";
    echo "=====================================\n";

    foreach ($summary['categories'] as $category => $stats) {
        echo sprintf(
            "%s: %d passati, %d falliti, %d avvisi (totale: %d)\n",
            ucfirst($category),
            $stats['passed'],
            $stats['failed'],
            $stats['warnings'],
            $stats['total']
        );
    }

    echo "\nStato generale: " . ($all_tests_passed ? "SUCCESSO" : "FALLITO") . "\n";
    echo "Data test: " . $summary['timestamp'] . "\n\n";
} else {
    // Output JSON per browser
    echo json_encode($final_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Funzione helper per convertire dimensioni in byte
function return_bytes($val) {
    $val = trim($val);
    if (empty($val)) return 0;

    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;

    switch($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }

    return $val;
}