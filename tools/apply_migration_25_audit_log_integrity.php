<?php
/**
 * Apply Migration: 25_audit_log_integrity.sql
 *
 * Purpose: Add integrity_* columns/indexes to audit_logs for tamper-evident chain.
 * This helper exists because mysql CLI may not be available on Windows deployments.
 *
 * Usage (CLI):
 *   php tools/apply_migration_25_audit_log_integrity.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) === 1;
}

function indexExists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->execute([$table, $indexName]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function safeExec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // Ignore (idempotent migration): the caller will re-check final state.
    }
}

try {
    $pdo = Database::getInstance()->getConnection();

    // Columns
    $columns = [
        'integrity_algo' => "ALTER TABLE audit_logs ADD COLUMN integrity_algo VARCHAR(16) NULL COMMENT 'Integrity algorithm (e.g. hmac-sha256)' AFTER created_at",
        'integrity_key_id' => "ALTER TABLE audit_logs ADD COLUMN integrity_key_id VARCHAR(32) NULL COMMENT 'HMAC key identifier (supports rotation)' AFTER integrity_algo",
        'integrity_prev_hash' => "ALTER TABLE audit_logs ADD COLUMN integrity_prev_hash CHAR(64) NULL COMMENT 'Previous hash in tenant chain (hex sha256)' AFTER integrity_key_id",
        'integrity_hash' => "ALTER TABLE audit_logs ADD COLUMN integrity_hash CHAR(64) NULL COMMENT 'Row integrity hash (hex hmac-sha256)' AFTER integrity_prev_hash",
        'integrity_signed_at' => "ALTER TABLE audit_logs ADD COLUMN integrity_signed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When integrity hash was computed' AFTER integrity_hash",
    ];

    foreach ($columns as $col => $ddl) {
        if (!columnExists($pdo, 'audit_logs', $col)) {
            safeExec($pdo, $ddl);
        }
    }

    // Indexes
    $indexes = [
        'idx_audit_integrity_hash' => 'CREATE INDEX idx_audit_integrity_hash ON audit_logs(tenant_id, integrity_hash)',
        'idx_audit_integrity_chain' => 'CREATE INDEX idx_audit_integrity_chain ON audit_logs(tenant_id, created_at, id)',
    ];

    foreach ($indexes as $idxName => $ddl) {
        if (!indexExists($pdo, 'audit_logs', $idxName)) {
            safeExec($pdo, $ddl);
        }
    }

    // Final verification
    $missing = [];
    foreach (array_keys($columns) as $col) {
        if (!columnExists($pdo, 'audit_logs', $col)) $missing[] = $col;
    }
    foreach (array_keys($indexes) as $idxName) {
        if (!indexExists($pdo, 'audit_logs', $idxName)) $missing[] = $idxName;
    }

    if ($missing) {
        fwrite(STDERR, "Migration incomplete. Missing: " . implode(', ', $missing) . PHP_EOL);
        exit(2);
    }

    echo "Migration applied: audit_logs integrity columns/indexes are present." . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

