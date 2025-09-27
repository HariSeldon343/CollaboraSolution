<?php
require_once 'config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS
    );
    echo "[OK] Connessione database riuscita\n";
    $stmt = $pdo->query('SELECT COUNT(*) as c FROM users');
    $count = $stmt->fetch()['c'];
    echo "[OK] Trovati $count utenti nel database\n";
} catch (Exception $e) {
    echo "[ERRORE] " . $e->getMessage() . "\n";
}
?>
