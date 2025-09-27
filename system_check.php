<?php
session_start();

// Configurazione base
define('DB_HOST', 'localhost');
define('DB_NAME', 'collaboranexio');
define('DB_USER', 'root');
define('DB_PASS', '');

// Colori per output console
$colors = [
    'success' => '#28a745',
    'warning' => '#ffc107',
    'danger' => '#dc3545',
    'info' => '#17a2b8',
    'primary' => '#007bff'
];

// Funzione per testare connessione database
function testDatabaseConnection() {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return ['status' => 'success', 'message' => 'Connesso', 'pdo' => $pdo];
    } catch (PDOException $e) {
        return ['status' => 'danger', 'message' => 'Errore: ' . $e->getMessage()];
    }
}

// Funzione per verificare tabelle
function checkTables($pdo) {
    $required_tables = [
        'tenants' => 'Organizzazioni multi-tenant',
        'users' => 'Utenti del sistema',
        'projects' => 'Progetti',
        'project_members' => 'Membri dei progetti',
        'project_milestones' => 'Milestone progetti',
        'tasks' => 'Attività/Task',
        'task_comments' => 'Commenti task',
        'folders' => 'Cartelle file',
        'files' => 'File caricati',
        'file_shares' => 'Condivisioni file',
        'file_versions' => 'Versioni file',
        'chat_channels' => 'Canali chat',
        'chat_channel_members' => 'Membri canali',
        'chat_messages' => 'Messaggi chat',
        'calendar_events' => 'Eventi calendario',
        'event_attendees' => 'Partecipanti eventi',
        'notifications' => 'Notifiche',
        'audit_logs' => 'Log audit',
        'sessions' => 'Sessioni utente',
        'password_resets' => 'Reset password',
        'rate_limits' => 'Rate limiting',
        'system_settings' => 'Impostazioni sistema'
    ];

    $results = [];
    foreach ($required_tables as $table => $description) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
            $count = $stmt->fetch()['count'];
            $results[] = [
                'table' => $table,
                'description' => $description,
                'status' => 'success',
                'records' => $count
            ];
        } catch (PDOException $e) {
            $results[] = [
                'table' => $table,
                'description' => $description,
                'status' => 'danger',
                'records' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    return $results;
}

// Funzione per verificare file di sistema
function checkSystemFiles() {
    $files = [
        '/config/config.php' => 'Configurazione principale',
        '/includes/auth.php' => 'Sistema autenticazione',
        '/includes/db.php' => 'Connessione database',
        '/api/auth.php' => 'API autenticazione',
        '/api/dashboard.php' => 'API dashboard',
        '/api/events.php' => 'API calendario',
        '/api/files.php' => 'API file',
        '/api/files_complete.php' => 'API file completa',
        '/api/projects_complete.php' => 'API progetti completa',
        '/api/tasks.php' => 'API task',
        '/api/channels.php' => 'API canali chat',
        '/api/messages.php' => 'API messaggi chat',
        '/dashboard.php' => 'Pagina dashboard',
        '/utenti.php' => 'Pagina utenti',
        '/files.php' => 'Pagina file manager',
        '/calendar.php' => 'Pagina calendario',
        '/tasks.php' => 'Pagina task',
        '/chat.php' => 'Pagina chat',
        '/login.php' => 'Pagina login',
        '/logout.php' => 'Pagina logout'
    ];

    $results = [];
    $base_dir = __DIR__;

    foreach ($files as $path => $description) {
        $full_path = $base_dir . $path;
        if (file_exists($full_path)) {
            $size = filesize($full_path);
            $results[] = [
                'file' => $path,
                'description' => $description,
                'status' => 'success',
                'size' => number_format($size / 1024, 2) . ' KB'
            ];
        } else {
            $results[] = [
                'file' => $path,
                'description' => $description,
                'status' => 'warning',
                'size' => 'Non trovato'
            ];
        }
    }
    return $results;
}

// Funzione per testare API endpoints
function testApiEndpoints() {
    $base_url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);

    $endpoints = [
        '/api/auth.php' => 'POST - Login',
        '/api/dashboard.php' => 'GET - Dashboard stats',
        '/api/files.php' => 'GET - Lista file',
        '/api/projects_complete.php?path=list' => 'GET - Lista progetti',
        '/api/tasks.php' => 'GET - Lista task',
        '/api/events.php' => 'GET - Lista eventi',
        '/api/channels.php' => 'GET - Lista canali chat'
    ];

    $results = [];

    foreach ($endpoints as $endpoint => $description) {
        $url = $base_url . $endpoint;

        // Simula richiesta (semplificata per test)
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Content-Type: application/json',
                'timeout' => 5,
                'ignore_errors' => true
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        $http_code = isset($http_response_header[0]) ?
            (int)substr($http_response_header[0], 9, 3) : 0;

        if ($http_code === 401) {
            // 401 è ok, significa che l'API risponde ma richiede autenticazione
            $status = 'success';
            $message = 'API attiva (richiede auth)';
        } elseif ($http_code === 200) {
            $status = 'success';
            $message = 'API attiva';
        } elseif ($http_code > 0) {
            $status = 'warning';
            $message = 'HTTP ' . $http_code;
        } else {
            $status = 'danger';
            $message = 'Non raggiungibile';
        }

        $results[] = [
            'endpoint' => $endpoint,
            'description' => $description,
            'status' => $status,
            'message' => $message
        ];
    }

    return $results;
}

