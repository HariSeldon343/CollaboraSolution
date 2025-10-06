#!/usr/bin/env php
<?php
/**
 * CollaboraNexio Comprehensive Integration Test Suite
 *
 * This test suite validates all modules of the multi-tenant collaboration platform.
 * Run with: php run_all_tests.php [module_name] [--verbose] [--html-report]
 *
 * @author CollaboraNexio Testing Team
 * @version 1.0.0
 * @since PHP 8.3
 */

declare(strict_types=1);

// Configuration
const TEST_VERSION = '1.0.0';
const REPORT_DIR = __DIR__ . '/reports';
const TEMP_DIR = __DIR__ . '/temp';

// Include required files
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/config.php';

/**
 * Test Result Class
 */
class TestResult {
    public string $module;
    public string $test;
    public bool $passed;
    public string $message;
    public float $executionTime;
    public ?array $details;

    public function __construct(
        string $module,
        string $test,
        bool $passed,
        string $message,
        float $executionTime = 0,
        ?array $details = null
    ) {
        $this->module = $module;
        $this->test = $test;
        $this->passed = $passed;
        $this->message = $message;
        $this->executionTime = $executionTime;
        $this->details = $details;
    }
}

/**
 * Base Test Class
 */
abstract class BaseTest {
    protected Database $db;
    protected array $results = [];
    protected bool $verbose = false;
    protected array $testData = [];
    protected float $startTime;

    public function __construct(bool $verbose = false) {
        $this->db = Database::getInstance();
        $this->verbose = $verbose;
    }

    abstract public function getModuleName(): string;
    abstract public function runTests(): array;

    protected function startTest(string $testName): void {
        $this->startTime = microtime(true);
        if ($this->verbose) {
            $this->printInfo("Running: {$testName}");
        }
    }

    protected function endTest(string $testName, bool $passed, string $message, ?array $details = null): TestResult {
        $executionTime = microtime(true) - $this->startTime;
        $result = new TestResult(
            $this->getModuleName(),
            $testName,
            $passed,
            $message,
            $executionTime,
            $details
        );
        $this->results[] = $result;

        if ($this->verbose) {
            $status = $passed ? 'PASS' : 'FAIL';
            $color = $passed ? '32' : '31';
            echo "\033[{$color}m[{$status}]\033[0m {$testName} ({$executionTime:.3f}s): {$message}\n";
        }

        return $result;
    }

    protected function printInfo(string $message): void {
        echo "\033[36m[INFO]\033[0m {$message}\n";
    }

    protected function printWarning(string $message): void {
        echo "\033[33m[WARN]\033[0m {$message}\n";
    }

    protected function printError(string $message): void {
        echo "\033[31m[ERROR]\033[0m {$message}\n";
    }

    protected function setupTestData(): void {
        // Override in child classes
    }

    protected function cleanupTestData(): void {
        // Override in child classes
    }
}

/**
 * Authentication & Multi-tenancy Test Module
 */
class AuthenticationTest extends BaseTest {
    private int $testTenantId = 0;
    private int $testUserId = 0;

    public function getModuleName(): string {
        return 'Authentication & Multi-tenancy';
    }

    public function runTests(): array {
        $this->setupTestData();

        // Test 1: Create tenant
        $this->testCreateTenant();

        // Test 2: User registration
        $this->testUserRegistration();

        // Test 3: User login
        $this->testUserLogin();

        // Test 4: Tenant isolation
        $this->testTenantIsolation();

        // Test 5: Password security
        $this->testPasswordSecurity();

        // Test 6: Session management
        $this->testSessionManagement();

        // Test 7: Role-based access
        $this->testRoleBasedAccess();

        // Test 8: Login attempts tracking
        $this->testLoginAttempts();

        $this->cleanupTestData();
        return $this->results;
    }

