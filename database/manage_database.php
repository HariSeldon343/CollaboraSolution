<?php
/**
 * Database Management Script for CollaboraNexio
 * Version: 2025-09-25
 * Author: Database Architect
 * Description: Comprehensive database management with proper error handling
 */

// Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'collaboranexio');

// Colors for CLI output
define('COLOR_RED', "\033[31m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_RESET', "\033[0m");

class DatabaseManager {
    private $conn;
    private $isWeb;

    public function __construct() {
        $this->isWeb = php_sapi_name() !== 'cli';
        if ($this->isWeb) {
            header('Content-Type: text/html; charset=UTF-8');
            echo "<!DOCTYPE html><html><head><title>Database Manager</title>";
            echo "<style>
                body { font-family: monospace; padding: 20px; background: #1a1a1a; color: #fff; }
                .success { color: #4caf50; }
                .error { color: #f44336; }
                .warning { color: #ff9800; }
                .info { color: #2196f3; }
                pre { background: #2a2a2a; padding: 10px; border-radius: 5px; overflow-x: auto; }
                .section { margin: 20px 0; padding: 15px; background: #2a2a2a; border-radius: 5px; }
                h2 { color: #2196f3; border-bottom: 2px solid #2196f3; padding-bottom: 10px; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                th, td { padding: 8px; text-align: left; border: 1px solid #444; }
                th { background: #333; color: #2196f3; }
                button { background: #2196f3; color: white; border: none; padding: 10px 20px;
                         margin: 5px; cursor: pointer; border-radius: 5px; }
                button:hover { background: #1976d2; }
                button.danger { background: #f44336; }
                button.danger:hover { background: #d32f2f; }
            </style></head><body>";
            echo "<h1>CollaboraNexio Database Manager</h1>";
        }
    }

    private function output($message, $type = 'info') {
        if ($this->isWeb) {
            $class = $type;
            echo "<div class='{$class}'>{$message}</div>";
        } else {
            $color = COLOR_RESET;
            switch($type) {
                case 'success': $color = COLOR_GREEN; break;
                case 'error': $color = COLOR_RED; break;
                case 'warning': $color = COLOR_YELLOW; break;
                case 'info': $color = COLOR_BLUE; break;
            }
            echo "{$color}{$message}" . COLOR_RESET . "\n";
        }
    }

    private function section($title) {
        if ($this->isWeb) {
            echo "<div class='section'><h2>{$title}</h2>";
        } else {
            echo "\n" . COLOR_BLUE . "========================================\n";
            echo "{$title}\n";
            echo "========================================" . COLOR_RESET . "\n";
        }
    }

    private function endSection() {
        if ($this->isWeb) {
            echo "</div>";
        }
    }

    public function connect() {
        try {
            $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
            if ($this->conn->connect_error) {
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }
            $this->conn->set_charset("utf8mb4");
            $this->output("✓ Connected to MySQL server", 'success');
            return true;
        } catch (Exception $e) {
            $this->output("✗ " . $e->getMessage(), 'error');
            return false;
        }
    }

    public function checkDatabase() {
        $this->section("1. Database Check");

        $result = $this->conn->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . DB_NAME . "'");
        if ($result->num_rows > 0) {
            $this->output("✓ Database '" . DB_NAME . "' exists", 'success');

            // Check tables
            $this->conn->select_db(DB_NAME);
            $result = $this->conn->query("SHOW TABLES");
            $this->output("  Found " . $result->num_rows . " tables", 'info');

            if ($result->num_rows > 0) {
                $tables = [];
                while ($row = $result->fetch_row()) {
                    $tables[] = $row[0];
                }
                $this->output("  Tables: " . implode(', ', array_slice($tables, 0, 10)) .
                            ($result->num_rows > 10 ? '...' : ''), 'info');
            }
        } else {
            $this->output("✗ Database '" . DB_NAME . "' does not exist", 'warning');
        }

        $this->endSection();
    }

    public function runStructureCheck() {
        $this->section("2. Structure Analysis");

        if (!file_exists('01_check_structure.sql')) {
            $this->output("✗ Structure check script not found", 'error');
            $this->endSection();
            return false;
        }

        try {
            $this->conn->select_db(DB_NAME);

            // Check foreign keys
            $result = $this->conn->query("
                SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = '" . DB_NAME . "'
                AND REFERENCED_TABLE_NAME IS NOT NULL
                ORDER BY TABLE_NAME
            ");

            if ($result->num_rows > 0) {
                $this->output("Foreign Key Constraints:", 'info');
                while ($row = $result->fetch_assoc()) {
                    $this->output("  {$row['TABLE_NAME']} -> {$row['REFERENCED_TABLE_NAME']} ({$row['CONSTRAINT_NAME']})", 'info');
                }
            } else {
                $this->output("No foreign key constraints found", 'warning');
            }

        } catch (Exception $e) {
            $this->output("✗ Error checking structure: " . $e->getMessage(), 'error');
        }

        $this->endSection();
    }

    public function resetDatabase() {
        $this->section("3. Database Reset");

        if (!file_exists('02_safe_reset.sql')) {
            $this->output("✗ Reset script not found", 'error');
            $this->endSection();
            return false;
        }

        $this->output("⚠ WARNING: This will DELETE ALL DATA!", 'warning');

        if (!$this->isWeb) {
            echo "Type 'yes' to continue: ";
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            if (trim($line) != 'yes') {
                $this->output("Reset cancelled", 'info');
                $this->endSection();
                return false;
            }
        }

        try {
            $this->conn->select_db(DB_NAME);

            // Disable foreign key checks
            $this->conn->query("SET FOREIGN_KEY_CHECKS = 0");
            $this->output("✓ Foreign key checks disabled", 'success');

            // Get all tables
            $result = $this->conn->query("SHOW TABLES");
            $tables = [];
            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }

            // Drop all tables
            foreach ($tables as $table) {
                if ($this->conn->query("DROP TABLE IF EXISTS `$table`")) {
                    $this->output("  ✓ Dropped table: $table", 'success');
                } else {
                    $this->output("  ✗ Failed to drop: $table", 'error');
                }
            }

            // Re-enable foreign key checks
            $this->conn->query("SET FOREIGN_KEY_CHECKS = 1");
            $this->output("✓ Foreign key checks re-enabled", 'success');
            $this->output("✓ Database reset complete", 'success');

        } catch (Exception $e) {
            $this->output("✗ Reset failed: " . $e->getMessage(), 'error');
            $this->conn->query("SET FOREIGN_KEY_CHECKS = 1");
        }

        $this->endSection();
    }

    public function initializeSchema() {
        $this->section("4. Schema Initialization");

        if (!file_exists('03_complete_schema.sql')) {
            $this->output("✗ Schema script not found", 'error');
            $this->endSection();
            return false;
        }

        try {
            // Create database if not exists
            $this->conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci");
            $this->conn->select_db(DB_NAME);
            $this->output("✓ Database selected", 'success');

            // Read and execute schema
            $sql = file_get_contents('03_complete_schema.sql');

            // Execute multi-query
            if ($this->conn->multi_query($sql)) {
                do {
                    if ($result = $this->conn->store_result()) {
                        $result->free();
                    }
                } while ($this->conn->more_results() && $this->conn->next_result());

                // Check for errors after all queries
                if ($this->conn->error) {
                    throw new Exception($this->conn->error);
                }

                $this->output("✓ Schema created successfully", 'success');

                // Verify tables
                $result = $this->conn->query("SHOW TABLES");
                $this->output("✓ Created " . $result->num_rows . " tables", 'success');

            } else {
                throw new Exception($this->conn->error);
            }

        } catch (Exception $e) {
            $this->output("✗ Schema initialization failed: " . $e->getMessage(), 'error');
        }

        $this->endSection();
    }

    public function loadDemoData() {
        $this->section("5. Demo Data Loading");

        try {
            $this->conn->select_db(DB_NAME);

            // Generate password hash
            $password = 'Admin123!';
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $this->output("✓ Generated password hash for demo users", 'success');

            // Load demo data with proper password hash
            $sql = file_get_contents('04_demo_data.sql');
            $sql = str_replace('$2y$10$YourHashHere', $hash, $sql);

            if ($this->conn->multi_query($sql)) {
                do {
                    if ($result = $this->conn->store_result()) {
                        $result->free();
                    }
                } while ($this->conn->more_results() && $this->conn->next_result());

                if ($this->conn->error) {
                    throw new Exception($this->conn->error);
                }

                $this->output("✓ Demo data loaded successfully", 'success');

                // Show user credentials
                $this->output("\nDemo User Credentials:", 'info');
                $this->output("═══════════════════════════════════════", 'info');
                $this->output("Admin: admin@demo.local / Admin123!", 'success');
                $this->output("Manager: manager@demo.local / Admin123!", 'success');
                $this->output("User 1: user1@demo.local / Admin123!", 'success');
                $this->output("User 2: user2@demo.local / Admin123!", 'success');
                $this->output("Test Admin: admin@test.local / Admin123!", 'success');

            } else {
                throw new Exception($this->conn->error);
            }

        } catch (Exception $e) {
            $this->output("✗ Demo data loading failed: " . $e->getMessage(), 'error');
        }

        $this->endSection();
    }

    public function showSummary() {
        $this->section("Database Summary");

        try {
            $this->conn->select_db(DB_NAME);

            $entities = [
                'tenants' => 'Tenants',
                'users' => 'Users',
                'projects' => 'Projects',
                'tasks' => 'Tasks',
                'folders' => 'Folders',
                'files' => 'Files',
                'chat_channels' => 'Chat Channels',
                'chat_messages' => 'Messages',
                'calendar_events' => 'Events',
                'notifications' => 'Notifications',
                'audit_logs' => 'Audit Logs'
            ];

            if ($this->isWeb) {
                echo "<table>";
                echo "<tr><th>Entity</th><th>Count</th></tr>";
            }

            foreach ($entities as $table => $name) {
                $result = $this->conn->query("SELECT COUNT(*) as cnt FROM $table");
                if ($result) {
                    $row = $result->fetch_assoc();
                    if ($this->isWeb) {
                        echo "<tr><td>{$name}</td><td>{$row['cnt']}</td></tr>";
                    } else {
                        $this->output(sprintf("  %-20s: %d", $name, $row['cnt']), 'info');
                    }
                }
            }

            if ($this->isWeb) {
                echo "</table>";
            }

        } catch (Exception $e) {
            $this->output("Unable to show summary: " . $e->getMessage(), 'warning');
        }

        $this->endSection();
    }

    public function showWebInterface() {
        echo "<div class='section'>";
        echo "<h2>Available Actions</h2>";
        echo "<form method='post'>";
        echo "<button type='submit' name='action' value='check'>Check Database</button>";
        echo "<button type='submit' name='action' value='structure'>Analyze Structure</button>";
        echo "<button type='submit' name='action' value='reset' class='danger' onclick=\"return confirm('This will DELETE ALL DATA! Are you sure?')\">Reset Database</button>";
        echo "<button type='submit' name='action' value='init'>Initialize Schema</button>";
        echo "<button type='submit' name='action' value='demo'>Load Demo Data</button>";
        echo "<button type='submit' name='action' value='full'>Full Setup (Reset + Init + Demo)</button>";
        echo "</form>";
        echo "</div>";

        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            switch($action) {
                case 'check':
                    $this->checkDatabase();
                    $this->showSummary();
                    break;
                case 'structure':
                    $this->runStructureCheck();
                    break;
                case 'reset':
                    $this->resetDatabase();
                    break;
                case 'init':
                    $this->initializeSchema();
                    break;
                case 'demo':
                    $this->loadDemoData();
                    break;
                case 'full':
                    $this->resetDatabase();
                    $this->initializeSchema();
                    $this->loadDemoData();
                    $this->showSummary();
                    break;
            }
        }
    }

    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }

        if ($this->isWeb) {
            echo "</body></html>";
        }
    }
}

// Main execution
$manager = new DatabaseManager();

if ($manager->connect()) {
    if (php_sapi_name() === 'cli') {
        // CLI mode
        if ($argc > 1) {
            switch($argv[1]) {
                case 'check':
                    $manager->checkDatabase();
                    $manager->showSummary();
                    break;
                case 'structure':
                    $manager->runStructureCheck();
                    break;
                case 'reset':
                    $manager->resetDatabase();
                    break;
                case 'init':
                    $manager->initializeSchema();
                    break;
                case 'demo':
                    $manager->loadDemoData();
                    break;
                case 'full':
                    $manager->resetDatabase();
                    $manager->initializeSchema();
                    $manager->loadDemoData();
                    $manager->showSummary();
                    break;
                default:
                    echo "Usage: php manage_database.php [check|structure|reset|init|demo|full]\n";
            }
        } else {
            echo "CollaboraNexio Database Manager\n";
            echo "================================\n";
            echo "Usage: php manage_database.php [command]\n\n";
            echo "Commands:\n";
            echo "  check     - Check database status\n";
            echo "  structure - Analyze database structure\n";
            echo "  reset     - Reset database (removes all data)\n";
            echo "  init      - Initialize database schema\n";
            echo "  demo      - Load demo data\n";
            echo "  full      - Full setup (reset + init + demo)\n";
        }
    } else {
        // Web mode
        $manager->showWebInterface();
    }
}

$manager->close();
?>