// Funzione per verificare permessi directory
function checkDirectoryPermissions() {
    $directories = [
        '/storage' => 'Directory storage file',
        '/storage/logs' => 'Directory logs',
        '/storage/cache' => 'Directory cache',
        '/storage/tenants' => 'Directory tenant files',
        '/backups' => 'Directory backup'
    ];

    $results = [];
    $base_dir = __DIR__;

    foreach ($directories as $path => $description) {
        $full_path = $base_dir . $path;

        if (!file_exists($full_path)) {
            @mkdir($full_path, 0755, true);
        }

        $exists = file_exists($full_path);
        $writable = is_writable($full_path);

        $results[] = [
            'directory' => $path,
            'description' => $description,
            'exists' => $exists,
            'writable' => $writable,
            'status' => ($exists && $writable) ? 'success' : 'warning'
        ];
    }

    return $results;
}

// Funzione per verificare configurazioni PHP
function checkPhpConfiguration() {
    $requirements = [
        'PHP Version' => [
            'required' => '8.0.0',
            'current' => PHP_VERSION,
            'check' => version_compare(PHP_VERSION, '8.0.0', '>=')
        ],
        'PDO MySQL' => [
            'required' => 'Abilitato',
            'current' => extension_loaded('pdo_mysql') ? 'Abilitato' : 'Disabilitato',
            'check' => extension_loaded('pdo_mysql')
        ],
        'JSON' => [
            'required' => 'Abilitato',
            'current' => extension_loaded('json') ? 'Abilitato' : 'Disabilitato',
            'check' => extension_loaded('json')
        ],
        'Session' => [
            'required' => 'Abilitato',
            'current' => extension_loaded('session') ? 'Abilitato' : 'Disabilitato',
            'check' => extension_loaded('session')
        ],
        'Upload Max Size' => [
            'required' => '>= 10M',
            'current' => ini_get('upload_max_filesize'),
            'check' => true
        ],
        'Post Max Size' => [
            'required' => '>= 10M',
            'current' => ini_get('post_max_size'),
            'check' => true
        ],
        'Memory Limit' => [
            'required' => '>= 128M',
            'current' => ini_get('memory_limit'),
            'check' => true
        ]
    ];

    $results = [];
    foreach ($requirements as $name => $req) {
        $results[] = [
            'requirement' => $name,
            'required' => $req['required'],
            'current' => $req['current'],
            'status' => $req['check'] ? 'success' : 'danger'
        ];
    }

    return $results;
}

// Esegui tutti i test
$db_test = testDatabaseConnection();
$tables = [];
$file_checks = checkSystemFiles();
$api_tests = testApiEndpoints();
$dir_permissions = checkDirectoryPermissions();
$php_config = checkPhpConfiguration();

if ($db_test['status'] === 'success') {
    $tables = checkTables($db_test['pdo']);
}

