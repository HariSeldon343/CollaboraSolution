<?php
/**
 * Install Phase 4: Real-time Chat System
 * CollaboraNexio Platform
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install Phase 4: Chat System - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 10px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .feature-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .feature-card h3 {
            margin-top: 0;
            color: #333;
        }
        .feature-card ul {
            margin: 10px 0;
            padding-left: 20px;
            color: #666;
        }
        .progress {
            background: #f0f0f0;
            border-radius: 10px;
            height: 30px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .progress-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .log {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        .info { color: #17a2b8; }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            margin: 10px 5px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: transform 0.2s;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn-success {
            background: #28a745;
        }
        .btn-primary {
            background: #007bff;
        }
        .actions {
            text-align: center;
            margin-top: 30px;
        }
        .status-icon {
            display: inline-block;
            width: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>💬 Install Phase 4: Real-time Chat System</h1>
        <p class="subtitle">Sistema di comunicazione in tempo reale con long-polling</p>

        <div class="feature-grid">
            <div class="feature-card">
                <h3>🚀 Core Features</h3>
                <ul>
                    <li>Canali pubblici e privati</li>
                    <li>Messaggi diretti (DM)</li>
                    <li>Thread di discussione</li>
                    <li>Indicatore "sta scrivendo"</li>
                    <li>Presenza online/offline</li>
                </ul>
            </div>
            <div class="feature-card">
                <h3>✨ Advanced Features</h3>
                <ul>
                    <li>Menzioni @utente</li>
                    <li>Formattazione Markdown</li>
                    <li>Emoji e reazioni</li>
                    <li>Condivisione file</li>
                    <li>Ricerca full-text</li>
                </ul>
            </div>
            <div class="feature-card">
                <h3>⚡ Performance</h3>
                <ul>
                    <li>Long-polling efficiente</li>
                    <li>Reconnect automatico</li>
                    <li>Backoff esponenziale</li>
                    <li>Sequence-based polling</li>
                    <li>Ottimizzato per 100+ utenti</li>
                </ul>
            </div>
        </div>

        <div class="progress">
            <div class="progress-bar" id="progressBar" style="width: 0%">0%</div>
        </div>

        <div class="log" id="log">
            <div class="info"><span class="status-icon">ℹ️</span> Starting Phase 4 installation...</div>
        </div>

        <?php
        ob_flush();
        flush();

        try {
            $db = Database::getInstance();
            $pdo = $db->getConnection();

            // Enable buffered queries
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

            // Function to update progress
            echo '<script>
                function updateProgress(percent, message) {
                    document.getElementById("progressBar").style.width = percent + "%";
                    document.getElementById("progressBar").textContent = percent + "%";
                    var log = document.getElementById("log");
                    log.innerHTML += "<div>" + message + "</div>";
                    log.scrollTop = log.scrollHeight;
                }
            </script>';

            // Check if Phase 4 SQL file exists
            $sqlFile = __DIR__ . '/install_phase4.sql';
            if (!file_exists($sqlFile)) {
                throw new Exception("install_phase4.sql not found. Please ensure the file exists.");
            }

            echo '<script>updateProgress(10, "<span class=\"status-icon\">📄</span> <span class=\"info\">Reading SQL file...</span>");</script>';
            ob_flush();
            flush();

            // Read SQL file
            $sql = file_get_contents($sqlFile);

            // Remove comments and empty lines for cleaner processing
            $sql = preg_replace('/^--.*$/m', '', $sql);
            $sql = preg_replace('/^\/\*.*?\*\//s', '', $sql);

            // Split by delimiter for procedures
            $delimiter = ';';
            $delimiterLength = 1;
            $inDelimiter = false;
            $statements = [];
            $currentStatement = '';

            $lines = explode("\n", $sql);
            foreach ($lines as $line) {
                $trimmedLine = trim($line);

                // Check for DELIMITER command
                if (stripos($trimmedLine, 'DELIMITER') === 0) {
                    if (stripos($trimmedLine, 'DELIMITER $$') !== false) {
                        $delimiter = '$$';
                        $delimiterLength = 2;
                        $inDelimiter = true;
                    } elseif (stripos($trimmedLine, 'DELIMITER ;') !== false) {
                        $delimiter = ';';
                        $delimiterLength = 1;
                        $inDelimiter = false;
                        if (!empty(trim($currentStatement))) {
                            $statements[] = $currentStatement;
                            $currentStatement = '';
                        }
                    }
                    continue;
                }

                $currentStatement .= $line . "\n";

                // Check if line ends with delimiter
                if (substr(rtrim($line), -$delimiterLength) === $delimiter) {
                    if (!$inDelimiter) {
                        $statements[] = substr($currentStatement, 0, -($delimiterLength + 1));
                        $currentStatement = '';
                    }
                }
            }

            // Add any remaining statement
            if (!empty(trim($currentStatement))) {
                $statements[] = $currentStatement;
            }

            echo '<script>updateProgress(20, "<span class=\"status-icon\">🔍</span> <span class=\"info\">Found ' . count($statements) . ' SQL statements to execute</span>");</script>';
            ob_flush();
            flush();

            // Disable foreign key checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $totalStatements = count($statements);
            $executedStatements = 0;
            $errors = [];
            $warnings = [];

            foreach ($statements as $index => $statement) {
                $statement = trim($statement);
                if (empty($statement)) continue;

                try {
                    // Identify what we're creating
                    $objectType = '';
                    $objectName = '';

                    if (stripos($statement, 'CREATE TABLE') !== false) {
                        preg_match('/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?(\w+)`?/i', $statement, $matches);
                        $objectType = 'TABLE';
                        $objectName = $matches[1] ?? 'unknown';
                    } elseif (stripos($statement, 'CREATE TRIGGER') !== false) {
                        preg_match('/CREATE TRIGGER\s+`?(\w+)`?/i', $statement, $matches);
                        $objectType = 'TRIGGER';
                        $objectName = $matches[1] ?? 'unknown';
                    } elseif (stripos($statement, 'CREATE PROCEDURE') !== false) {
                        preg_match('/CREATE PROCEDURE\s+`?(\w+)`?/i', $statement, $matches);
                        $objectType = 'PROCEDURE';
                        $objectName = $matches[1] ?? 'unknown';
                    } elseif (stripos($statement, 'CREATE INDEX') !== false) {
                        preg_match('/CREATE INDEX\s+`?(\w+)`?/i', $statement, $matches);
                        $objectType = 'INDEX';
                        $objectName = $matches[1] ?? 'unknown';
                    } elseif (stripos($statement, 'INSERT') !== false) {
                        preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', $statement, $matches);
                        $objectType = 'DATA';
                        $objectName = $matches[1] ?? 'unknown';
                    } elseif (stripos($statement, 'CREATE OR REPLACE VIEW') !== false) {
                        preg_match('/CREATE OR REPLACE VIEW\s+`?(\w+)`?/i', $statement, $matches);
                        $objectType = 'VIEW';
                        $objectName = $matches[1] ?? 'unknown';
                    } else {
                        $objectType = 'STATEMENT';
                        $objectName = substr($statement, 0, 30) . '...';
                    }

                    $pdo->exec($statement);
                    $executedStatements++;

                    $progress = 20 + round(($executedStatements / $totalStatements) * 70);

                    if ($objectType === 'TABLE') {
                        echo '<script>updateProgress(' . $progress . ', "<span class=\"status-icon\">✅</span> <span class=\"success\">Created table: ' . $objectName . '</span>");</script>';
                    } elseif ($objectType === 'TRIGGER') {
                        echo '<script>updateProgress(' . $progress . ', "<span class=\"status-icon\">⚡</span> <span class=\"success\">Created trigger: ' . $objectName . '</span>");</script>';
                    } elseif ($objectType === 'PROCEDURE') {
                        echo '<script>updateProgress(' . $progress . ', "<span class=\"status-icon\">🔧</span> <span class=\"success\">Created procedure: ' . $objectName . '</span>");</script>';
                    } elseif ($objectType === 'DATA') {
                        echo '<script>updateProgress(' . $progress . ', "<span class=\"status-icon\">📝</span> <span class=\"success\">Inserted data into: ' . $objectName . '</span>");</script>';
                    } elseif ($objectType === 'VIEW') {
                        echo '<script>updateProgress(' . $progress . ', "<span class=\"status-icon\">👁️</span> <span class=\"success\">Created view: ' . $objectName . '</span>");</script>';
                    }

                } catch (PDOException $e) {
                    $errorMsg = $e->getMessage();

                    // Check if it's a duplicate error (can be ignored)
                    if (strpos($errorMsg, 'Duplicate') !== false || $e->getCode() == '23000') {
                        $warnings[] = "$objectType $objectName: Already exists";
                        echo '<script>updateProgress(' . (20 + round(($executedStatements / $totalStatements) * 70)) . ', "<span class=\"status-icon\">⚠️</span> <span class=\"warning\">' . $objectType . ' ' . $objectName . ' already exists (skipped)</span>");</script>';
                    } else {
                        $errors[] = "$objectType $objectName: " . $errorMsg;
                        echo '<script>updateProgress(' . (20 + round(($executedStatements / $totalStatements) * 70)) . ', "<span class=\"status-icon\">❌</span> <span class=\"error\">Error creating ' . $objectType . ' ' . $objectName . ': ' . htmlspecialchars(substr($errorMsg, 0, 100)) . '</span>");</script>';
                    }
                }

                ob_flush();
                flush();
            }

            // Re-enable foreign key checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            echo '<script>updateProgress(95, "<span class=\"status-icon\">🔄</span> <span class=\"info\">Verifying installation...</span>");</script>';
            ob_flush();
            flush();

            // Verify installation
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM information_schema.tables WHERE table_schema = 'collabora' AND table_name LIKE 'chat_%' OR table_name LIKE 'channel_%' OR table_name LIKE 'message_%'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $tableCount = $result['count'];

            echo '<script>updateProgress(100, "<span class=\"status-icon\">📊</span> <span class=\"info\">Found ' . $tableCount . ' chat-related tables</span>");</script>';

            // Final status
            if (empty($errors) || count($errors) < 5) {
                echo '<script>updateProgress(100, "<span class=\"status-icon\">🎉</span> <span class=\"success\"><strong>Phase 4 installation completed successfully!</strong></span>");</script>';
            } else {
                echo '<script>updateProgress(100, "<span class=\"status-icon\">⚠️</span> <span class=\"warning\"><strong>Phase 4 installation completed with some warnings.</strong></span>");</script>';
            }

            // Summary
            echo '<script>updateProgress(100, "<br><strong>Summary:</strong>");</script>';
            echo '<script>updateProgress(100, "<span class=\"status-icon\">✅</span> Executed: ' . $executedStatements . ' statements");</script>';
            if (!empty($warnings)) {
                echo '<script>updateProgress(100, "<span class=\"status-icon\">⚠️</span> Warnings: ' . count($warnings) . '");</script>';
            }
            if (!empty($errors)) {
                echo '<script>updateProgress(100, "<span class=\"status-icon\">❌</span> Errors: ' . count($errors) . '");</script>';
            }

            echo '<div class="actions">';
            echo '<a href="verify_database.php" class="btn btn-success">🔍 Verify All Tables</a>';
            echo '<a href="chat.php" class="btn btn-primary">💬 Open Chat System</a>';
            echo '<a href="dashboard.php" class="btn">🏠 Go to Dashboard</a>';
            echo '</div>';

        } catch (Exception $e) {
            echo '<script>updateProgress(0, "<span class=\"status-icon\">❌</span> <span class=\"error\">Fatal error: ' . htmlspecialchars($e->getMessage()) . '</span>");</script>';
            echo '<div class="actions">';
            echo '<button onclick="location.reload()" class="btn">🔄 Retry Installation</button>';
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>