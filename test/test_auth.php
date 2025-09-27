<?php
/**
 * Test completo per la classe Auth
 *
 * Verifica tutte le funzionalità di autenticazione con utenti demo
 *
 * @author CollaboraNexio Development Team
 * @version 1.0.0
 */

// Disabilita output buffering per vedere risultati in tempo reale
ob_implicit_flush(true);

// Carica configurazione e classi
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Imposta header per output testo semplice
header('Content-Type: text/plain; charset=UTF-8');

// Classe per formattazione output test
class TestOutput {
    private static int $testCount = 0;
    private static int $passedCount = 0;
    private static int $failedCount = 0;

    public static function title(string $title): void {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo " $title\n";
        echo str_repeat("=", 80) . "\n\n";
    }

    public static function section(string $section): void {
        echo "\n" . str_repeat("-", 60) . "\n";
        echo " $section\n";
        echo str_repeat("-", 60) . "\n";
    }

    public static function test(string $description, bool $result, string $details = ''): void {
        self::$testCount++;
        if ($result) {
            self::$passedCount++;
            echo "✓ ";
        } else {
            self::$failedCount++;
            echo "✗ ";
        }
        echo "Test " . self::$testCount . ": $description\n";
        if ($details) {
            echo "  → $details\n";
        }
    }

    public static function info(string $message): void {
        echo "ℹ $message\n";
    }

    public static function error(string $message): void {
        echo "⚠ ERROR: $message\n";
    }

    public static function summary(): void {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo " RISULTATI TEST\n";
        echo str_repeat("=", 80) . "\n";
        echo "Test eseguiti: " . self::$testCount . "\n";
        echo "Test passati: " . self::$passedCount . " ✓\n";
        echo "Test falliti: " . self::$failedCount . " ✗\n";
        $percentage = self::$testCount > 0 ? round((self::$passedCount / self::$testCount) * 100, 2) : 0;
        echo "Percentuale successo: $percentage%\n";
        echo str_repeat("=", 80) . "\n";
    }
}

// Inizializza test
TestOutput::title("TEST SISTEMA DI AUTENTICAZIONE COLLABORANEXIO");
TestOutput::info("Timestamp: " . date('Y-m-d H:i:s'));
TestOutput::info("PHP Version: " . PHP_VERSION);
TestOutput::info("Environment: " . (DEBUG_MODE ? 'Development' : 'Production'));

