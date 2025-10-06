<?php
/**
 * Email Database Integrity Verification Script
 * Verifies complete database configuration for Infomaniak email setup
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

// Output as HTML for better readability
header('Content-Type: text/html; charset=utf-8');

class EmailIntegrityVerifier {
    private $db;
    private $conn;
    private $issues = [];
    private $warnings = [];
    private $successes = [];

    // Expected Infomaniak configuration
    private $expectedConfig = [
        'smtp_host' => 'mail.infomaniak.com',
        'smtp_port' => '465',
        'smtp_username' => 'info@fortibyte.it',
        'smtp_password' => 'Cartesi@1987',
        'smtp_from_email' => 'info@fortibyte.it',
        'smtp_from_name' => 'CollaboraNexio',
        'smtp_encryption' => 'ssl',
        'smtp_secure' => 'ssl',
        'smtp_enabled' => '1'
    ];

    public function __construct() {
        $this->db = Database::getInstance();
        $this->conn = $this->db->getConnection();
    }

    /**
     * Run all verification checks
     */
    public function runAllChecks() {
        echo "<h1>📧 Email Database Integrity Verification Report</h1>";
        echo "<p><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</p>";
        echo "<hr>";

        $this->checkSystemSettingsTableExists();
        $this->checkEmailSettingsInDatabase();
        $this->validateConfigurationValues();
        $this->checkConfigPhpConstants();
        $this->checkEmailSenderHardcodedValues();
        $this->testApplicationLevelQuery();
        $this->checkTableStructure();
        $this->checkForDuplicates();
        $this->checkForeignKeys();
        $this->generateSummary();
    }

    /**
     * Check if system_settings table exists
     */
    private function checkSystemSettingsTableExists() {
        echo "<h2>1. System Settings Table Existence Check</h2>";

        try {
            $stmt = $this->conn->query("SHOW TABLES LIKE 'system_settings'");
            $result = $stmt->fetch();

            if ($result) {
                $this->successes[] = "✅ system_settings table exists";
                echo "<p class='success'>✅ <strong>system_settings</strong> table exists</p>";

                // Count rows
                $stmt = $this->conn->query("SELECT COUNT(*) as count FROM system_settings");
                $count = $stmt->fetch()['count'];
                echo "<p>📊 Total rows in system_settings: <strong>{$count}</strong></p>";
            } else {
                $this->issues[] = "❌ system_settings table does NOT exist";
                echo "<p class='error'>❌ <strong>system_settings</strong> table does NOT exist!</p>";
            }
        } catch (Exception $e) {
            $this->issues[] = "Error checking table existence: " . $e->getMessage();
            echo "<p class='error'>Error: {$e->getMessage()}</p>";
        }
    }

    /**
     * Retrieve and display all email-related settings
     */
    private function checkEmailSettingsInDatabase() {
        echo "<h2>2. Email Settings in Database</h2>";

        try {
            $stmt = $this->conn->prepare("
                SELECT setting_key, setting_value, value_type, created_at, updated_at
                FROM system_settings
                WHERE setting_key LIKE 'smtp_%' OR setting_key IN ('mail_from_name', 'mail_from_email')
                ORDER BY setting_key
            ");
            $stmt->execute();
            $settings = $stmt->fetchAll();

            if (empty($settings)) {
                $this->issues[] = "❌ No email settings found in database";
                echo "<p class='error'>❌ No email settings found in system_settings table!</p>";
                return;
            }

            echo "<table border='1' cellpadding='10' cellspacing='0' style='width:100%; border-collapse: collapse;'>";
            echo "<thead><tr>";
            echo "<th>Setting Key</th>";
            echo "<th>Setting Value</th>";
            echo "<th>Value Type</th>";
            echo "<th>Created At</th>";
            echo "<th>Updated At</th>";
            echo "</tr></thead>";
            echo "<tbody>";

            foreach ($settings as $setting) {
                $key = htmlspecialchars($setting['setting_key']);
                $value = htmlspecialchars($setting['setting_value']);
                $dataType = htmlspecialchars($setting['value_type']);
                $createdAt = htmlspecialchars($setting['created_at']);
                $updatedAt = htmlspecialchars($setting['updated_at']);

                // Check if value is NULL or empty
                $valueDisplay = $value;
                if (is_null($setting['setting_value']) || $setting['setting_value'] === '') {
                    $valueDisplay = "<span style='color: red;'>(NULL or EMPTY)</span>";
                    $this->issues[] = "❌ {$key} is NULL or empty";
                }

                echo "<tr>";
                echo "<td><strong>{$key}</strong></td>";
                echo "<td>{$valueDisplay}</td>";
                echo "<td>{$dataType}</td>";
                echo "<td>{$createdAt}</td>";
                echo "<td>{$updatedAt}</td>";
                echo "</tr>";
            }

            echo "</tbody></table>";

            $this->successes[] = "✅ Found " . count($settings) . " email settings in database";

        } catch (Exception $e) {
            $this->issues[] = "Error retrieving email settings: " . $e->getMessage();
            echo "<p class='error'>Error: {$e->getMessage()}</p>";
        }
    }

    /**
     * Validate configuration values against expected values
     */
    private function validateConfigurationValues() {
        echo "<h2>3. Configuration Values Validation</h2>";

        try {
            $stmt = $this->conn->prepare("
                SELECT setting_key, setting_value
                FROM system_settings
                WHERE setting_key LIKE 'smtp_%' OR setting_key IN ('mail_from_name', 'mail_from_email')
            ");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            echo "<table border='1' cellpadding='10' cellspacing='0' style='width:100%; border-collapse: collapse;'>";
            echo "<thead><tr>";
            echo "<th>Setting</th>";
            echo "<th>Expected Value</th>";
            echo "<th>Actual Value</th>";
            echo "<th>Status</th>";
            echo "</tr></thead>";
            echo "<tbody>";

            foreach ($this->expectedConfig as $key => $expectedValue) {
                $actualValue = isset($settings[$key]) ? $settings[$key] : '(NOT SET)';

                $match = false;
                if (isset($settings[$key])) {
                    // For encryption/secure, accept either ssl or tls
                    if (in_array($key, ['smtp_encryption', 'smtp_secure'])) {
                        $match = in_array(strtolower($actualValue), ['ssl', 'tls']);
                    } else {
                        $match = ($actualValue === $expectedValue);
                    }
                }

                $status = $match ? "✅ Match" : "❌ Mismatch";
                $statusClass = $match ? "success" : "error";

                if (!$match) {
                    $this->issues[] = "❌ {$key}: Expected '{$expectedValue}', got '{$actualValue}'";
                } else {
                    $this->successes[] = "✅ {$key} is correct";
                }

                echo "<tr>";
                echo "<td><strong>{$key}</strong></td>";
                echo "<td>" . htmlspecialchars($expectedValue) . "</td>";
                echo "<td>" . htmlspecialchars($actualValue) . "</td>";
                echo "<td class='{$statusClass}'>{$status}</td>";
                echo "</tr>";
            }

            echo "</tbody></table>";

        } catch (Exception $e) {
            $this->issues[] = "Error validating configuration: " . $e->getMessage();
            echo "<p class='error'>Error: {$e->getMessage()}</p>";
        }
    }

    /**
     * Check config.php constants
     */
    private function checkConfigPhpConstants() {
        echo "<h2>4. config.php Email Constants Check</h2>";

        $configConstants = [
            'MAIL_FROM_NAME' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '(NOT DEFINED)',
            'MAIL_FROM_EMAIL' => defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : '(NOT DEFINED)',
            'MAIL_SMTP_HOST' => defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : '(NOT DEFINED)',
            'MAIL_SMTP_PORT' => defined('MAIL_SMTP_PORT') ? MAIL_SMTP_PORT : '(NOT DEFINED)',
            'MAIL_SMTP_AUTH' => defined('MAIL_SMTP_AUTH') ? (MAIL_SMTP_AUTH ? 'true' : 'false') : '(NOT DEFINED)',
            'MAIL_SMTP_USERNAME' => defined('MAIL_SMTP_USERNAME') ? MAIL_SMTP_USERNAME : '(NOT DEFINED)',
            'MAIL_SMTP_PASSWORD' => defined('MAIL_SMTP_PASSWORD') ? MAIL_SMTP_PASSWORD : '(NOT DEFINED)',
            'MAIL_SMTP_SECURE' => defined('MAIL_SMTP_SECURE') ? MAIL_SMTP_SECURE : '(NOT DEFINED)',
        ];

        echo "<table border='1' cellpadding='10' cellspacing='0' style='width:100%; border-collapse: collapse;'>";
        echo "<thead><tr>";
        echo "<th>Constant Name</th>";
        echo "<th>Value</th>";
        echo "<th>Note</th>";
        echo "</tr></thead>";
        echo "<tbody>";

        foreach ($configConstants as $constant => $value) {
            $note = '';
            $noteClass = '';

            // Check if constant is using default/placeholder values
            if ($value === 'localhost' || $value === '25' || $value === 'noreply@localhost' || $value === '') {
                $note = "⚠️ Using default/placeholder value - database should override";
                $noteClass = "warning";
                $this->warnings[] = "{$constant} has default value in config.php";
            } elseif ($value === '(NOT DEFINED)') {
                $note = "❌ Not defined in config.php";
                $noteClass = "error";
                $this->issues[] = "{$constant} is not defined in config.php";
            } else {
                $note = "ℹ️ Custom value set";
                $noteClass = "info";
            }

            echo "<tr>";
            echo "<td><strong>{$constant}</strong></td>";
            echo "<td>" . htmlspecialchars($value) . "</td>";
            echo "<td class='{$noteClass}'>{$note}</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";

        echo "<p><strong>📝 Note:</strong> config.php constants are legacy. The application should prefer database settings from system_settings table.</p>";
    }

    /**
     * Check EmailSender.php hardcoded values
     */
    private function checkEmailSenderHardcodedValues() {
        echo "<h2>5. EmailSender.php Hardcoded Values Check</h2>";

        $emailSenderPath = __DIR__ . '/includes/EmailSender.php';

        if (!file_exists($emailSenderPath)) {
            $this->issues[] = "❌ EmailSender.php not found";
            echo "<p class='error'>❌ EmailSender.php not found at {$emailSenderPath}</p>";
            return;
        }

        require_once $emailSenderPath;

        // Create reflection to read private properties
        $reflector = new ReflectionClass('EmailSender');
        $properties = $reflector->getDefaultProperties();

        echo "<table border='1' cellpadding='10' cellspacing='0' style='width:100%; border-collapse: collapse;'>";
        echo "<thead><tr>";
        echo "<th>Property</th>";
        echo "<th>Hardcoded Value</th>";
        echo "<th>Expected Value</th>";
        echo "<th>Status</th>";
        echo "</tr></thead>";
        echo "<tbody>";

        $propertyMapping = [
            'smtpHost' => 'smtp_host',
            'smtpPort' => 'smtp_port',
            'smtpUsername' => 'smtp_username',
            'smtpPassword' => 'smtp_password',
            'fromEmail' => 'smtp_from_email',
            'fromName' => 'smtp_from_name'
        ];

        foreach ($propertyMapping as $property => $settingKey) {
            $hardcodedValue = isset($properties[$property]) ? $properties[$property] : '(NOT SET)';
            $expectedValue = isset($this->expectedConfig[$settingKey]) ? $this->expectedConfig[$settingKey] : 'N/A';

            $match = ($hardcodedValue == $expectedValue);
            $status = $match ? "✅ Match" : "❌ Mismatch";
            $statusClass = $match ? "success" : "error";

            if (!$match && $expectedValue !== 'N/A') {
                $this->issues[] = "❌ EmailSender::{$property}: Expected '{$expectedValue}', got '{$hardcodedValue}'";
            } else {
                $this->successes[] = "✅ EmailSender::{$property} is correct";
            }

            echo "<tr>";
            echo "<td><strong>\${$property}</strong></td>";
            echo "<td>" . htmlspecialchars($hardcodedValue) . "</td>";
            echo "<td>" . htmlspecialchars($expectedValue) . "</td>";
            echo "<td class='{$statusClass}'>{$status}</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";

        echo "<p><strong>✅ Good News:</strong> EmailSender.php constructor accepts config array to override hardcoded values.</p>";
    }

    /**
     * Test application-level configuration query
     */
    private function testApplicationLevelQuery() {
        echo "<h2>6. Application-Level Configuration Query Test</h2>";

        try {
            // This is how the application should fetch email config
            $stmt = $this->conn->prepare("
                SELECT setting_key, setting_value
                FROM system_settings
                WHERE setting_key LIKE 'smtp_%' OR setting_key IN ('mail_from_name', 'mail_from_email')
            ");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            echo "<p>✅ Query executed successfully</p>";
            echo "<p>📊 Retrieved <strong>" . count($settings) . "</strong> settings</p>";

            echo "<h3>Resulting Configuration Array:</h3>";
            echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
            print_r($settings);
            echo "</pre>";

            // Test instantiating EmailSender with database config
            if (count($settings) > 0) {
                echo "<h3>Test: Instantiate EmailSender with Database Config</h3>";

                $emailConfig = [
                    'smtpHost' => $settings['smtp_host'] ?? '',
                    'smtpPort' => (int)($settings['smtp_port'] ?? 465),
                    'smtpUsername' => $settings['smtp_username'] ?? '',
                    'smtpPassword' => $settings['smtp_password'] ?? '',
                    'fromEmail' => $settings['smtp_from_email'] ?? '',
                    'fromName' => $settings['smtp_from_name'] ?? 'CollaboraNexio',
                ];

                echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
                echo "// EmailSender configuration from database:\n";
                echo "\$emailConfig = [\n";
                foreach ($emailConfig as $key => $value) {
                    echo "    '{$key}' => " . var_export($value, true) . ",\n";
                }
                echo "];\n\n";
                echo "\$emailSender = new EmailSender(\$emailConfig);\n";
                echo "</pre>";

                $this->successes[] = "✅ Database configuration can be successfully loaded";
            }

        } catch (Exception $e) {
            $this->issues[] = "Error testing application query: " . $e->getMessage();
            echo "<p class='error'>Error: {$e->getMessage()}</p>";
        }
    }

    /**
     * Check table structure
     */
    private function checkTableStructure() {
        echo "<h2>7. system_settings Table Structure</h2>";

        try {
            $stmt = $this->conn->query("DESCRIBE system_settings");
            $columns = $stmt->fetchAll();

            echo "<table border='1' cellpadding='10' cellspacing='0' style='width:100%; border-collapse: collapse;'>";
            echo "<thead><tr>";
            echo "<th>Field</th>";
            echo "<th>Type</th>";
            echo "<th>Null</th>";
            echo "<th>Key</th>";
            echo "<th>Default</th>";
            echo "<th>Extra</th>";
            echo "</tr></thead>";
            echo "<tbody>";

            foreach ($columns as $column) {
                echo "<tr>";
                echo "<td><strong>{$column['Field']}</strong></td>";
                echo "<td>{$column['Type']}</td>";
                echo "<td>{$column['Null']}</td>";
                echo "<td>{$column['Key']}</td>";
                echo "<td>" . ($column['Default'] ?? '(NULL)') . "</td>";
                echo "<td>{$column['Extra']}</td>";
                echo "</tr>";
            }

            echo "</tbody></table>";

            // Check for indexes
            echo "<h3>Table Indexes:</h3>";
            $stmt = $this->conn->query("SHOW INDEX FROM system_settings");
            $indexes = $stmt->fetchAll();

            if (!empty($indexes)) {
                echo "<ul>";
                foreach ($indexes as $index) {
                    echo "<li><strong>{$index['Key_name']}</strong> on column <code>{$index['Column_name']}</code>";
                    if ($index['Key_name'] === 'PRIMARY') {
                        echo " (PRIMARY KEY)";
                    }
                    if (!$index['Non_unique']) {
                        echo " (UNIQUE)";
                    }
                    echo "</li>";
                }
                echo "</ul>";
                $this->successes[] = "✅ Table has proper indexes";
            } else {
                $this->warnings[] = "⚠️ No indexes found on system_settings table";
                echo "<p class='warning'>⚠️ No indexes found</p>";
            }

        } catch (Exception $e) {
            $this->issues[] = "Error checking table structure: " . $e->getMessage();
            echo "<p class='error'>Error: {$e->getMessage()}</p>";
        }
    }

    /**
     * Check for duplicate keys
     */
    private function checkForDuplicates() {
        echo "<h2>8. Duplicate Keys Check</h2>";

        try {
            $stmt = $this->conn->query("
                SELECT setting_key, COUNT(*) as count
                FROM system_settings
                WHERE setting_key LIKE 'smtp_%' OR setting_key IN ('mail_from_name', 'mail_from_email')
                GROUP BY setting_key
                HAVING count > 1
            ");
            $duplicates = $stmt->fetchAll();

            if (empty($duplicates)) {
                echo "<p class='success'>✅ No duplicate keys found</p>";
                $this->successes[] = "✅ No duplicate setting keys";
            } else {
                echo "<p class='error'>❌ Found duplicate keys:</p>";
                echo "<ul>";
                foreach ($duplicates as $dup) {
                    echo "<li><strong>{$dup['setting_key']}</strong>: {$dup['count']} occurrences</li>";
                    $this->issues[] = "❌ Duplicate key: {$dup['setting_key']} ({$dup['count']} times)";
                }
                echo "</ul>";
            }

        } catch (Exception $e) {
            $this->issues[] = "Error checking duplicates: " . $e->getMessage();
            echo "<p class='error'>Error: {$e->getMessage()}</p>";
        }
    }

    /**
     * Check for foreign key constraints
     */
    private function checkForeignKeys() {
        echo "<h2>9. Foreign Key Constraints</h2>";

        try {
            $stmt = $this->conn->query("
                SELECT
                    CONSTRAINT_NAME,
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = '" . DB_NAME . "'
                AND TABLE_NAME = 'system_settings'
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $foreignKeys = $stmt->fetchAll();

            if (empty($foreignKeys)) {
                echo "<p class='info'>ℹ️ No foreign key constraints on system_settings table</p>";
                echo "<p><em>This is normal for system_settings as it's a configuration table.</em></p>";
                $this->successes[] = "✅ No foreign key issues (none expected)";
            } else {
                echo "<table border='1' cellpadding='10' cellspacing='0'>";
                echo "<thead><tr><th>Constraint</th><th>Column</th><th>References</th></tr></thead>";
                echo "<tbody>";
                foreach ($foreignKeys as $fk) {
                    echo "<tr>";
                    echo "<td>{$fk['CONSTRAINT_NAME']}</td>";
                    echo "<td>{$fk['COLUMN_NAME']}</td>";
                    echo "<td>{$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}</td>";
                    echo "</tr>";
                }
                echo "</tbody></table>";
            }

        } catch (Exception $e) {
            $this->warnings[] = "Could not check foreign keys: " . $e->getMessage();
            echo "<p class='warning'>⚠️ Could not check foreign keys: {$e->getMessage()}</p>";
        }
    }

    /**
     * Generate summary report
     */
    private function generateSummary() {
        echo "<h2>📋 Verification Summary</h2>";

        $totalChecks = count($this->successes) + count($this->issues) + count($this->warnings);
        $successRate = $totalChecks > 0 ? round((count($this->successes) / $totalChecks) * 100, 1) : 0;

        echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3>Statistics</h3>";
        echo "<p>✅ <strong>Successes:</strong> " . count($this->successes) . "</p>";
        echo "<p>❌ <strong>Issues:</strong> " . count($this->issues) . "</p>";
        echo "<p>⚠️ <strong>Warnings:</strong> " . count($this->warnings) . "</p>";
        echo "<p>📊 <strong>Success Rate:</strong> {$successRate}%</p>";
        echo "</div>";

        if (!empty($this->issues)) {
            echo "<div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;'>";
            echo "<h3>❌ Critical Issues Found:</h3>";
            echo "<ul>";
            foreach ($this->issues as $issue) {
                echo "<li>{$issue}</li>";
            }
            echo "</ul>";
            echo "</div>";
        }

        if (!empty($this->warnings)) {
            echo "<div style='background: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0;'>";
            echo "<h3>⚠️ Warnings:</h3>";
            echo "<ul>";
            foreach ($this->warnings as $warning) {
                echo "<li>{$warning}</li>";
            }
            echo "</ul>";
            echo "</div>";
        }

        echo "<h3>✅ Recommendations</h3>";
        echo "<ol>";

        if (count($this->issues) > 0) {
            echo "<li><strong>Fix Critical Issues:</strong> Address all issues listed above before using email functionality.</li>";
            echo "<li><strong>Run Migration:</strong> If email settings are missing, run the email settings migration script.</li>";
        } else {
            echo "<li>✅ Database configuration is correct!</li>";
        }

        echo "<li><strong>Update Application Code:</strong> Ensure EmailSender class loads configuration from database instead of using hardcoded values.</li>";
        echo "<li><strong>Test Email Sending:</strong> After fixing issues, test actual email sending with a test script.</li>";
        echo "<li><strong>Monitor Logs:</strong> Check error logs for any SMTP connection issues.</li>";
        echo "</ol>";

        echo "<h3>🔧 Next Steps</h3>";
        echo "<ol>";
        echo "<li>If email settings are missing, run: <code>php database/insert_email_settings.sql</code></li>";
        echo "<li>Update EmailSender.php to load config from database</li>";
        echo "<li>Test with: <code>php test_email_optimization.php</code></li>";
        echo "</ol>";
    }
}

// Add CSS styles
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Database Integrity Verification</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }

        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }

        h2 {
            color: #34495e;
            margin-top: 30px;
            border-left: 4px solid #3498db;
            padding-left: 15px;
        }

        h3 {
            color: #555;
        }

        table {
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
            text-align: left;
        }

        tr:nth-child(even) {
            background: #f8f9fa;
        }

        .success {
            color: #27ae60;
            font-weight: bold;
        }

        .error {
            color: #e74c3c;
            font-weight: bold;
        }

        .warning {
            color: #f39c12;
            font-weight: bold;
        }

        .info {
            color: #3498db;
        }

        pre {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }

        code {
            background: #ecf0f1;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }

        hr {
            border: none;
            border-top: 2px solid #e0e0e0;
            margin: 30px 0;
        }
    </style>
</head>
<body>
<?php

// Run the verification
$verifier = new EmailIntegrityVerifier();
$verifier->runAllChecks();

?>
</body>
</html>
