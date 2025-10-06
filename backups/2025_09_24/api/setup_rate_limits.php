<?php
/**
 * Setup script per creare la tabella rate_limits
 *
 * Esegui questo script una sola volta per creare la tabella necessaria
 * per il rate limiting del sistema di polling
 */

require_once '../config.php';
require_once '../includes/db.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Crea tabella rate_limits se non esiste
    $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tenant_id INT NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        action VARCHAR(50) NOT NULL DEFAULT 'polling',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        INDEX idx_rate_limits_user (tenant_id, user_id, action, created_at),
        INDEX idx_rate_limits_cleanup (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);

    echo json_encode([
        'success' => true,
        'message' => 'Tabella rate_limits creata con successo'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}