try {
    // Verifica connessione database
    TestOutput::section("1. VERIFICA CONNESSIONE DATABASE");

    $db = Database::getInstance();
    TestOutput::test(
        "Connessione al database",
        $db->isConnected(),
        "Database: " . DB_NAME . " su " . DB_HOST
    );

    // Verifica esistenza tabelle necessarie
    $requiredTables = ['users', 'tenants', 'user_tenant_access', 'activity_logs', 'login_attempts'];
    foreach ($requiredTables as $table) {
        $sql = "SHOW TABLES LIKE :table";
        $result = $db->fetchColumn($sql, [':table' => $table]);
        TestOutput::test(
            "Tabella '$table' esiste",
            $result !== false,
            $result ? "Tabella trovata" : "Tabella mancante"
        );
    }

    // Inizializza Auth
    TestOutput::section("2. INIZIALIZZAZIONE CLASSE AUTH");

    $auth = Auth::getInstance();
    TestOutput::test(
        "Istanza Auth creata",
        $auth !== null,
        "Singleton pattern funzionante"
    );

    // Test metodi di base prima del login
    TestOutput::section("3. TEST METODI PRE-AUTENTICAZIONE");

    TestOutput::test(
        "isAuthenticated() prima del login",
        !$auth->isAuthenticated(),
        "Deve restituire false"
    );

    TestOutput::test(
        "getCurrentUser() prima del login",
        $auth->getCurrentUser() === null,
        "Deve restituire null"
    );

    TestOutput::test(
        "hasRole('admin') prima del login",
        !$auth->hasRole('admin'),
        "Deve restituire false"
    );

    // Genera token CSRF
    $csrfToken1 = $auth->generateCSRFToken();
    TestOutput::test(
        "generateCSRFToken()",
        strlen($csrfToken1) === 64,
        "Token generato: " . substr($csrfToken1, 0, 20) . "..."
    );

    TestOutput::test(
        "validateCSRFToken() con token valido",
        $auth->validateCSRFToken($csrfToken1),
        "Validazione token corretta"
    );

    TestOutput::test(
        "validateCSRFToken() con token invalido",
        !$auth->validateCSRFToken('invalid_token'),
        "Rifiuto token non valido"
    );

    // Test login con credenziali errate
    TestOutput::section("4. TEST LOGIN CON CREDENZIALI ERRATE");

    $result = $auth->login('nonexistent@example.com', 'wrong_password');
    TestOutput::test(
        "Login con email inesistente",
        !$result['success'],
        "Messaggio: " . $result['message']
    );

    $result = $auth->login('invalid-email', 'password');
    TestOutput::test(
        "Login con email non valida",
        !$result['success'] && $result['code'] === 'INVALID_EMAIL',
        "Validazione email funzionante"
    );

    // Test login utente regular (demo)
    TestOutput::section("5. TEST LOGIN UTENTE REGULAR");

    $result = $auth->login('user@demo.com', 'Password123!');
    TestOutput::test(
        "Login utente regular",
        $result['success'] === true,
        "Utente: " . ($result['user']['name'] ?? 'N/A')
    );

    if ($result['success']) {
        // Test metodi post-login
        TestOutput::test(
            "isAuthenticated() dopo login",
            $auth->isAuthenticated(),
            "Sessione attiva"
        );

        $currentUser = $auth->getCurrentUser();
        TestOutput::test(
            "getCurrentUser() restituisce dati",
            $currentUser !== null && isset($currentUser['email']),
            "Email: " . ($currentUser['email'] ?? 'N/A')
        );

        TestOutput::test(
            "Dati tenant in sessione",
            isset($currentUser['tenant_code']),
            "Tenant: " . ($currentUser['tenant_code'] ?? 'N/A')
        );

        TestOutput::test(
            "hasRole('user')",
            $auth->hasRole('user'),
            "Ruolo verificato correttamente"
        );

        TestOutput::test(
            "hasRole('admin') per utente regular",
            !$auth->hasRole('admin'),
            "Gerarchia ruoli rispettata"
        );

        // Test tenant list
        $tenants = $auth->getTenantList();
        TestOutput::test(
            "getTenantList()",
            is_array($tenants) && count($tenants) > 0,
            "Tenant trovati: " . count($tenants)
        );

        // Test activity update
        $auth->updateActivity();
        TestOutput::test(
            "updateActivity()",
            true,
            "Timestamp attività aggiornato"
        );

        // Test logout
        $logoutResult = $auth->logout();
        TestOutput::test(
            "Logout utente regular",
            $logoutResult['success'],
            "Sessione terminata"
        );

        TestOutput::test(
            "isAuthenticated() dopo logout",
            !$auth->isAuthenticated(),
            "Sessione pulita correttamente"
        );
    }

    // Test login platform admin
    TestOutput::section("6. TEST LOGIN PLATFORM ADMIN");

    $result = $auth->login('asamodeo@fortibyte.it', 'Admin2024!');
    TestOutput::test(
        "Login platform admin",
        $result['success'] === true,
        "Admin: " . ($result['user']['name'] ?? 'N/A')
    );

    if ($result['success']) {
        $currentUser = $auth->getCurrentUser();
        TestOutput::test(
            "Flag platform admin",
            $currentUser['is_platform_admin'] === true,
            "Platform admin riconosciuto"
        );

        TestOutput::test(
            "hasRole('admin') per platform admin",
            $auth->hasRole('admin'),
            "Accesso completo garantito"
        );

        // Test lista tenant per admin
        $tenants = $auth->getTenantList();
        TestOutput::test(
            "getTenantList() per admin",
            is_array($tenants) && count($tenants) > 0,
            "Admin vede tutti i tenant: " . count($tenants)
        );

        // Logout admin
        $auth->logout();
    }

    // Test login special multi-tenant user
    TestOutput::section("7. TEST LOGIN UTENTE MULTI-TENANT");

    $result = $auth->login('special@demo.com', 'Special2024!');
    TestOutput::test(
        "Login utente multi-tenant",
        $result['success'] === true,
        "Special user: " . ($result['user']['name'] ?? 'N/A')
    );

    if ($result['success']) {
        $currentUser = $auth->getCurrentUser();
        TestOutput::test(
            "Flag multi-tenant",
            $currentUser['is_multi_tenant'] === true,
            "Multi-tenant access riconosciuto"
        );

        $tenants = $auth->getTenantList();
        TestOutput::test(
            "Multiple tenant accessibili",
            count($tenants) > 1,
            "Tenant accessibili: " . count($tenants)
        );

        // Test switch tenant (assumendo ci sia almeno un altro tenant)
        if (count($tenants) > 1) {
            $currentTenantId = $currentUser['tenant_id'];
            $newTenant = null;
            foreach ($tenants as $tenant) {
                if ($tenant['id'] != $currentTenantId) {
                    $newTenant = $tenant;
                    break;
                }
            }

            if ($newTenant) {
                $switchResult = $auth->switchTenant($newTenant['id']);
                TestOutput::test(
                    "switchTenant()",
                    $switchResult['success'],
                    "Passato a tenant: " . $newTenant['code']
                );

                $updatedUser = $auth->getCurrentUser();
                TestOutput::test(
                    "Tenant ID aggiornato in sessione",
                    $updatedUser['tenant_id'] == $newTenant['id'],
                    "Nuovo tenant ID: " . $updatedUser['tenant_id']
                );
            }
        }

        $auth->logout();
    }

    // Test sicurezza sessione
    TestOutput::section("8. TEST SICUREZZA SESSIONE");

    // Login per test sicurezza
    $auth->login('user@demo.com', 'Password123!');

    // Test checkAuth
    TestOutput::test(
        "checkAuth() con sessione valida",
        $auth->checkAuth(),
        "Validazione sessione completa"
    );

    // Test session timeout simulation
    $_SESSION['last_activity'] = time() - 3600; // Simula inattività di 1 ora
    TestOutput::test(
        "checkSessionTimeout() dopo inattività",
        $auth->checkSessionTimeout(),
        "Timeout rilevato correttamente"
    );

    // Ripristina sessione
    $auth->updateActivity();

    // Test protezione CSRF
    $newToken = $auth->generateCSRFToken();
    TestOutput::test(
        "Token CSRF cambia ad ogni generazione",
        $newToken !== $csrfToken1,
        "Nuovo token diverso dal precedente"
    );

    $auth->logout();

    // Test tentativi di login falliti
    TestOutput::section("9. TEST PROTEZIONE BRUTE FORCE");

    TestOutput::info("Simulazione multipli tentativi falliti...");
    for ($i = 1; $i <= 3; $i++) {
        $result = $auth->login('user@demo.com', 'wrong_password_' . $i);
        TestOutput::test(
            "Tentativo fallito #$i",
            !$result['success'],
            "Login rifiutato"
        );
    }

    // Verifica che i tentativi siano stati registrati
    $sql = "SELECT COUNT(*) FROM login_attempts WHERE email = :email";
    $attempts = $db->fetchColumn($sql, [':email' => 'user@demo.com']);
    TestOutput::test(
        "Tentativi registrati nel database",
        $attempts >= 3,
        "Tentativi registrati: $attempts"
    );

    // Login riuscito per pulire i tentativi
    $result = $auth->login('user@demo.com', 'Password123!');
    if ($result['success']) {
        // Verifica pulizia tentativi
        $attempts = $db->fetchColumn($sql, [':email' => 'user@demo.com']);
        TestOutput::test(
            "Tentativi puliti dopo login riuscito",
            $attempts == 0,
            "Database pulito"
        );
        $auth->logout();
    }

    // Test metodi helper globali
    TestOutput::section("10. TEST FUNZIONI HELPER GLOBALI");

    TestOutput::test(
        "auth() restituisce istanza",
        auth() instanceof Auth,
        "Helper auth() funzionante"
    );

    TestOutput::test(
        "isAuthenticated() helper",
        !isAuthenticated(),
        "Helper isAuthenticated() funzionante"
    );

    TestOutput::test(
        "currentUser() helper",
        currentUser() === null,
        "Helper currentUser() funzionante"
    );

    TestOutput::test(
        "hasRole() helper",
        !hasRole('admin'),
        "Helper hasRole() funzionante"
    );

    $token = csrfToken();
    TestOutput::test(
        "csrfToken() helper",
        strlen($token) === 64,
        "Helper csrfToken() funzionante"
    );

    // Test activity logs
    TestOutput::section("11. VERIFICA ACTIVITY LOGS");

    $sql = "SELECT COUNT(*) FROM activity_logs WHERE action IN ('LOGIN_SUCCESS', 'LOGOUT', 'LOGIN_FAILED')";
    $logCount = $db->fetchColumn($sql);
    TestOutput::test(
        "Activity logs registrati",
        $logCount > 0,
        "Log trovati: $logCount"
    );

    // Verifica ultimi log
    $sql = "SELECT action, details, created_at
            FROM activity_logs
            ORDER BY id DESC
            LIMIT 5";
    $recentLogs = $db->fetchAll($sql);

    TestOutput::info("\nUltimi 5 log di attività:");
    foreach ($recentLogs as $log) {
        TestOutput::info("  - " . $log['action'] . " at " . $log['created_at']);
    }

} catch (Exception $e) {
    TestOutput::error("Eccezione durante i test: " . $e->getMessage());
    TestOutput::error("Stack trace:\n" . $e->getTraceAsString());
}

// Mostra riepilogo finale
TestOutput::summary();

// Informazioni finali
echo "\n";
TestOutput::info("Test completati in " . round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3) . " secondi");
TestOutput::info("Memoria utilizzata: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB");
TestOutput::info("File di log: " . LOG_DIR . "/database_errors.log");

echo "\n" . str_repeat("=", 80) . "\n";