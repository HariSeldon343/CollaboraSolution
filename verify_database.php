<?php
/**
 * Database Verification Script for CollaboraNexio
 * Checks if all tables were created successfully
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
    <title>Database Verification - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .module {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .module h2 {
            color: #333;
            margin-top: 0;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        .table-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .table-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .table-item.exists {
            border-left-color: #28a745;
            background: #e8f5e9;
        }
        .table-item.missing {
            border-left-color: #dc3545;
            background: #ffebee;
        }
        .table-name {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        .table-info {
            font-size: 14px;
            color: #666;
        }
        .summary {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            text-align: center;
        }
        .summary h2 {
            color: #333;
            margin-top: 0;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .stat-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        .success {
            color: #28a745;
        }
        .error {
            color: #dc3545;
        }
        .warning {
            color: #ffc107;
        }
        .action-buttons {
            text-align: center;
            margin-top: 30px;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            margin: 0 10px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn-success {
            background: #28a745;
        }
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 CollaboraNexio Database Verification</h1>

        <?php
        try {
            $db = Database::getInstance();
            $pdo = $db->getConnection();

            // Define expected tables by module
            $modules = [
                'Core System' => [
                    'tables' => ['tenants', 'users', 'teams', 'team_members', 'projects', 'project_members'],
                    'icon' => '🏢'
                ],
                'Document Management' => [
                    'tables' => ['folders', 'files', 'file_permissions', 'file_shares', 'file_activity_logs'],
                    'icon' => '📁'
                ],
                'Calendar System' => [
                    'tables' => ['calendars', 'calendar_shares', 'events', 'event_participants', 'event_reminders', 'event_attachments', 'recurring_patterns'],
                    'icon' => '📅'
                ],
                'Task Management' => [
                    'tables' => ['task_lists', 'task_list_columns', 'tasks', 'task_dependencies', 'task_comments', 'task_attachments', 'task_watchers', 'time_entries'],
                    'icon' => '✅'
                ],
                'Custom Fields' => [
                    'tables' => ['custom_fields', 'custom_field_values'],
                    'icon' => '⚙️'
                ]
            ];

            // Get all tables in database
            $stmt = $pdo->query("SHOW TABLES");
            $existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $total_expected = 0;
            $total_found = 0;
            $total_missing = 0;

            // Summary statistics
            echo '<div class="summary">';
            echo '<h2>Database Status Overview</h2>';

            // Quick stats
            foreach ($modules as $module_name => $module_data) {
                foreach ($module_data['tables'] as $table) {
                    $total_expected++;
                    if (in_array($table, $existing_tables)) {
                        $total_found++;
                    } else {
                        $total_missing++;
                    }
                }
            }

            $percentage = $total_expected > 0 ? round(($total_found / $total_expected) * 100) : 0;
            $status_class = $percentage == 100 ? 'success' : ($percentage >= 50 ? 'warning' : 'error');

            echo '<div class="stats">';
            echo '<div class="stat-item">';
            echo '<div class="stat-value">' . $total_expected . '</div>';
            echo '<div class="stat-label">Expected Tables</div>';
            echo '</div>';
            echo '<div class="stat-item">';
            echo '<div class="stat-value success">' . $total_found . '</div>';
            echo '<div class="stat-label">Found</div>';
            echo '</div>';
            echo '<div class="stat-item">';
            echo '<div class="stat-value error">' . $total_missing . '</div>';
            echo '<div class="stat-label">Missing</div>';
            echo '</div>';
            echo '<div class="stat-item">';
            echo '<div class="stat-value ' . $status_class . '">' . $percentage . '%</div>';
            echo '<div class="stat-label">Complete</div>';
            echo '</div>';
            echo '</div>';
            echo '</div>';

            // Detailed module breakdown
            foreach ($modules as $module_name => $module_data) {
                echo '<div class="module">';
                echo '<h2>' . $module_data['icon'] . ' ' . $module_name . '</h2>';
                echo '<div class="table-grid">';

                foreach ($module_data['tables'] as $table) {
                    $exists = in_array($table, $existing_tables);
                    $class = $exists ? 'exists' : 'missing';

                    echo '<div class="table-item ' . $class . '">';
                    echo '<div class="table-name">' . ($exists ? '✓ ' : '✗ ') . $table . '</div>';

                    if ($exists) {
                        // Get row count
                        try {
                            $count_stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
                            $count = $count_stmt->fetchColumn();
                            echo '<div class="table-info">' . $count . ' rows</div>';
                        } catch (Exception $e) {
                            echo '<div class="table-info">Unable to count</div>';
                        }
                    } else {
                        echo '<div class="table-info">Not found</div>';
                    }

                    echo '</div>';
                }

                echo '</div>';
                echo '</div>';
            }

            // Additional tables found (not in our list)
            $expected_tables = [];
            foreach ($modules as $module_data) {
                $expected_tables = array_merge($expected_tables, $module_data['tables']);
            }

            $extra_tables = array_diff($existing_tables, $expected_tables);
            if (!empty($extra_tables)) {
                echo '<div class="module">';
                echo '<h2>📊 Additional Tables Found</h2>';
                echo '<div class="table-grid">';
                foreach ($extra_tables as $table) {
                    echo '<div class="table-item exists">';
                    echo '<div class="table-name">+ ' . $table . '</div>';
                    try {
                        $count_stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
                        $count = $count_stmt->fetchColumn();
                        echo '<div class="table-info">' . $count . ' rows</div>';
                    } catch (Exception $e) {
                        echo '<div class="table-info">Unable to count</div>';
                    }
                    echo '</div>';
                }
                echo '</div>';
                echo '</div>';
            }

            // Action buttons
            echo '<div class="action-buttons">';
            if ($total_missing > 0) {
                echo '<a href="install_database.php" class="btn btn-warning">🔧 Re-run Installation</a>';
            } else {
                echo '<a href="dashboard.php" class="btn btn-success">🚀 Go to Dashboard</a>';
            }
            echo '<a href="index.php" class="btn">🏠 Go to Login</a>';
            echo '</div>';

        } catch (Exception $e) {
            echo '<div class="module">';
            echo '<h2 class="error">❌ Error</h2>';
            echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>