<?php
/**
 * Test Suite for Login System
 * Verifies the authentication flow after security fixes
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';

class LoginTest {
    private $pdo;
    private $testEmail = 'test@collabora.com';
    private $testPassword = 'Test@2024!';
    private $testTenantId = 1;

    public function __construct() {
        $db = Database::getInstance();
        $this->pdo = $db->getConnection();
    }

    public function runAllTests() {
        echo "=== CollaboraNexio Login System Test Suite ===\n\n";

        $this->setupTestData();

        $tests = [
            'testLoginEndpoint',
            'testSuccessfulLogin',
            'testFailedLogin',
            'testMissingCredentials',
            'testSessionCreation',
            'testLogout',
            'testAuthCheck',
            'testNoURLParameters'
        ];

        $passed = 0;
        $failed = 0;

        foreach ($tests as $test) {
            echo "Running $test... ";
            try {
                $this->$test();
                echo "✓ PASSED\n";
                $passed++;
            } catch (Exception $e) {
                echo "✗ FAILED: " . $e->getMessage() . "\n";
                $failed++;
            }
        }

        echo "\n=== Test Results ===\n";
        echo "Passed: $passed\n";
        echo "Failed: $failed\n";
        echo "Total: " . ($passed + $failed) . "\n";

        $this->cleanupTestData();
    }

    private function setupTestData() {
        // Create test tenant if not exists
        $stmt = $this->pdo->prepare("SELECT id FROM tenants WHERE code = 'TEST'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $stmt = $this->pdo->prepare("
                INSERT INTO tenants (name, code, domain, settings)
                VALUES ('Test Tenant', 'TEST', 'test.collabora.com', '{}')
            ");
            $stmt->execute();
            $this->testTenantId = $this->pdo->lastInsertId();
        }

        // Create test user
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE email = ?");
        $stmt->execute([$this->testEmail]);

        $stmt = $this->pdo->prepare("
            INSERT INTO users (tenant_id, email, password_hash, name, role, is_active)
            VALUES (?, ?, ?, 'Test User', 'user', 1)
        ");
        $stmt->execute([
            $this->testTenantId,
            $this->testEmail,
            password_hash($this->testPassword, PASSWORD_DEFAULT)
        ]);
    }

    private function cleanupTestData() {
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE email = ?");
        $stmt->execute([$this->testEmail]);
    }

    private function testLoginEndpoint() {
        $url = 'http://localhost/CollaboraNexio/api/auth.php?action=login';

        // Test that endpoint accepts POST
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'email' => '',
            'password' => ''
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Should return 400 for empty credentials, not 403
        if ($httpCode === 403) {
            throw new Exception("Endpoint returns 403 Forbidden - security headers issue");
        }

        // Verify response is JSON
        $data = json_decode($response, true);
        if ($data === null) {
            throw new Exception("Response is not valid JSON: " . substr($response, 0, 100));
        }
    }

    private function testSuccessfulLogin() {
        $ch = curl_init('http://localhost/CollaboraNexio/api/auth.php?action=login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'email' => $this->testEmail,
            'password' => $this->testPassword
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Expected 200, got $httpCode");
        }

        $data = json_decode($response, true);
        if (!$data['success']) {
            throw new Exception("Login failed: " . $data['message']);
        }

        if (!isset($data['user']['id'])) {
            throw new Exception("User data not returned");
        }
    }

    private function testFailedLogin() {
        $ch = curl_init('http://localhost/CollaboraNexio/api/auth.php?action=login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'email' => $this->testEmail,
            'password' => 'WrongPassword123'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 401) {
            throw new Exception("Expected 401, got $httpCode");
        }

        $data = json_decode($response, true);
        if ($data['success'] !== false) {
            throw new Exception("Login should have failed");
        }
    }

    private function testMissingCredentials() {
        $ch = curl_init('http://localhost/CollaboraNexio/api/auth.php?action=login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'email' => '',
            'password' => ''
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 400) {
            throw new Exception("Expected 400, got $httpCode");
        }

        $data = json_decode($response, true);
        if ($data['success'] !== false) {
            throw new Exception("Should reject empty credentials");
        }
    }

    private function testSessionCreation() {
        // Login first
        $ch = curl_init('http://localhost/CollaboraNexio/api/auth.php?action=login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'email' => $this->testEmail,
            'password' => $this->testPassword
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');

        curl_exec($ch);
        curl_close($ch);

        // Check session
        $ch = curl_init('http://localhost/CollaboraNexio/api/auth.php?action=check');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!$data['authenticated']) {
            throw new Exception("Session not created after login");
        }
    }

    private function testLogout() {
        // Logout
        $ch = curl_init('http://localhost/CollaboraNexio/api/auth.php?action=logout');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
        curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!$data['success']) {
            throw new Exception("Logout failed");
        }

        // Verify logged out
        $ch = curl_init('http://localhost/CollaboraNexio/api/auth.php?action=check');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($data['authenticated']) {
            throw new Exception("Still authenticated after logout");
        }
    }

    private function testAuthCheck() {
        $ch = curl_init('http://localhost/CollaboraNexio/api/auth.php?action=check');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Expected 200, got $httpCode");
        }

        $data = json_decode($response, true);
        if (!isset($data['authenticated'])) {
            throw new Exception("Missing authenticated field");
        }
    }

    private function testNoURLParameters() {
        // Simulate form submission and verify no data in URL
        $loginUrl = 'http://localhost/CollaboraNexio/';

        // Get the login page
        $ch = curl_init($loginUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        // Verify URL doesn't contain sensitive data
        if (strpos($finalUrl, 'password=') !== false) {
            throw new Exception("Password found in URL!");
        }
        if (strpos($finalUrl, 'email=') !== false && strpos($finalUrl, '@') !== false) {
            throw new Exception("Email found in URL!");
        }

        // Verify login.js prevents form submission
        $jsFile = file_get_contents(dirname(__DIR__) . '/assets/js/login.js');
        if (strpos($jsFile, 'e.preventDefault()') === false) {
            throw new Exception("login.js missing e.preventDefault()");
        }
        if (strpos($jsFile, 'e.stopPropagation()') === false) {
            throw new Exception("login.js missing e.stopPropagation()");
        }
    }
}

// Run tests
$tester = new LoginTest();
$tester->runAllTests();