// Calcola statistiche
$total_tables = count($tables);
$ok_tables = count(array_filter($tables, fn($t) => $t['status'] === 'success'));
$total_files = count($file_checks);
$ok_files = count(array_filter($file_checks, fn($f) => $f['status'] === 'success'));
$total_apis = count($api_tests);
$ok_apis = count(array_filter($api_tests, fn($a) => $a['status'] === 'success'));
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - System Check</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .check-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        .check-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .check-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            font-size: 1.2rem;
            font-weight: 600;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .status-success { background: #d4edda; color: #155724; }
        .status-warning { background: #fff3cd; color: #856404; }
        .status-danger { background: #f8d7da; color: #721c24; }
        .status-info { background: #d1ecf1; color: #0c5460; }

        .summary-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            background: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        .summary-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        .summary-value {
            font-size: 2rem;
            font-weight: 700;
        }
        .summary-label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .check-table {
            margin: 0;
        }
        .check-table td, .check-table th {
            padding: 12px 20px;
            vertical-align: middle;
        }
        .check-table tbody tr {
            border-bottom: 1px solid #f0f0f0;
        }
        .check-table tbody tr:last-child {
            border-bottom: none;
        }

        .icon-check { color: #28a745; }
        .icon-warning { color: #ffc107; }
        .icon-danger { color: #dc3545; }

        .progress-ring {
            transform: rotate(-90deg);
        }
        .progress-ring-circle {
            stroke-dasharray: 251.2;
            stroke-dashoffset: 0;
            transition: stroke-dashoffset 1s;
            stroke-linecap: round;
        }
    </style>
</head>
<body>
    <div class="check-container">
        <!-- Header -->
        <div class="text-center text-white mb-5">
            <h1 class="display-4 fw-bold mb-3">
                <i class="bi bi-gear-wide-connected"></i> CollaboraNexio System Check
            </h1>
            <p class="lead">Verifica completa dello stato del sistema e delle componenti</p>
        </div>

        <!-- Summary Cards -->
        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-icon text-primary">
                        <i class="bi bi-database"></i>
                    </div>
                    <div class="summary-value"><?php echo $ok_tables; ?>/<?php echo $total_tables; ?></div>
                    <div class="summary-label">Tabelle Database</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-icon text-success">
                        <i class="bi bi-file-code"></i>
                    </div>
                    <div class="summary-value"><?php echo $ok_files; ?>/<?php echo $total_files; ?></div>
                    <div class="summary-label">File Sistema</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-icon text-info">
                        <i class="bi bi-plug"></i>
                    </div>
                    <div class="summary-value"><?php echo $ok_apis; ?>/<?php echo $total_apis; ?></div>
                    <div class="summary-label">API Endpoints</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-card">
                    <div class="summary-icon text-warning">
                        <i class="bi bi-server"></i>
                    </div>
                    <div class="summary-value">
                        <?php
                        $overall = ($ok_tables == $total_tables && $ok_files == $total_files) ? 100 :
                                  round((($ok_tables + $ok_files + $ok_apis) / ($total_tables + $total_files + $total_apis)) * 100);
                        echo $overall;
                        ?>%
                    </div>
                    <div class="summary-label">Stato Generale</div>
                </div>
            </div>
        </div>

        <!-- Database Connection -->
        <div class="check-card">
            <div class="check-header">
                <i class="bi bi-database"></i> Connessione Database
            </div>
            <div class="p-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <strong>Host:</strong> <?php echo DB_HOST; ?> |
                        <strong>Database:</strong> <?php echo DB_NAME; ?>
                    </div>
                    <span class="status-badge status-<?php echo $db_test['status']; ?>">
                        <?php echo $db_test['message']; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Database Tables -->
        <?php if (!empty($tables)): ?>
        <div class="check-card">
            <div class="check-header">
                <i class="bi bi-table"></i> Tabelle Database (<?php echo $ok_tables; ?>/<?php echo $total_tables; ?>)
            </div>
            <div class="table-responsive">
                <table class="table check-table mb-0">
                    <thead>
                        <tr>
                            <th width="30"></th>
                            <th>Tabella</th>
                            <th>Descrizione</th>
                            <th>Record</th>
                            <th>Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $table): ?>
                        <tr>
                            <td>
                                <?php if ($table['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill icon-check"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill icon-danger"></i>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo $table['table']; ?></code></td>
                            <td><?php echo $table['description']; ?></td>
                            <td>
                                <span class="badge bg-secondary"><?php echo $table['records']; ?></span>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $table['status']; ?>">
                                    <?php echo $table['status'] === 'success' ? 'OK' : 'Errore'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- System Files -->
        <div class="check-card">
            <div class="check-header">
                <i class="bi bi-folder-check"></i> File di Sistema (<?php echo $ok_files; ?>/<?php echo $total_files; ?>)
            </div>
            <div class="table-responsive">
                <table class="table check-table mb-0">
                    <thead>
                        <tr>
                            <th width="30"></th>
                            <th>File</th>
                            <th>Descrizione</th>
                            <th>Dimensione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($file_checks as $file): ?>
                        <tr>
                            <td>
                                <?php if ($file['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill icon-check"></i>
                                <?php else: ?>
                                    <i class="bi bi-exclamation-triangle-fill icon-warning"></i>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo $file['file']; ?></code></td>
                            <td><?php echo $file['description']; ?></td>
                            <td>
                                <?php if ($file['status'] === 'success'): ?>
                                    <span class="text-muted"><?php echo $file['size']; ?></span>
                                <?php else: ?>
                                    <span class="status-badge status-warning">Non trovato</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- API Endpoints -->
        <div class="check-card">
            <div class="check-header">
                <i class="bi bi-plug"></i> API Endpoints (<?php echo $ok_apis; ?>/<?php echo $total_apis; ?>)
            </div>
            <div class="table-responsive">
                <table class="table check-table mb-0">
                    <thead>
                        <tr>
                            <th width="30"></th>
                            <th>Endpoint</th>
                            <th>Descrizione</th>
                            <th>Stato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($api_tests as $api): ?>
                        <tr>
                            <td>
                                <?php if ($api['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill icon-check"></i>
                                <?php elseif ($api['status'] === 'warning'): ?>
                                    <i class="bi bi-exclamation-triangle-fill icon-warning"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill icon-danger"></i>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo $api['endpoint']; ?></code></td>
                            <td><?php echo $api['description']; ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $api['status']; ?>">
                                    <?php echo $api['message']; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Directory Permissions -->
        <div class="check-card">
            <div class="check-header">
                <i class="bi bi-shield-check"></i> Permessi Directory
            </div>
            <div class="table-responsive">
                <table class="table check-table mb-0">
                    <thead>
                        <tr>
                            <th width="30"></th>
                            <th>Directory</th>
                            <th>Descrizione</th>
                            <th>Esiste</th>
                            <th>Scrivibile</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dir_permissions as $dir): ?>
                        <tr>
                            <td>
                                <?php if ($dir['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill icon-check"></i>
                                <?php else: ?>
                                    <i class="bi bi-exclamation-triangle-fill icon-warning"></i>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo $dir['directory']; ?></code></td>
                            <td><?php echo $dir['description']; ?></td>
                            <td>
                                <?php if ($dir['exists']): ?>
                                    <span class="badge bg-success">Sì</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($dir['writable']): ?>
                                    <span class="badge bg-success">Sì</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">No</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PHP Configuration -->
        <div class="check-card">
            <div class="check-header">
                <i class="bi bi-gear"></i> Configurazione PHP
            </div>
            <div class="table-responsive">
                <table class="table check-table mb-0">
                    <thead>
                        <tr>
                            <th width="30"></th>
                            <th>Requisito</th>
                            <th>Richiesto</th>
                            <th>Attuale</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($php_config as $config): ?>
                        <tr>
                            <td>
                                <?php if ($config['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill icon-check"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill icon-danger"></i>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $config['requirement']; ?></td>
                            <td><code><?php echo $config['required']; ?></code></td>
                            <td>
                                <code class="text-<?php echo $config['status'] === 'success' ? 'success' : 'danger'; ?>">
                                    <?php echo $config['current']; ?>
                                </code>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Actions -->
        <div class="text-center mt-5 mb-4">
            <a href="/login.php" class="btn btn-lg btn-primary me-3">
                <i class="bi bi-box-arrow-in-right"></i> Vai al Login
            </a>
            <a href="/dashboard.php" class="btn btn-lg btn-success me-3">
                <i class="bi bi-speedometer2"></i> Vai alla Dashboard
            </a>
            <button onclick="window.location.reload()" class="btn btn-lg btn-secondary">
                <i class="bi bi-arrow-clockwise"></i> Ricarica Test
            </button>
        </div>

        <!-- Quick Links -->
        <div class="row mt-5">
            <div class="col-md-12">
                <div class="check-card">
                    <div class="check-header">
                        <i class="bi bi-link-45deg"></i> Link Rapidi Sistema
                    </div>
                    <div class="p-4">
                        <div class="row">
                            <div class="col-md-4">
                                <h6 class="text-muted mb-3">Pagine Principali</h6>
                                <div class="list-group list-group-flush">
                                    <a href="/login.php" class="list-group-item list-group-item-action">
                                        <i class="bi bi-box-arrow-in-right"></i> Login
                                    </a>
                                    <a href="/dashboard.php" class="list-group-item list-group-item-action">
                                        <i class="bi bi-speedometer2"></i> Dashboard
                                    </a>
                                    <a href="/utenti.php" class="list-group-item list-group-item-action">
                                        <i class="bi bi-people"></i> Utenti
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <h6 class="text-muted mb-3">Moduli Applicazione</h6>
                                <div class="list-group list-group-flush">
                                    <a href="/files.php" class="list-group-item list-group-item-action">
                                        <i class="bi bi-folder"></i> File Manager
                                    </a>
                                    <a href="/calendar.php" class="list-group-item list-group-item-action">
                                        <i class="bi bi-calendar3"></i> Calendario
                                    </a>
                                    <a href="/tasks.php" class="list-group-item list-group-item-action">
                                        <i class="bi bi-check2-square"></i> Tasks
                                    </a>
                                    <a href="/chat.php" class="list-group-item list-group-item-action">
                                        <i class="bi bi-chat-dots"></i> Chat
                                    </a>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <h6 class="text-muted mb-3">Utilità</h6>
                                <div class="list-group list-group-flush">
                                    <a href="/database/04_demo_data.sql" class="list-group-item list-group-item-action">
                                        <i class="bi bi-database-add"></i> SQL Demo Data
                                    </a>
                                    <a href="/api/system/info.php" class="list-group-item list-group-item-action">
                                        <i class="bi bi-info-circle"></i> System Info API
                                    </a>
                                    <a href="/phpinfo.php" class="list-group-item list-group-item-action">
                                        <i class="bi bi-filetype-php"></i> PHP Info
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>