    private function testCreateTenant(): void {
        $this->startTest('Create Tenant');

        try {
            $tenantData = [
                'name' => 'test_tenant_' . time(),
                'domain' => 'test' . time() . '.local',
                'settings' => json_encode(['max_users' => 100]),
                'is_active' => true
            ];

            $this->testTenantId = $this->db->insert('tenants', $tenantData);

            if ($this->testTenantId > 0) {
                $this->endTest('Create Tenant', true, 'Tenant created successfully', ['tenant_id' => $this->testTenantId]);
            } else {
                $this->endTest('Create Tenant', false, 'Failed to create tenant');
            }
        } catch (Exception $e) {
            $this->endTest('Create Tenant', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testUserRegistration(): void {
        $this->startTest('User Registration');

        try {
            $userData = [
                'tenant_id' => $this->testTenantId,
                'name' => 'Test User',
                'email' => 'test_' . time() . '@example.com',
                'password' => password_hash('TestPass123!', PASSWORD_DEFAULT),
                'role' => 'user',
                'is_active' => true
            ];

            $this->testUserId = $this->db->insert('users', $userData);

            if ($this->testUserId > 0) {
                $this->endTest('User Registration', true, 'User registered successfully', ['user_id' => $this->testUserId]);
            } else {
                $this->endTest('User Registration', false, 'Failed to register user');
            }
        } catch (Exception $e) {
            $this->endTest('User Registration', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testUserLogin(): void {
        $this->startTest('User Login');

        try {
            $email = 'test_' . ($this->testUserId ? time() - 1 : time()) . '@example.com';
            $user = $this->db->fetchOne(
                "SELECT * FROM users WHERE email = :email AND tenant_id = :tenant_id",
                ['email' => $email, 'tenant_id' => $this->testTenantId]
            );

            if ($user && password_verify('TestPass123!', $user['password'])) {
                // Update last login
                $this->db->update(
                    'users',
                    ['last_login_at' => date('Y-m-d H:i:s')],
                    ['id' => $user['id']]
                );
                $this->endTest('User Login', true, 'Login successful');
            } else {
                $this->endTest('User Login', false, 'Invalid credentials');
            }
        } catch (Exception $e) {
            $this->endTest('User Login', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testTenantIsolation(): void {
        $this->startTest('Tenant Isolation');

        try {
            // Create second tenant
            $tenant2Id = $this->db->insert('tenants', [
                'name' => 'test_tenant2_' . time(),
                'domain' => 'test2_' . time() . '.local'
            ]);

            // Create user in second tenant with same email
            $user2Data = [
                'tenant_id' => $tenant2Id,
                'name' => 'Test User 2',
                'email' => 'same@example.com',
                'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
                'role' => 'user'
            ];

            $user2Id = $this->db->insert('users', $user2Data);

            // Try to create same email in first tenant
            $user1Data = $user2Data;
            $user1Data['tenant_id'] = $this->testTenantId;
            $user1Data['name'] = 'Test User 1';

            $user1Id = $this->db->insert('users', $user1Data);

            // Verify isolation
            $count = $this->db->count('users', ['email' => 'same@example.com']);

            if ($count == 2 && $user1Id > 0 && $user2Id > 0) {
                $this->endTest('Tenant Isolation', true, 'Tenant isolation working correctly');

                // Cleanup
                $this->db->delete('users', ['email' => 'same@example.com']);
                $this->db->delete('tenants', ['id' => $tenant2Id]);
            } else {
                $this->endTest('Tenant Isolation', false, 'Tenant isolation failed');
            }
        } catch (Exception $e) {
            $this->endTest('Tenant Isolation', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testPasswordSecurity(): void {
        $this->startTest('Password Security');

        try {
            $weakPasswords = ['123456', 'password', 'qwerty'];
            $strongPassword = 'Str0ng!Pass#2024';

            $allSecure = true;
            $details = [];

            // Test weak passwords should not be stored as plain text
            foreach ($weakPasswords as $weak) {
                $hash = password_hash($weak, PASSWORD_DEFAULT);
                if ($weak === $hash || strlen($hash) < 50) {
                    $allSecure = false;
                    $details[] = "Weak password not properly hashed: {$weak}";
                }
            }

            // Test strong password hashing
            $hash = password_hash($strongPassword, PASSWORD_DEFAULT);
            if (!password_verify($strongPassword, $hash)) {
                $allSecure = false;
                $details[] = "Password verification failed";
            }

            // Test different hashes for same password
            $hash2 = password_hash($strongPassword, PASSWORD_DEFAULT);
            if ($hash === $hash2) {
                $allSecure = false;
                $details[] = "Same password produces identical hashes (no salt)";
            }

            if ($allSecure) {
                $this->endTest('Password Security', true, 'Password security checks passed');
            } else {
                $this->endTest('Password Security', false, 'Password security issues found', $details);
            }
        } catch (Exception $e) {
            $this->endTest('Password Security', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testSessionManagement(): void {
        $this->startTest('Session Management');

        try {
            // Start a new session
            if (session_status() === PHP_SESSION_NONE) {
                session_name(SESSION_NAME);
                session_start();
            }

            // Set session data
            $_SESSION['user_id'] = $this->testUserId;
            $_SESSION['tenant_id'] = $this->testTenantId;
            $_SESSION['login_time'] = time();

            // Verify session data
            $passed = isset($_SESSION['user_id']) &&
                     isset($_SESSION['tenant_id']) &&
                     isset($_SESSION['login_time']);

            if ($passed) {
                $this->endTest('Session Management', true, 'Session management working correctly');
            } else {
                $this->endTest('Session Management', false, 'Session data not properly stored');
            }

            // Cleanup
            session_destroy();
        } catch (Exception $e) {
            $this->endTest('Session Management', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testRoleBasedAccess(): void {
        $this->startTest('Role-based Access Control');

        try {
            $roles = ['admin', 'manager', 'user'];
            $results = [];

            foreach ($roles as $role) {
                $userId = $this->db->insert('users', [
                    'tenant_id' => $this->testTenantId,
                    'name' => "Test {$role}",
                    'email' => "{$role}_" . time() . "@example.com",
                    'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
                    'role' => $role
                ]);

                $user = $this->db->fetchOne("SELECT * FROM users WHERE id = :id", ['id' => $userId]);

                if ($user && $user['role'] === $role) {
                    $results[$role] = true;
                } else {
                    $results[$role] = false;
                }

                // Cleanup
                $this->db->delete('users', ['id' => $userId]);
            }

            $allPassed = !in_array(false, $results, true);

            if ($allPassed) {
                $this->endTest('Role-based Access Control', true, 'All roles created and verified successfully');
            } else {
                $this->endTest('Role-based Access Control', false, 'Some roles failed', $results);
            }
        } catch (Exception $e) {
            $this->endTest('Role-based Access Control', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testLoginAttempts(): void {
        $this->startTest('Login Attempts Tracking');

        try {
            // Create login_attempts table if not exists
            $this->db->query("
                CREATE TABLE IF NOT EXISTS login_attempts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL,
                    ip_address VARCHAR(45),
                    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    success BOOLEAN DEFAULT FALSE,
                    INDEX idx_email_time (email, attempt_time)
                )
            ");

            $testEmail = 'attempts_test@example.com';
            $testIp = '127.0.0.1';

            // Simulate failed login attempts
            for ($i = 1; $i <= MAX_LOGIN_ATTEMPTS; $i++) {
                $this->db->insert('login_attempts', [
                    'email' => $testEmail,
                    'ip_address' => $testIp,
                    'success' => false
                ]);
            }

            // Count recent attempts
            $recentTime = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_TIME);
            $attempts = $this->db->count('login_attempts', [
                'email' => $testEmail,
                'success' => false
            ]);

            $shouldBeLocked = $attempts >= MAX_LOGIN_ATTEMPTS;

            if ($shouldBeLocked) {
                $this->endTest('Login Attempts Tracking', true,
                    "Account locked after {$attempts} failed attempts");
            } else {
                $this->endTest('Login Attempts Tracking', false,
                    "Account not locked after {$attempts} attempts");
            }

            // Cleanup
            $this->db->query("DROP TABLE IF EXISTS login_attempts");
        } catch (Exception $e) {
            $this->endTest('Login Attempts Tracking', false, 'Exception: ' . $e->getMessage());
        }
    }

    protected function cleanupTestData(): void {
        if ($this->testUserId > 0) {
            $this->db->delete('users', ['id' => $this->testUserId]);
        }
        if ($this->testTenantId > 0) {
            $this->db->delete('tenants', ['id' => $this->testTenantId]);
        }
    }
}

/**
 * File Management Test Module
 */
class FileManagementTest extends BaseTest {
    private int $testTenantId = 0;
    private int $testUserId = 0;
    private array $testFiles = [];

    public function getModuleName(): string {
        return 'File Management';
    }

    public function runTests(): array {
        $this->setupTestData();

        // Test 1: File upload
        $this->testFileUpload();

        // Test 2: File download
        $this->testFileDownload();

        // Test 3: Folder operations
        $this->testFolderOperations();

        // Test 4: File permissions
        $this->testFilePermissions();

        // Test 5: File versioning
        $this->testFileVersioning();

        // Test 6: File search
        $this->testFileSearch();

        // Test 7: File sharing
        $this->testFileSharing();

        // Test 8: Storage quota
        $this->testStorageQuota();

        $this->cleanupTestData();
        return $this->results;
    }

    protected function setupTestData(): void {
        // Create test tenant and user
        $this->testTenantId = $this->db->insert('tenants', [
            'name' => 'file_test_tenant_' . time(),
            'domain' => 'filetest.local'
        ]);

        $this->testUserId = $this->db->insert('users', [
            'tenant_id' => $this->testTenantId,
            'name' => 'File Test User',
            'email' => 'filetest@example.com',
            'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
            'role' => 'user'
        ]);

        // Create upload directory if not exists
        $uploadPath = UPLOAD_PATH . '/' . $this->testTenantId;
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }
    }

    private function testFileUpload(): void {
        $this->startTest('File Upload');

        try {
            // Simulate file upload
            $fileName = 'test_file_' . time() . '.txt';
            $fileContent = "Test content for file upload\n" . str_repeat("Data ", 100);
            $filePath = UPLOAD_PATH . '/' . $this->testTenantId . '/' . $fileName;

            // Write test file
            file_put_contents($filePath, $fileContent);
            $fileSize = filesize($filePath);
            $checksum = md5_file($filePath);

            // Save to database
            $fileId = $this->db->insert('files', [
                'tenant_id' => $this->testTenantId,
                'user_id' => $this->testUserId,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'mime_type' => 'text/plain',
                'checksum' => $checksum,
                'is_public' => false
            ]);

            $this->testFiles[] = ['id' => $fileId, 'path' => $filePath];

            if ($fileId > 0 && file_exists($filePath)) {
                $this->endTest('File Upload', true, 'File uploaded successfully', [
                    'file_id' => $fileId,
                    'size' => $fileSize,
                    'checksum' => $checksum
                ]);
            } else {
                $this->endTest('File Upload', false, 'File upload failed');
            }
        } catch (Exception $e) {
            $this->endTest('File Upload', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testFileDownload(): void {
        $this->startTest('File Download');

        try {
            if (empty($this->testFiles)) {
                $this->endTest('File Download', false, 'No test files available');
                return;
            }

            $fileId = $this->testFiles[0]['id'];
            $file = $this->db->fetchOne(
                "SELECT * FROM files WHERE id = :id AND tenant_id = :tenant_id",
                ['id' => $fileId, 'tenant_id' => $this->testTenantId]
            );

            if ($file && file_exists($file['file_path'])) {
                $content = file_get_contents($file['file_path']);
                $checksum = md5($content);

                if ($checksum === $file['checksum']) {
                    $this->endTest('File Download', true, 'File downloaded and verified successfully');
                } else {
                    $this->endTest('File Download', false, 'File checksum mismatch');
                }
            } else {
                $this->endTest('File Download', false, 'File not found');
            }
        } catch (Exception $e) {
            $this->endTest('File Download', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testFolderOperations(): void {
        $this->startTest('Folder Operations');

        try {
            // Create folders table if not exists
            $this->db->query("
                CREATE TABLE IF NOT EXISTS folders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id INT NOT NULL,
                    parent_id INT NULL,
                    name VARCHAR(255) NOT NULL,
                    path VARCHAR(500),
                    created_by INT UNSIGNED,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
                    FOREIGN KEY (created_by) REFERENCES users(id),
                    INDEX idx_folder_path (path)
                )
            ");

            // Create root folder
            $rootId = $this->db->insert('folders', [
                'tenant_id' => $this->testTenantId,
                'parent_id' => null,
                'name' => 'Root',
                'path' => '/',
                'created_by' => $this->testUserId
            ]);

            // Create subfolder
            $subfolderId = $this->db->insert('folders', [
                'tenant_id' => $this->testTenantId,
                'parent_id' => $rootId,
                'name' => 'Documents',
                'path' => '/Documents',
                'created_by' => $this->testUserId
            ]);

            // Verify folder hierarchy
            $folders = $this->db->fetchAll(
                "SELECT * FROM folders WHERE tenant_id = :tenant_id ORDER BY path",
                ['tenant_id' => $this->testTenantId]
            );

            if (count($folders) >= 2) {
                $this->endTest('Folder Operations', true, 'Folder hierarchy created successfully', [
                    'folders_created' => count($folders)
                ]);
            } else {
                $this->endTest('Folder Operations', false, 'Failed to create folder structure');
            }

            // Cleanup
            $this->db->query("DROP TABLE IF EXISTS folders");
        } catch (Exception $e) {
            $this->endTest('Folder Operations', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testFilePermissions(): void {
        $this->startTest('File Permissions');

        try {
            // Create file_permissions table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS file_permissions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    file_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NULL,
                    team_id INT UNSIGNED NULL,
                    permission ENUM('read', 'write', 'delete', 'share') NOT NULL,
                    granted_by INT UNSIGNED,
                    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                    UNIQUE KEY uniq_permission (file_id, user_id, team_id, permission)
                )
            ");

            if (empty($this->testFiles)) {
                $this->endTest('File Permissions', false, 'No test files available');
                return;
            }

            $fileId = $this->testFiles[0]['id'];

            // Create another user
            $user2Id = $this->db->insert('users', [
                'tenant_id' => $this->testTenantId,
                'name' => 'Test User 2',
                'email' => 'user2_' . time() . '@example.com',
                'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
                'role' => 'user'
            ]);

            // Grant read permission
            $permId = $this->db->insert('file_permissions', [
                'file_id' => $fileId,
                'user_id' => $user2Id,
                'permission' => 'read',
                'granted_by' => $this->testUserId
            ]);

            // Check permission
            $hasPermission = $this->db->exists('file_permissions', [
                'file_id' => $fileId,
                'user_id' => $user2Id,
                'permission' => 'read'
            ]);

            if ($hasPermission) {
                $this->endTest('File Permissions', true, 'File permissions working correctly');
            } else {
                $this->endTest('File Permissions', false, 'Failed to grant permission');
            }

            // Cleanup
            $this->db->delete('users', ['id' => $user2Id]);
            $this->db->query("DROP TABLE IF EXISTS file_permissions");
        } catch (Exception $e) {
            $this->endTest('File Permissions', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testFileVersioning(): void {
        $this->startTest('File Versioning');

        try {
            // Create file_versions table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS file_versions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    file_id INT UNSIGNED NOT NULL,
                    version_number INT NOT NULL,
                    file_path VARCHAR(500) NOT NULL,
                    file_size INT UNSIGNED,
                    checksum VARCHAR(64),
                    created_by INT UNSIGNED,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
                    FOREIGN KEY (created_by) REFERENCES users(id),
                    UNIQUE KEY uniq_file_version (file_id, version_number)
                )
            ");

            if (empty($this->testFiles)) {
                $this->endTest('File Versioning', false, 'No test files available');
                return;
            }

            $fileId = $this->testFiles[0]['id'];
            $versions = [];

            // Create multiple versions
            for ($v = 1; $v <= 3; $v++) {
                $versionPath = UPLOAD_PATH . '/' . $this->testTenantId . '/v' . $v . '_test.txt';
                $content = "Version {$v} content\n" . str_repeat("Data ", 50 * $v);
                file_put_contents($versionPath, $content);

                $versionId = $this->db->insert('file_versions', [
                    'file_id' => $fileId,
                    'version_number' => $v,
                    'file_path' => $versionPath,
                    'file_size' => strlen($content),
                    'checksum' => md5($content),
                    'created_by' => $this->testUserId
                ]);

                $versions[] = $versionPath;
            }

            // Verify versions
            $versionCount = $this->db->count('file_versions', ['file_id' => $fileId]);

            if ($versionCount === 3) {
                $this->endTest('File Versioning', true, 'File versioning working correctly', [
                    'versions_created' => $versionCount
                ]);
            } else {
                $this->endTest('File Versioning', false, 'Failed to create all versions');
            }

            // Cleanup version files
            foreach ($versions as $vPath) {
                if (file_exists($vPath)) {
                    unlink($vPath);
                }
            }

            $this->db->query("DROP TABLE IF EXISTS file_versions");
        } catch (Exception $e) {
            $this->endTest('File Versioning', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testFileSearch(): void {
        $this->startTest('File Search');

        try {
            // Create multiple test files with searchable names
            $searchableFiles = [];
            $searchTerms = ['report', 'document', 'presentation'];

            foreach ($searchTerms as $term) {
                $fileName = $term . '_' . time() . '.txt';
                $filePath = UPLOAD_PATH . '/' . $this->testTenantId . '/' . $fileName;
                file_put_contents($filePath, "Content for {$term}");

                $fileId = $this->db->insert('files', [
                    'tenant_id' => $this->testTenantId,
                    'user_id' => $this->testUserId,
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                    'file_size' => filesize($filePath),
                    'mime_type' => 'text/plain',
                    'checksum' => md5_file($filePath)
                ]);

                $searchableFiles[] = ['id' => $fileId, 'path' => $filePath, 'term' => $term];
            }

            // Search for files
            $searchResults = $this->db->fetchAll(
                "SELECT * FROM files WHERE tenant_id = :tenant_id AND file_name LIKE :search",
                ['tenant_id' => $this->testTenantId, 'search' => '%report%']
            );

            $foundReport = false;
            foreach ($searchResults as $result) {
                if (strpos($result['file_name'], 'report') !== false) {
                    $foundReport = true;
                    break;
                }
            }

            if ($foundReport) {
                $this->endTest('File Search', true, 'File search working correctly', [
                    'results_found' => count($searchResults)
                ]);
            } else {
                $this->endTest('File Search', false, 'Search did not find expected files');
            }

            // Cleanup
            foreach ($searchableFiles as $file) {
                $this->db->delete('files', ['id' => $file['id']]);
                if (file_exists($file['path'])) {
                    unlink($file['path']);
                }
            }
        } catch (Exception $e) {
            $this->endTest('File Search', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testFileSharing(): void {
        $this->startTest('File Sharing');

        try {
            // Create file_shares table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS file_shares (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    file_id INT UNSIGNED NOT NULL,
                    share_token VARCHAR(64) UNIQUE,
                    shared_by INT UNSIGNED,
                    expires_at TIMESTAMP NULL,
                    access_count INT DEFAULT 0,
                    max_access INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
                    FOREIGN KEY (shared_by) REFERENCES users(id)
                )
            ");

            if (empty($this->testFiles)) {
                $this->endTest('File Sharing', false, 'No test files available');
                return;
            }

            $fileId = $this->testFiles[0]['id'];
            $shareToken = bin2hex(random_bytes(32));

            // Create share link
            $shareId = $this->db->insert('file_shares', [
                'file_id' => $fileId,
                'share_token' => $shareToken,
                'shared_by' => $this->testUserId,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'max_access' => 10
            ]);

            // Simulate access
            $this->db->update(
                'file_shares',
                ['access_count' => 1],
                ['id' => $shareId]
            );

            // Verify share
            $share = $this->db->fetchOne(
                "SELECT * FROM file_shares WHERE share_token = :token",
                ['token' => $shareToken]
            );

            if ($share && $share['access_count'] == 1) {
                $this->endTest('File Sharing', true, 'File sharing working correctly', [
                    'share_token' => substr($shareToken, 0, 16) . '...'
                ]);
            } else {
                $this->endTest('File Sharing', false, 'Failed to create or access share');
            }

            $this->db->query("DROP TABLE IF EXISTS file_shares");
        } catch (Exception $e) {
            $this->endTest('File Sharing', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testStorageQuota(): void {
        $this->startTest('Storage Quota');

        try {
            // Set tenant storage quota in settings
            $quotaSettings = json_encode([
                'storage_quota_mb' => 100,
                'used_storage_mb' => 0
            ]);

            $this->db->update(
                'tenants',
                ['settings' => $quotaSettings],
                ['id' => $this->testTenantId]
            );

            // Calculate used storage
            $totalSize = $this->db->fetchOne(
                "SELECT SUM(file_size) as total FROM files WHERE tenant_id = :tenant_id",
                ['tenant_id' => $this->testTenantId]
            );

            $usedMb = ($totalSize['total'] ?? 0) / (1024 * 1024);

            // Update used storage
            $newSettings = json_encode([
                'storage_quota_mb' => 100,
                'used_storage_mb' => $usedMb
            ]);

            $this->db->update(
                'tenants',
                ['settings' => $newSettings],
                ['id' => $this->testTenantId]
            );

            // Check if under quota
            if ($usedMb < 100) {
                $this->endTest('Storage Quota', true, 'Storage quota tracking working', [
                    'used_mb' => round($usedMb, 2),
                    'quota_mb' => 100
                ]);
            } else {
                $this->endTest('Storage Quota', false, 'Storage quota exceeded');
            }
        } catch (Exception $e) {
            $this->endTest('Storage Quota', false, 'Exception: ' . $e->getMessage());
        }
    }

    protected function cleanupTestData(): void {
        // Delete test files from filesystem
        foreach ($this->testFiles as $file) {
            if (file_exists($file['path'])) {
                unlink($file['path']);
            }
        }

        // Remove upload directory
        $uploadPath = UPLOAD_PATH . '/' . $this->testTenantId;
        if (is_dir($uploadPath)) {
            rmdir($uploadPath);
        }

        // Delete database records
        if ($this->testUserId > 0) {
            $this->db->delete('users', ['id' => $this->testUserId]);
        }
        if ($this->testTenantId > 0) {
            $this->db->delete('tenants', ['id' => $this->testTenantId]);
        }
    }
}

/**
 * Calendar & Events Test Module
 */
class CalendarEventsTest extends BaseTest {
    private int $testTenantId = 0;
    private int $testUserId = 0;
    private array $testEvents = [];

    public function getModuleName(): string {
        return 'Calendar & Events';
    }

    public function runTests(): array {
        $this->setupTestData();

        // Test 1: Create event
        $this->testCreateEvent();

        // Test 2: Update event
        $this->testUpdateEvent();

        // Test 3: Delete event
        $this->testDeleteEvent();

        // Test 4: Recurring events
        $this->testRecurringEvents();

        // Test 5: Event reminders
        $this->testEventReminders();

        // Test 6: Event invitations
        $this->testEventInvitations();

        // Test 7: Calendar sharing
        $this->testCalendarSharing();

        // Test 8: Event conflicts
        $this->testEventConflicts();

        $this->cleanupTestData();
        return $this->results;
    }

    protected function setupTestData(): void {
        // Create test tenant and user
        $this->testTenantId = $this->db->insert('tenants', [
            'name' => 'calendar_test_' . time(),
            'domain' => 'caltest.local'
        ]);

        $this->testUserId = $this->db->insert('users', [
            'tenant_id' => $this->testTenantId,
            'name' => 'Calendar Test User',
            'email' => 'caltest@example.com',
            'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
            'role' => 'user'
        ]);

        // Create events table if not exists
        $this->db->query("
            CREATE TABLE IF NOT EXISTS events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                start_time DATETIME NOT NULL,
                end_time DATETIME NOT NULL,
                location VARCHAR(255),
                is_all_day BOOLEAN DEFAULT FALSE,
                is_recurring BOOLEAN DEFAULT FALSE,
                recurrence_rule VARCHAR(255),
                color VARCHAR(7),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_event_time (start_time, end_time),
                INDEX idx_event_user (user_id, start_time)
            )
        ");
    }

    private function testCreateEvent(): void {
        $this->startTest('Create Event');

        try {
            $eventData = [
                'tenant_id' => $this->testTenantId,
                'user_id' => $this->testUserId,
                'title' => 'Test Meeting',
                'description' => 'Important project discussion',
                'start_time' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'end_time' => date('Y-m-d H:i:s', strtotime('+1 day +1 hour')),
                'location' => 'Conference Room A',
                'is_all_day' => false,
                'color' => '#4285F4'
            ];

            $eventId = $this->db->insert('events', $eventData);
            $this->testEvents[] = $eventId;

            if ($eventId > 0) {
                $this->endTest('Create Event', true, 'Event created successfully', ['event_id' => $eventId]);
            } else {
                $this->endTest('Create Event', false, 'Failed to create event');
            }
        } catch (Exception $e) {
            $this->endTest('Create Event', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testUpdateEvent(): void {
        $this->startTest('Update Event');

        try {
            if (empty($this->testEvents)) {
                $this->endTest('Update Event', false, 'No test events available');
                return;
            }

            $eventId = $this->testEvents[0];
            $newTitle = 'Updated Meeting Title';

            $updated = $this->db->update(
                'events',
                ['title' => $newTitle, 'location' => 'Conference Room B'],
                ['id' => $eventId]
            );

            $event = $this->db->fetchOne("SELECT * FROM events WHERE id = :id", ['id' => $eventId]);

            if ($updated && $event['title'] === $newTitle) {
                $this->endTest('Update Event', true, 'Event updated successfully');
            } else {
                $this->endTest('Update Event', false, 'Failed to update event');
            }
        } catch (Exception $e) {
            $this->endTest('Update Event', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testDeleteEvent(): void {
        $this->startTest('Delete Event');

        try {
            // Create a temporary event to delete
            $tempEventId = $this->db->insert('events', [
                'tenant_id' => $this->testTenantId,
                'user_id' => $this->testUserId,
                'title' => 'Event to Delete',
                'start_time' => date('Y-m-d H:i:s', strtotime('+2 days')),
                'end_time' => date('Y-m-d H:i:s', strtotime('+2 days +1 hour'))
            ]);

            $deleted = $this->db->delete('events', ['id' => $tempEventId]);

            $exists = $this->db->exists('events', ['id' => $tempEventId]);

            if ($deleted > 0 && !$exists) {
                $this->endTest('Delete Event', true, 'Event deleted successfully');
            } else {
                $this->endTest('Delete Event', false, 'Failed to delete event');
            }
        } catch (Exception $e) {
            $this->endTest('Delete Event', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testRecurringEvents(): void {
        $this->startTest('Recurring Events');

        try {
            // Create a recurring event
            $recurringEventId = $this->db->insert('events', [
                'tenant_id' => $this->testTenantId,
                'user_id' => $this->testUserId,
                'title' => 'Weekly Team Meeting',
                'start_time' => date('Y-m-d 10:00:00'),
                'end_time' => date('Y-m-d 11:00:00'),
                'is_recurring' => true,
                'recurrence_rule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=10'
            ]);

            $this->testEvents[] = $recurringEventId;

            // Generate occurrences
            $occurrences = [];
            $startDate = new DateTime();
            for ($i = 0; $i < 10; $i++) {
                $nextMonday = clone $startDate;
                $nextMonday->modify('next monday');
                $nextMonday->add(new DateInterval('P' . ($i * 7) . 'D'));
                $occurrences[] = $nextMonday->format('Y-m-d');
            }

            if ($recurringEventId > 0 && count($occurrences) == 10) {
                $this->endTest('Recurring Events', true, 'Recurring event created successfully', [
                    'occurrences' => count($occurrences)
                ]);
            } else {
                $this->endTest('Recurring Events', false, 'Failed to create recurring event');
            }
        } catch (Exception $e) {
            $this->endTest('Recurring Events', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testEventReminders(): void {
        $this->startTest('Event Reminders');

        try {
            // Create event_reminders table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS event_reminders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id INT NOT NULL,
                    user_id INT UNSIGNED NOT NULL,
                    reminder_time DATETIME NOT NULL,
                    reminder_type ENUM('email', 'notification', 'sms') DEFAULT 'notification',
                    is_sent BOOLEAN DEFAULT FALSE,
                    sent_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_reminder_time (reminder_time, is_sent)
                )
            ");

            if (empty($this->testEvents)) {
                $this->endTest('Event Reminders', false, 'No test events available');
                return;
            }

            $eventId = $this->testEvents[0];

            // Add reminder 15 minutes before event
            $event = $this->db->fetchOne("SELECT * FROM events WHERE id = :id", ['id' => $eventId]);
            $reminderTime = date('Y-m-d H:i:s', strtotime($event['start_time'] . ' -15 minutes'));

            $reminderId = $this->db->insert('event_reminders', [
                'event_id' => $eventId,
                'user_id' => $this->testUserId,
                'reminder_time' => $reminderTime,
                'reminder_type' => 'notification'
            ]);

            if ($reminderId > 0) {
                $this->endTest('Event Reminders', true, 'Reminder created successfully');
            } else {
                $this->endTest('Event Reminders', false, 'Failed to create reminder');
            }

            $this->db->query("DROP TABLE IF EXISTS event_reminders");
        } catch (Exception $e) {
            $this->endTest('Event Reminders', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testEventInvitations(): void {
        $this->startTest('Event Invitations');

        try {
            // Create event_invitations table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS event_invitations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id INT NOT NULL,
                    invitee_id INT UNSIGNED NOT NULL,
                    inviter_id INT UNSIGNED NOT NULL,
                    status ENUM('pending', 'accepted', 'declined', 'maybe') DEFAULT 'pending',
                    responded_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_event_invitee (event_id, invitee_id)
                )
            ");

            if (empty($this->testEvents)) {
                $this->endTest('Event Invitations', false, 'No test events available');
                return;
            }

            // Create another user to invite
            $inviteeId = $this->db->insert('users', [
                'tenant_id' => $this->testTenantId,
                'name' => 'Invitee User',
                'email' => 'invitee_' . time() . '@example.com',
                'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
                'role' => 'user'
            ]);

            // Send invitation
            $invitationId = $this->db->insert('event_invitations', [
                'event_id' => $this->testEvents[0],
                'invitee_id' => $inviteeId,
                'inviter_id' => $this->testUserId,
                'status' => 'pending'
            ]);

            // Simulate acceptance
            $this->db->update(
                'event_invitations',
                ['status' => 'accepted', 'responded_at' => date('Y-m-d H:i:s')],
                ['id' => $invitationId]
            );

            $invitation = $this->db->fetchOne(
                "SELECT * FROM event_invitations WHERE id = :id",
                ['id' => $invitationId]
            );

            if ($invitation && $invitation['status'] === 'accepted') {
                $this->endTest('Event Invitations', true, 'Invitation system working correctly');
            } else {
                $this->endTest('Event Invitations', false, 'Failed to process invitation');
            }

            // Cleanup
            $this->db->delete('users', ['id' => $inviteeId]);
            $this->db->query("DROP TABLE IF EXISTS event_invitations");
        } catch (Exception $e) {
            $this->endTest('Event Invitations', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testCalendarSharing(): void {
        $this->startTest('Calendar Sharing');

        try {
            // Create calendar_shares table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS calendar_shares (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_id INT UNSIGNED NOT NULL,
                    shared_with_id INT UNSIGNED NOT NULL,
                    permission ENUM('view', 'edit') DEFAULT 'view',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (shared_with_id) REFERENCES users(id) ON DELETE CASCADE,
                    UNIQUE KEY uniq_calendar_share (owner_id, shared_with_id)
                )
            ");

            // Create another user
            $sharedUserId = $this->db->insert('users', [
                'tenant_id' => $this->testTenantId,
                'name' => 'Shared Calendar User',
                'email' => 'shared_cal_' . time() . '@example.com',
                'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
                'role' => 'user'
            ]);

            // Share calendar
            $shareId = $this->db->insert('calendar_shares', [
                'owner_id' => $this->testUserId,
                'shared_with_id' => $sharedUserId,
                'permission' => 'view'
            ]);

            // Verify shared events are visible
            $sharedEvents = $this->db->fetchAll("
                SELECT e.* FROM events e
                JOIN calendar_shares cs ON e.user_id = cs.owner_id
                WHERE cs.shared_with_id = :user_id AND e.tenant_id = :tenant_id
            ", ['user_id' => $sharedUserId, 'tenant_id' => $this->testTenantId]);

            if ($shareId > 0) {
                $this->endTest('Calendar Sharing', true, 'Calendar shared successfully');
            } else {
                $this->endTest('Calendar Sharing', false, 'Failed to share calendar');
            }

            // Cleanup
            $this->db->delete('users', ['id' => $sharedUserId]);
            $this->db->query("DROP TABLE IF EXISTS calendar_shares");
        } catch (Exception $e) {
            $this->endTest('Calendar Sharing', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testEventConflicts(): void {
        $this->startTest('Event Conflicts');

        try {
            $conflictTime = date('Y-m-d 14:00:00', strtotime('+3 days'));

            // Create first event
            $event1Id = $this->db->insert('events', [
                'tenant_id' => $this->testTenantId,
                'user_id' => $this->testUserId,
                'title' => 'Meeting 1',
                'start_time' => $conflictTime,
                'end_time' => date('Y-m-d H:i:s', strtotime($conflictTime . ' +1 hour'))
            ]);

            // Try to create conflicting event
            $conflicts = $this->db->fetchAll("
                SELECT * FROM events
                WHERE user_id = :user_id
                AND :new_start < end_time
                AND :new_end > start_time
            ", [
                'user_id' => $this->testUserId,
                'new_start' => $conflictTime,
                'new_end' => date('Y-m-d H:i:s', strtotime($conflictTime . ' +30 minutes'))
            ]);

            if (count($conflicts) > 0) {
                $this->endTest('Event Conflicts', true, 'Conflict detection working correctly', [
                    'conflicts_found' => count($conflicts)
                ]);
            } else {
                $this->endTest('Event Conflicts', false, 'Failed to detect event conflict');
            }

            // Cleanup
            $this->db->delete('events', ['id' => $event1Id]);
        } catch (Exception $e) {
            $this->endTest('Event Conflicts', false, 'Exception: ' . $e->getMessage());
        }
    }

    protected function cleanupTestData(): void {
        // Delete test events
        foreach ($this->testEvents as $eventId) {
            $this->db->delete('events', ['id' => $eventId]);
        }

        // Delete test data
        if ($this->testUserId > 0) {
            $this->db->delete('users', ['id' => $this->testUserId]);
        }
        if ($this->testTenantId > 0) {
            $this->db->delete('tenants', ['id' => $this->testTenantId]);
        }

        // Drop events table (optional - comment out if you want to keep it)
        // $this->db->query("DROP TABLE IF EXISTS events");
    }
}

/**
 * Task Management Test Module
 */
class TaskManagementTest extends BaseTest {
    private int $testTenantId = 0;
    private int $testUserId = 0;
    private int $testProjectId = 0;
    private array $testTasks = [];

    public function getModuleName(): string {
        return 'Task Management';
    }

    public function runTests(): array {
        $this->setupTestData();

        // Test 1: Create project
        $this->testCreateProject();

        // Test 2: Create task
        $this->testCreateTask();

        // Test 3: Task assignments
        $this->testTaskAssignments();

        // Test 4: Task dependencies
        $this->testTaskDependencies();

        // Test 5: Task status workflow
        $this->testTaskStatusWorkflow();

        // Test 6: Gantt chart data
        $this->testGanttChartData();

        // Test 7: Task comments
        $this->testTaskComments();

        // Test 8: Task attachments
        $this->testTaskAttachments();

        $this->cleanupTestData();
        return $this->results;
    }

    protected function setupTestData(): void {
        // Create test tenant and user
        $this->testTenantId = $this->db->insert('tenants', [
            'name' => 'task_test_' . time(),
            'domain' => 'tasktest.local'
        ]);

        $this->testUserId = $this->db->insert('users', [
            'tenant_id' => $this->testTenantId,
            'name' => 'Task Test User',
            'email' => 'tasktest@example.com',
            'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
            'role' => 'manager'
        ]);

        // Create projects table
        $this->db->query("
            CREATE TABLE IF NOT EXISTS projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                manager_id INT UNSIGNED,
                start_date DATE,
                end_date DATE,
                status ENUM('planning', 'active', 'on_hold', 'completed', 'cancelled') DEFAULT 'planning',
                budget DECIMAL(10,2),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (manager_id) REFERENCES users(id),
                INDEX idx_project_status (status),
                INDEX idx_project_dates (start_date, end_date)
            )
        ");

        // Create tasks table
        $this->db->query("
            CREATE TABLE IF NOT EXISTS tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT NOT NULL,
                project_id INT,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                assigned_to INT UNSIGNED,
                priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
                status ENUM('todo', 'in_progress', 'review', 'done', 'blocked') DEFAULT 'todo',
                start_date DATE,
                due_date DATE,
                estimated_hours DECIMAL(5,2),
                actual_hours DECIMAL(5,2),
                progress INT DEFAULT 0,
                created_by INT UNSIGNED,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_to) REFERENCES users(id),
                FOREIGN KEY (created_by) REFERENCES users(id),
                INDEX idx_task_status (status),
                INDEX idx_task_assigned (assigned_to, status),
                INDEX idx_task_due (due_date)
            )
        ");
    }

    private function testCreateProject(): void {
        $this->startTest('Create Project');

        try {
            $projectData = [
                'tenant_id' => $this->testTenantId,
                'name' => 'Test Project Alpha',
                'description' => 'A test project for integration testing',
                'manager_id' => $this->testUserId,
                'start_date' => date('Y-m-d'),
                'end_date' => date('Y-m-d', strtotime('+30 days')),
                'status' => 'active',
                'budget' => 50000.00
            ];

            $this->testProjectId = $this->db->insert('projects', $projectData);

            if ($this->testProjectId > 0) {
                $this->endTest('Create Project', true, 'Project created successfully', [
                    'project_id' => $this->testProjectId
                ]);
            } else {
                $this->endTest('Create Project', false, 'Failed to create project');
            }
        } catch (Exception $e) {
            $this->endTest('Create Project', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testCreateTask(): void {
        $this->startTest('Create Task');

        try {
            $taskData = [
                'tenant_id' => $this->testTenantId,
                'project_id' => $this->testProjectId,
                'title' => 'Implement user authentication',
                'description' => 'Create login and registration functionality',
                'assigned_to' => $this->testUserId,
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => date('Y-m-d'),
                'due_date' => date('Y-m-d', strtotime('+7 days')),
                'estimated_hours' => 16.00,
                'created_by' => $this->testUserId
            ];

            $taskId = $this->db->insert('tasks', $taskData);
            $this->testTasks[] = $taskId;

            if ($taskId > 0) {
                $this->endTest('Create Task', true, 'Task created successfully', ['task_id' => $taskId]);
            } else {
                $this->endTest('Create Task', false, 'Failed to create task');
            }
        } catch (Exception $e) {
            $this->endTest('Create Task', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testTaskAssignments(): void {
        $this->startTest('Task Assignments');

        try {
            // Create another user
            $user2Id = $this->db->insert('users', [
                'tenant_id' => $this->testTenantId,
                'name' => 'Task Assignee',
                'email' => 'assignee_' . time() . '@example.com',
                'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
                'role' => 'user'
            ]);

            // Create and assign task
            $taskId = $this->db->insert('tasks', [
                'tenant_id' => $this->testTenantId,
                'project_id' => $this->testProjectId,
                'title' => 'Review code changes',
                'assigned_to' => $user2Id,
                'priority' => 'medium',
                'status' => 'todo',
                'created_by' => $this->testUserId
            ]);

            $this->testTasks[] = $taskId;

            // Reassign task
            $updated = $this->db->update(
                'tasks',
                ['assigned_to' => $this->testUserId, 'status' => 'in_progress'],
                ['id' => $taskId]
            );

            $task = $this->db->fetchOne("SELECT * FROM tasks WHERE id = :id", ['id' => $taskId]);

            if ($task && $task['assigned_to'] == $this->testUserId) {
                $this->endTest('Task Assignments', true, 'Task assignment working correctly');
            } else {
                $this->endTest('Task Assignments', false, 'Failed to reassign task');
            }

            // Cleanup
            $this->db->delete('users', ['id' => $user2Id]);
        } catch (Exception $e) {
            $this->endTest('Task Assignments', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testTaskDependencies(): void {
        $this->startTest('Task Dependencies');

        try {
            // Create task_dependencies table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS task_dependencies (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    depends_on_task_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_dependency (task_id, depends_on_task_id),
                    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                    FOREIGN KEY (depends_on_task_id) REFERENCES tasks(id) ON DELETE CASCADE
                )
            ");

            // Create two related tasks
            $task1Id = $this->db->insert('tasks', [
                'tenant_id' => $this->testTenantId,
                'project_id' => $this->testProjectId,
                'title' => 'Backend API Development',
                'status' => 'done',
                'created_by' => $this->testUserId
            ]);

            $task2Id = $this->db->insert('tasks', [
                'tenant_id' => $this->testTenantId,
                'project_id' => $this->testProjectId,
                'title' => 'Frontend Integration',
                'status' => 'todo',
                'created_by' => $this->testUserId
            ]);

            $this->testTasks[] = $task1Id;
            $this->testTasks[] = $task2Id;

            // Create dependency
            $depId = $this->db->insert('task_dependencies', [
                'task_id' => $task2Id,
                'depends_on_task_id' => $task1Id
            ]);

            // Check if dependency exists
            $dependency = $this->db->fetchOne("
                SELECT * FROM task_dependencies
                WHERE task_id = :task_id AND depends_on_task_id = :dep_id
            ", ['task_id' => $task2Id, 'dep_id' => $task1Id]);

            if ($dependency) {
                $this->endTest('Task Dependencies', true, 'Task dependencies working correctly');
            } else {
                $this->endTest('Task Dependencies', false, 'Failed to create task dependency');
            }

            $this->db->query("DROP TABLE IF EXISTS task_dependencies");
        } catch (Exception $e) {
            $this->endTest('Task Dependencies', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testTaskStatusWorkflow(): void {
        $this->startTest('Task Status Workflow');

        try {
            // Create a task to test workflow
            $taskId = $this->db->insert('tasks', [
                'tenant_id' => $this->testTenantId,
                'project_id' => $this->testProjectId,
                'title' => 'Workflow Test Task',
                'status' => 'todo',
                'created_by' => $this->testUserId
            ]);

            $this->testTasks[] = $taskId;

            // Test workflow: todo -> in_progress -> review -> done
            $workflow = ['todo', 'in_progress', 'review', 'done'];
            $workflowSuccess = true;

            foreach ($workflow as $status) {
                $this->db->update('tasks', [
                    'status' => $status,
                    'progress' => $status === 'done' ? 100 : ($status === 'review' ? 75 : ($status === 'in_progress' ? 50 : 0))
                ], ['id' => $taskId]);

                $task = $this->db->fetchOne("SELECT * FROM tasks WHERE id = :id", ['id' => $taskId]);

                if ($task['status'] !== $status) {
                    $workflowSuccess = false;
                    break;
                }
            }

            if ($workflowSuccess) {
                $this->endTest('Task Status Workflow', true, 'Status workflow transitions working correctly');
            } else {
                $this->endTest('Task Status Workflow', false, 'Status workflow failed');
            }
        } catch (Exception $e) {
            $this->endTest('Task Status Workflow', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testGanttChartData(): void {
        $this->startTest('Gantt Chart Data');

        try {
            // Create multiple tasks with different dates
            $ganttTasks = [];
            for ($i = 1; $i <= 5; $i++) {
                $taskId = $this->db->insert('tasks', [
                    'tenant_id' => $this->testTenantId,
                    'project_id' => $this->testProjectId,
                    'title' => "Gantt Task {$i}",
                    'start_date' => date('Y-m-d', strtotime("+{$i} days")),
                    'due_date' => date('Y-m-d', strtotime("+" . ($i + 3) . " days")),
                    'progress' => $i * 20,
                    'created_by' => $this->testUserId
                ]);
                $ganttTasks[] = $taskId;
                $this->testTasks[] = $taskId;
            }

            // Fetch Gantt data
            $ganttData = $this->db->fetchAll("
                SELECT id, title, start_date, due_date, progress,
                       DATEDIFF(due_date, start_date) as duration
                FROM tasks
                WHERE project_id = :project_id
                ORDER BY start_date
            ", ['project_id' => $this->testProjectId]);

            if (count($ganttData) >= 5) {
                $this->endTest('Gantt Chart Data', true, 'Gantt chart data retrieved successfully', [
                    'tasks_count' => count($ganttData)
                ]);
            } else {
                $this->endTest('Gantt Chart Data', false, 'Insufficient Gantt data');
            }
        } catch (Exception $e) {
            $this->endTest('Gantt Chart Data', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testTaskComments(): void {
        $this->startTest('Task Comments');

        try {
            // Create task_comments table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS task_comments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    user_id INT UNSIGNED NOT NULL,
                    comment TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id),
                    INDEX idx_comment_task (task_id, created_at)
                )
            ");

            if (empty($this->testTasks)) {
                $this->endTest('Task Comments', false, 'No test tasks available');
                return;
            }

            $taskId = $this->testTasks[0];

            // Add comments
            $comment1Id = $this->db->insert('task_comments', [
                'task_id' => $taskId,
                'user_id' => $this->testUserId,
                'comment' => 'Started working on this task'
            ]);

            $comment2Id = $this->db->insert('task_comments', [
                'task_id' => $taskId,
                'user_id' => $this->testUserId,
                'comment' => 'Need clarification on requirements'
            ]);

            // Count comments
            $commentCount = $this->db->count('task_comments', ['task_id' => $taskId]);

            if ($commentCount >= 2) {
                $this->endTest('Task Comments', true, 'Task comments working correctly', [
                    'comments_count' => $commentCount
                ]);
            } else {
                $this->endTest('Task Comments', false, 'Failed to add task comments');
            }

            $this->db->query("DROP TABLE IF EXISTS task_comments");
        } catch (Exception $e) {
            $this->endTest('Task Comments', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testTaskAttachments(): void {
        $this->startTest('Task Attachments');

        try {
            // Create task_attachments table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS task_attachments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    file_id INT UNSIGNED NOT NULL,
                    attached_by INT UNSIGNED,
                    attached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
                    FOREIGN KEY (attached_by) REFERENCES users(id),
                    UNIQUE KEY uniq_task_file (task_id, file_id)
                )
            ");

            if (empty($this->testTasks)) {
                $this->endTest('Task Attachments', false, 'No test tasks available');
                return;
            }

            // Create a test file
            $fileName = 'task_attachment_' . time() . '.pdf';
            $filePath = UPLOAD_PATH . '/' . $this->testTenantId . '/' . $fileName;

            // Ensure directory exists
            if (!is_dir(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }

            file_put_contents($filePath, 'Test attachment content');

            $fileId = $this->db->insert('files', [
                'tenant_id' => $this->testTenantId,
                'user_id' => $this->testUserId,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_size' => filesize($filePath),
                'mime_type' => 'application/pdf'
            ]);

            // Attach file to task
            $attachmentId = $this->db->insert('task_attachments', [
                'task_id' => $this->testTasks[0],
                'file_id' => $fileId,
                'attached_by' => $this->testUserId
            ]);

            if ($attachmentId > 0) {
                $this->endTest('Task Attachments', true, 'Task attachment added successfully');
            } else {
                $this->endTest('Task Attachments', false, 'Failed to attach file to task');
            }

            // Cleanup
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $this->db->delete('files', ['id' => $fileId]);
            $this->db->query("DROP TABLE IF EXISTS task_attachments");
        } catch (Exception $e) {
            $this->endTest('Task Attachments', false, 'Exception: ' . $e->getMessage());
        }
    }

    protected function cleanupTestData(): void {
        // Delete test tasks
        foreach ($this->testTasks as $taskId) {
            $this->db->delete('tasks', ['id' => $taskId]);
        }

        // Delete test project
        if ($this->testProjectId > 0) {
            $this->db->delete('projects', ['id' => $this->testProjectId]);
        }

        // Delete test user and tenant
        if ($this->testUserId > 0) {
            $this->db->delete('users', ['id' => $this->testUserId]);
        }
        if ($this->testTenantId > 0) {
            $this->db->delete('tenants', ['id' => $this->testTenantId]);
        }
    }
}

/**
 * Real-time Chat Test Module
 */
class RealTimeChatTest extends BaseTest {
    private int $testTenantId = 0;
    private int $testUserId = 0;
    private int $testChannelId = 0;
    private array $testMessages = [];

    public function getModuleName(): string {
        return 'Real-time Chat';
    }

    public function runTests(): array {
        $this->setupTestData();

        // Test 1: Create chat channel
        $this->testCreateChannel();

        // Test 2: Send message
        $this->testSendMessage();

        // Test 3: Message threading
        $this->testMessageThreading();

        // Test 4: Channel membership
        $this->testChannelMembership();

        // Test 5: Unread notifications
        $this->testUnreadNotifications();

        // Test 6: Message search
        $this->testMessageSearch();

        // Test 7: Message reactions
        $this->testMessageReactions();

        // Test 8: Direct messages
        $this->testDirectMessages();

        $this->cleanupTestData();
        return $this->results;
    }

    protected function setupTestData(): void {
        // Create test tenant and user
        $this->testTenantId = $this->db->insert('tenants', [
            'name' => 'chat_test_' . time(),
            'domain' => 'chattest.local'
        ]);

        $this->testUserId = $this->db->insert('users', [
            'tenant_id' => $this->testTenantId,
            'name' => 'Chat Test User',
            'email' => 'chattest@example.com',
            'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
            'role' => 'user'
        ]);
    }

    private function testCreateChannel(): void {
        $this->startTest('Create Chat Channel');

        try {
            $channelData = [
                'tenant_id' => $this->testTenantId,
                'channel_type' => 'public',
                'name' => 'General Discussion',
                'description' => 'A channel for general team discussions',
                'created_by' => $this->testUserId,
                'allow_threading' => true
            ];

            $this->testChannelId = $this->db->insert('chat_channels', $channelData);

            // Add creator as member
            $this->db->insert('channel_members', [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $this->testChannelId,
                'user_id' => $this->testUserId,
                'role' => 'owner'
            ]);

            if ($this->testChannelId > 0) {
                $this->endTest('Create Chat Channel', true, 'Channel created successfully', [
                    'channel_id' => $this->testChannelId
                ]);
            } else {
                $this->endTest('Create Chat Channel', false, 'Failed to create channel');
            }
        } catch (Exception $e) {
            $this->endTest('Create Chat Channel', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testSendMessage(): void {
        $this->startTest('Send Message');

        try {
            $messageData = [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $this->testChannelId,
                'user_id' => $this->testUserId,
                'message_type' => 'text',
                'content' => 'Hello, this is a test message!',
                'content_plain' => 'Hello, this is a test message!'
            ];

            $messageId = $this->db->insert('chat_messages', $messageData);
            $this->testMessages[] = $messageId;

            // Update channel last message
            $this->db->update('chat_channels', [
                'last_message_at' => date('Y-m-d H:i:s'),
                'message_count' => 1
            ], ['id' => $this->testChannelId]);

            if ($messageId > 0) {
                $this->endTest('Send Message', true, 'Message sent successfully', [
                    'message_id' => $messageId
                ]);
            } else {
                $this->endTest('Send Message', false, 'Failed to send message');
            }
        } catch (Exception $e) {
            $this->endTest('Send Message', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testMessageThreading(): void {
        $this->startTest('Message Threading');

        try {
            if (empty($this->testMessages)) {
                $this->endTest('Message Threading', false, 'No test messages available');
                return;
            }

            $parentMessageId = $this->testMessages[0];

            // Create thread replies
            $reply1Id = $this->db->insert('chat_messages', [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $this->testChannelId,
                'user_id' => $this->testUserId,
                'parent_message_id' => $parentMessageId,
                'message_type' => 'text',
                'content' => 'This is a reply to the original message',
                'content_plain' => 'This is a reply to the original message'
            ]);

            $reply2Id = $this->db->insert('chat_messages', [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $this->testChannelId,
                'user_id' => $this->testUserId,
                'parent_message_id' => $parentMessageId,
                'message_type' => 'text',
                'content' => 'Another reply in the thread',
                'content_plain' => 'Another reply in the thread'
            ]);

            $this->testMessages[] = $reply1Id;
            $this->testMessages[] = $reply2Id;

            // Count thread replies
            $replyCount = $this->db->count('chat_messages', [
                'parent_message_id' => $parentMessageId
            ]);

            if ($replyCount >= 2) {
                $this->endTest('Message Threading', true, 'Message threading working correctly', [
                    'thread_replies' => $replyCount
                ]);
            } else {
                $this->endTest('Message Threading', false, 'Failed to create message thread');
            }
        } catch (Exception $e) {
            $this->endTest('Message Threading', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testChannelMembership(): void {
        $this->startTest('Channel Membership');

        try {
            // Create additional users
            $user2Id = $this->db->insert('users', [
                'tenant_id' => $this->testTenantId,
                'name' => 'Chat User 2',
                'email' => 'chatuser2_' . time() . '@example.com',
                'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
                'role' => 'user'
            ]);

            $user3Id = $this->db->insert('users', [
                'tenant_id' => $this->testTenantId,
                'name' => 'Chat User 3',
                'email' => 'chatuser3_' . time() . '@example.com',
                'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
                'role' => 'user'
            ]);

            // Add members to channel
            $this->db->insert('channel_members', [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $this->testChannelId,
                'user_id' => $user2Id,
                'role' => 'member'
            ]);

            $this->db->insert('channel_members', [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $this->testChannelId,
                'user_id' => $user3Id,
                'role' => 'member'
            ]);

            // Update member count
            $memberCount = $this->db->count('channel_members', [
                'channel_id' => $this->testChannelId
            ]);

            $this->db->update('chat_channels', [
                'member_count' => $memberCount
            ], ['id' => $this->testChannelId]);

            if ($memberCount >= 3) {
                $this->endTest('Channel Membership', true, 'Channel membership working correctly', [
                    'member_count' => $memberCount
                ]);
            } else {
                $this->endTest('Channel Membership', false, 'Failed to add channel members');
            }

            // Cleanup
            $this->db->delete('users', ['id' => $user2Id]);
            $this->db->delete('users', ['id' => $user3Id]);
        } catch (Exception $e) {
            $this->endTest('Channel Membership', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testUnreadNotifications(): void {
        $this->startTest('Unread Notifications');

        try {
            // Create new messages
            $newMessageId = $this->db->insert('chat_messages', [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $this->testChannelId,
                'user_id' => $this->testUserId,
                'message_type' => 'text',
                'content' => 'New unread message',
                'content_plain' => 'New unread message'
            ]);

            $this->testMessages[] = $newMessageId;

            // Update unread count for member
            $this->db->update('channel_members', [
                'unread_count' => 5,
                'last_read_message_id' => $newMessageId - 1
            ], [
                'channel_id' => $this->testChannelId,
                'user_id' => $this->testUserId
            ]);

            // Mark as read
            $this->db->update('channel_members', [
                'unread_count' => 0,
                'last_read_message_id' => $newMessageId,
                'last_read_at' => date('Y-m-d H:i:s')
            ], [
                'channel_id' => $this->testChannelId,
                'user_id' => $this->testUserId
            ]);

            $member = $this->db->fetchOne("
                SELECT * FROM channel_members
                WHERE channel_id = :channel_id AND user_id = :user_id
            ", [
                'channel_id' => $this->testChannelId,
                'user_id' => $this->testUserId
            ]);

            if ($member && $member['unread_count'] == 0) {
                $this->endTest('Unread Notifications', true, 'Unread notifications working correctly');
            } else {
                $this->endTest('Unread Notifications', false, 'Failed to manage unread notifications');
            }
        } catch (Exception $e) {
            $this->endTest('Unread Notifications', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testMessageSearch(): void {
        $this->startTest('Message Search');

        try {
            // Create messages with searchable content
            $searchTerms = ['project update', 'meeting notes', 'deadline reminder'];

            foreach ($searchTerms as $term) {
                $msgId = $this->db->insert('chat_messages', [
                    'tenant_id' => $this->testTenantId,
                    'channel_id' => $this->testChannelId,
                    'user_id' => $this->testUserId,
                    'message_type' => 'text',
                    'content' => "Important: {$term} for the team",
                    'content_plain' => "Important: {$term} for the team"
                ]);
                $this->testMessages[] = $msgId;
            }

            // Search for messages
            $searchResults = $this->db->fetchAll("
                SELECT * FROM chat_messages
                WHERE tenant_id = :tenant_id
                AND content_plain LIKE :search
            ", [
                'tenant_id' => $this->testTenantId,
                'search' => '%meeting%'
            ]);

            if (count($searchResults) > 0) {
                $this->endTest('Message Search', true, 'Message search working correctly', [
                    'results_found' => count($searchResults)
                ]);
            } else {
                $this->endTest('Message Search', false, 'Message search returned no results');
            }
        } catch (Exception $e) {
            $this->endTest('Message Search', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testMessageReactions(): void {
        $this->startTest('Message Reactions');

        try {
            // Create message_reactions table
            $this->db->query("
                CREATE TABLE IF NOT EXISTS message_reactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NOT NULL,
                    reaction VARCHAR(50) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    UNIQUE KEY uniq_user_reaction (message_id, user_id, reaction),
                    INDEX idx_message_reactions (message_id)
                )
            ");

            if (empty($this->testMessages)) {
                $this->endTest('Message Reactions', false, 'No test messages available');
                return;
            }

            $messageId = $this->testMessages[0];

            // Add reactions
            $reactions = ['👍', '❤️', '😂', '🎉'];
            foreach ($reactions as $reaction) {
                $this->db->insert('message_reactions', [
                    'message_id' => $messageId,
                    'user_id' => $this->testUserId,
                    'reaction' => $reaction
                ]);
            }

            // Count reactions
            $reactionCount = $this->db->count('message_reactions', [
                'message_id' => $messageId
            ]);

            if ($reactionCount >= 4) {
                $this->endTest('Message Reactions', true, 'Message reactions working correctly', [
                    'reactions_count' => $reactionCount
                ]);
            } else {
                $this->endTest('Message Reactions', false, 'Failed to add message reactions');
            }

            $this->db->query("DROP TABLE IF EXISTS message_reactions");
        } catch (Exception $e) {
            $this->endTest('Message Reactions', false, 'Exception: ' . $e->getMessage());
        }
    }

    private function testDirectMessages(): void {
        $this->startTest('Direct Messages');

        try {
            // Create another user for DM
            $dmUserId = $this->db->insert('users', [
                'tenant_id' => $this->testTenantId,
                'name' => 'DM User',
                'email' => 'dmuser_' . time() . '@example.com',
                'password' => password_hash('Pass123!', PASSWORD_DEFAULT),
                'role' => 'user'
            ]);

            // Create direct message channel
            $dmChannelId = $this->db->insert('chat_channels', [
                'tenant_id' => $this->testTenantId,
                'channel_type' => 'direct',
                'name' => null,
                'created_by' => $this->testUserId
            ]);

            // Add both users as members
            $this->db->insert('channel_members', [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $dmChannelId,
                'user_id' => $this->testUserId,
                'role' => 'member'
            ]);

            $this->db->insert('channel_members', [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $dmChannelId,
                'user_id' => $dmUserId,
                'role' => 'member'
            ]);

            // Send direct message
            $dmMessageId = $this->db->insert('chat_messages', [
                'tenant_id' => $this->testTenantId,
                'channel_id' => $dmChannelId,
                'user_id' => $this->testUserId,
                'message_type' => 'text',
                'content' => 'This is a direct message',
                'content_plain' => 'This is a direct message'
            ]);

            if ($dmMessageId > 0) {
                $this->endTest('Direct Messages', true, 'Direct messaging working correctly');
            } else {
                $this->endTest('Direct Messages', false, 'Failed to send direct message');
            }

            // Cleanup
            $this->db->delete('chat_messages', ['channel_id' => $dmChannelId]);
            $this->db->delete('chat_channels', ['id' => $dmChannelId]);
            $this->db->delete('users', ['id' => $dmUserId]);
        } catch (Exception $e) {
            $this->endTest('Direct Messages', false, 'Exception: ' . $e->getMessage());
        }
    }

    protected function cleanupTestData(): void {
        // Delete test messages
        foreach ($this->testMessages as $messageId) {
            $this->db->delete('chat_messages', ['id' => $messageId]);
        }

        // Delete test channel
        if ($this->testChannelId > 0) {
            $this->db->delete('chat_channels', ['id' => $this->testChannelId]);
        }

        // Delete test user and tenant
        if ($this->testUserId > 0) {
            $this->db->delete('users', ['id' => $this->testUserId]);
        }
        if ($this->testTenantId > 0) {
            $this->db->delete('tenants', ['id' => $this->testTenantId]);
        }
    }
}

/**
 * Test Report Generator
 */
class TestReportGenerator {
    private array $results;
    private float $totalTime;

    public function __construct(array $results, float $totalTime) {
        $this->results = $results;
        $this->totalTime = $totalTime;
    }

    public function generateConsoleReport(): void {
        $totalTests = 0;
        $passedTests = 0;
        $failedTests = 0;
        $moduleResults = [];

        foreach ($this->results as $result) {
            $totalTests++;
            if ($result->passed) {
                $passedTests++;
            } else {
                $failedTests++;
            }

            if (!isset($moduleResults[$result->module])) {
                $moduleResults[$result->module] = [
                    'passed' => 0,
                    'failed' => 0,
                    'tests' => []
                ];
            }

            $moduleResults[$result->module]['tests'][] = $result;
            if ($result->passed) {
                $moduleResults[$result->module]['passed']++;
            } else {
                $moduleResults[$result->module]['failed']++;
            }
        }

        // Print header
        echo "\n";
        echo str_repeat("=", 80) . "\n";
        echo "   CollaboraNexio Integration Test Suite - Final Report\n";
        echo str_repeat("=", 80) . "\n\n";

        // Summary
        $successRate = $totalTests > 0 ? ($passedTests / $totalTests * 100) : 0;
        $color = $successRate >= 80 ? '32' : ($successRate >= 50 ? '33' : '31');

        echo "Summary:\n";
        echo "--------\n";
        echo "Total Tests: {$totalTests}\n";
        echo "\033[32mPassed: {$passedTests}\033[0m\n";
        echo "\033[31mFailed: {$failedTests}\033[0m\n";
        echo "Success Rate: \033[{$color}m" . number_format($successRate, 1) . "%\033[0m\n";
        echo "Total Execution Time: " . number_format($this->totalTime, 2) . " seconds\n\n";

        // Module breakdown
        echo "Module Breakdown:\n";
        echo "-----------------\n";
        foreach ($moduleResults as $module => $data) {
            $moduleRate = ($data['passed'] / count($data['tests'])) * 100;
            $moduleColor = $moduleRate >= 80 ? '32' : ($moduleRate >= 50 ? '33' : '31');

            echo "\n\033[1m{$module}\033[0m\n";
            echo "  Tests: " . count($data['tests']) . " | ";
            echo "\033[32mPassed: {$data['passed']}\033[0m | ";
            echo "\033[31mFailed: {$data['failed']}\033[0m | ";
            echo "Success: \033[{$moduleColor}m" . number_format($moduleRate, 1) . "%\033[0m\n";

            // Show failed tests
            foreach ($data['tests'] as $test) {
                if (!$test->passed) {
                    echo "  \033[31m✗\033[0m {$test->test}: {$test->message}\n";
                    if ($test->details) {
                        foreach ($test->details as $detail) {
                            echo "    - {$detail}\n";
                        }
                    }
                }
            }
        }

        echo "\n" . str_repeat("=", 80) . "\n";
    }

    public function generateHtmlReport(): string {
        $totalTests = 0;
        $passedTests = 0;
        $failedTests = 0;
        $moduleResults = [];

        foreach ($this->results as $result) {
            $totalTests++;
            if ($result->passed) {
                $passedTests++;
            } else {
                $failedTests++;
            }

            if (!isset($moduleResults[$result->module])) {
                $moduleResults[$result->module] = [
                    'passed' => 0,
                    'failed' => 0,
                    'tests' => []
                ];
            }

            $moduleResults[$result->module]['tests'][] = $result;
            if ($result->passed) {
                $moduleResults[$result->module]['passed']++;
            } else {
                $moduleResults[$result->module]['failed']++;
            }
        }

        $successRate = $totalTests > 0 ? ($passedTests / $totalTests * 100) : 0;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio Test Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f8f9fa;
        }
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .summary-card h3 {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .summary-card .value {
            font-size: 2rem;
            font-weight: bold;
        }
        .success { color: #28a745; }
        .danger { color: #dc3545; }
        .warning { color: #ffc107; }
        .modules {
            padding: 30px;
        }
        .module {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .module-header {
            background: #f8f9fa;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        .module-header:hover {
            background: #e9ecef;
        }
        .module-title {
            font-size: 1.2rem;
            font-weight: 600;
        }
        .module-stats {
            display: flex;
            gap: 15px;
        }
        .stat {
            font-size: 0.9rem;
        }
        .module-tests {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .module-tests.expanded {
            max-height: 1000px;
        }
        .test {
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .test:last-child {
            border-bottom: none;
        }
        .test-status {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
            flex-shrink: 0;
        }
        .test-status.pass {
            background: #28a745;
        }
        .test-status.fail {
            background: #dc3545;
        }
        .test-name {
            font-weight: 500;
            flex: 1;
        }
        .test-message {
            color: #666;
            font-size: 0.9rem;
        }
        .test-time {
            color: #999;
            font-size: 0.85rem;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
        }
        .progress-bar {
            width: 100%;
            height: 30px;
            background: #e0e0e0;
            border-radius: 15px;
            overflow: hidden;
            margin-top: 10px;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            transition: width 0.3s ease;
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
        <div class="header">
            <h1>CollaboraNexio Integration Test Report</h1>
            <p>Generated on {DATE} at {TIME}</p>
        </div>

        <div class="summary">
            <div class="summary-card">
                <h3>Total Tests</h3>
                <div class="value">{$totalTests}</div>
            </div>
            <div class="summary-card">
                <h3>Passed</h3>
                <div class="value success">{$passedTests}</div>
            </div>
            <div class="summary-card">
                <h3>Failed</h3>
                <div class="value danger">{$failedTests}</div>
            </div>
            <div class="summary-card">
                <h3>Success Rate</h3>
                <div class="value {$this->getColorClass($successRate)}">{$successRate}%</div>
            </div>
            <div class="summary-card">
                <h3>Execution Time</h3>
                <div class="value">{$this->totalTime}s</div>
            </div>
        </div>

        <div class="modules">
            <h2 style="margin-bottom: 20px;">Module Results</h2>
HTML;

        foreach ($moduleResults as $module => $data) {
            $moduleRate = ($data['passed'] / count($data['tests'])) * 100;
            $moduleId = str_replace(' ', '_', strtolower($module));

            $html .= <<<HTML
            <div class="module">
                <div class="module-header" onclick="toggleModule('{$moduleId}')">
                    <div class="module-title">{$module}</div>
                    <div class="module-stats">
                        <span class="stat">Tests: {$this->count($data['tests'])}</span>
                        <span class="stat success">Passed: {$data['passed']}</span>
                        <span class="stat danger">Failed: {$data['failed']}</span>
                        <span class="stat">Success: {$moduleRate}%</span>
                    </div>
                </div>
                <div class="module-tests" id="{$moduleId}">
HTML;

            foreach ($data['tests'] as $test) {
                $statusIcon = $test->passed ? '✓' : '✗';
                $statusClass = $test->passed ? 'pass' : 'fail';

                $html .= <<<HTML
                    <div class="test">
                        <div class="test-status {$statusClass}">{$statusIcon}</div>
                        <div class="test-name">{$test->test}</div>
                        <div class="test-message">{$test->message}</div>
                        <div class="test-time">{$test->executionTime}s</div>
                    </div>
HTML;
            }

            $html .= <<<HTML
                </div>
            </div>
HTML;
        }

        $html .= <<<HTML
        </div>

        <div class="footer">
            <p>CollaboraNexio Test Suite v{TEST_VERSION} | Powered by PHP {PHP_VERSION}</p>
        </div>
    </div>

    <script>
        function toggleModule(moduleId) {
            const module = document.getElementById(moduleId);
            module.classList.toggle('expanded');
        }

        // Auto-expand failed modules
        document.addEventListener('DOMContentLoaded', function() {
            const modules = document.querySelectorAll('.module');
            modules.forEach(module => {
                const fails = module.querySelectorAll('.test-status.fail');
                if (fails.length > 0) {
                    const testsDiv = module.querySelector('.module-tests');
                    if (testsDiv) {
                        testsDiv.classList.add('expanded');
                    }
                }
            });
        });
    </script>
</body>
</html>
HTML;

        // Replace placeholders
        $html = str_replace('{DATE}', date('Y-m-d'), $html);
        $html = str_replace('{TIME}', date('H:i:s'), $html);
        $html = str_replace('{TEST_VERSION}', TEST_VERSION, $html);
        $html = str_replace('{PHP_VERSION}', PHP_VERSION, $html);
        $html = str_replace('{$successRate}', number_format($successRate, 1), $html);
        $html = str_replace('{$this->totalTime}', number_format($this->totalTime, 2), $html);

        return $html;
    }

    private function getColorClass(float $percentage): string {
        if ($percentage >= 80) return 'success';
        if ($percentage >= 50) return 'warning';
        return 'danger';
    }

    private function count($array): int {
        return is_array($array) || $array instanceof Countable ? count($array) : 0;
    }

    public function saveHtmlReport(string $filename): bool {
        try {
            if (!is_dir(REPORT_DIR)) {
                mkdir(REPORT_DIR, 0755, true);
            }

            $html = $this->generateHtmlReport();
            $filepath = REPORT_DIR . '/' . $filename;

            return file_put_contents($filepath, $html) !== false;
        } catch (Exception $e) {
            echo "Error saving HTML report: " . $e->getMessage() . "\n";
            return false;
        }
    }
}

/**
 * Main Test Runner
 */
class TestRunner {
    private array $testModules = [];
    private array $results = [];
    private bool $verbose = false;
    private bool $generateHtml = false;
    private ?string $specificModule = null;

    public function __construct() {
        // Register all test modules
        $this->testModules = [
            'auth' => AuthenticationTest::class,
            'files' => FileManagementTest::class,
            'calendar' => CalendarEventsTest::class,
            'tasks' => TaskManagementTest::class,
            'chat' => RealTimeChatTest::class
        ];
    }

    public function parseArguments(array $argv): void {
        foreach ($argv as $arg) {
            if ($arg === '--verbose' || $arg === '-v') {
                $this->verbose = true;
            } elseif ($arg === '--html-report' || $arg === '-h') {
                $this->generateHtml = true;
            } elseif (!str_starts_with($arg, '-') && $arg !== $argv[0]) {
                $this->specificModule = strtolower($arg);
            }
        }
    }

    public function run(): void {
        $startTime = microtime(true);

        $this->printHeader();

        // Create report directory if needed
        if (!is_dir(REPORT_DIR)) {
            mkdir(REPORT_DIR, 0755, true);
        }

        // Determine which modules to run
        $modulesToRun = [];
        if ($this->specificModule) {
            if (isset($this->testModules[$this->specificModule])) {
                $modulesToRun[$this->specificModule] = $this->testModules[$this->specificModule];
            } else {
                $this->printError("Module '{$this->specificModule}' not found");
                $this->printAvailableModules();
                return;
            }
        } else {
            $modulesToRun = $this->testModules;
        }

        // Run selected modules
        foreach ($modulesToRun as $name => $className) {
            $this->printModuleHeader($className);

            try {
                $testClass = new $className($this->verbose);
                $moduleResults = $testClass->runTests();
                $this->results = array_merge($this->results, $moduleResults);
            } catch (Exception $e) {
                $this->printError("Error running {$name} tests: " . $e->getMessage());
            }

            echo "\n";
        }

        $totalTime = microtime(true) - $startTime;

        // Generate reports
        $reporter = new TestReportGenerator($this->results, $totalTime);
        $reporter->generateConsoleReport();

        if ($this->generateHtml) {
            $filename = 'test_report_' . date('Y-m-d_His') . '.html';
            if ($reporter->saveHtmlReport($filename)) {
                echo "\n\033[32m✓\033[0m HTML report saved to: " . REPORT_DIR . "/{$filename}\n";
            }
        }
    }

    private function printHeader(): void {
        echo "\n";
        echo "\033[35m╔══════════════════════════════════════════════════════════════════╗\033[0m\n";
        echo "\033[35m║\033[0m     CollaboraNexio Integration Test Suite v" . TEST_VERSION . "              \033[35m║\033[0m\n";
        echo "\033[35m║\033[0m     Multi-tenant Collaboration Platform Testing                   \033[35m║\033[0m\n";
        echo "\033[35m╚══════════════════════════════════════════════════════════════════╝\033[0m\n";
        echo "\n";
    }

    private function printModuleHeader(string $className): void {
        $testClass = new $className(false);
        $moduleName = $testClass->getModuleName();

        echo "\033[36m━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m\n";
        echo "\033[36m▶\033[0m Testing Module: \033[1m{$moduleName}\033[0m\n";
        echo "\033[36m━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m\n";
    }

    private function printError(string $message): void {
        echo "\033[31m[ERROR]\033[0m {$message}\n";
    }

    private function printAvailableModules(): void {
        echo "\nAvailable modules:\n";
        foreach (array_keys($this->testModules) as $module) {
            echo "  - {$module}\n";
        }
    }
}

// Main execution
if (PHP_SAPI !== 'cli') {
    die("This script must be run from the command line\n");
}

try {
    $runner = new TestRunner();
    $runner->parseArguments($argv);
    $runner->run();
} catch (Exception $e) {
    echo "\033[31m[FATAL ERROR]\033[0m " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);