-- ============================================
-- Module: Audit Logs Schema Fix
-- Version: 2025-10-14
-- Author: Database Architect
-- Description: Fix for audit_logs column mismatch
--              Issue: Code was using 'details' column but schema has 'description'
--              This migration verifies schema and provides reference queries
-- ============================================

USE collaboranexio;

-- ============================================
-- VERIFY CURRENT SCHEMA
-- ============================================
SELECT 'Verifying audit_logs table structure...' as status;

-- Show current table structure
DESCRIBE audit_logs;

-- ============================================
-- SCHEMA DOCUMENTATION
-- ============================================
/*
CORRECT AUDIT_LOGS SCHEMA (from database/06_audit_logs.sql):
=============================================================

audit_logs:
  - id BIGINT UNSIGNED (PK)
  - tenant_id INT UNSIGNED (FK to tenants)
  - user_id INT UNSIGNED (FK to users, nullable)
  - action VARCHAR(50) - e.g., 'create', 'update', 'delete', 'login', 'password_change'
  - entity_type VARCHAR(50) - e.g., 'user', 'file', 'folder', 'project'
  - entity_id INT UNSIGNED (nullable)

  Change Tracking:
  - old_values JSON (nullable) - Previous state
  - new_values JSON (nullable) - New state
  - description TEXT (nullable) - Human-readable description <<<< USE THIS, NOT 'details'

  Context:
  - ip_address VARCHAR(45)
  - user_agent TEXT
  - session_id VARCHAR(128)

  Request Details:
  - request_method VARCHAR(10) - GET, POST, PUT, DELETE
  - request_url TEXT
  - request_data JSON - Sanitized request parameters
  - response_code INT

  Performance:
  - execution_time_ms INT UNSIGNED
  - memory_usage_kb INT UNSIGNED

  Metadata:
  - severity ENUM('info', 'warning', 'error', 'critical')
  - status ENUM('success', 'failed', 'pending')
  - created_at TIMESTAMP

IMPORTANT: There is NO 'details' column. Use 'description' instead!
*/

-- ============================================
-- VERIFY NO 'details' COLUMN EXISTS
-- ============================================
SELECT
    CASE
        WHEN COUNT(*) = 0 THEN 'CORRECT: No "details" column found in audit_logs'
        ELSE 'ERROR: Found "details" column - schema drift detected!'
    END as column_check
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name = 'audit_logs'
  AND column_name = 'details';

-- ============================================
-- VERIFY 'description' COLUMN EXISTS
-- ============================================
SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'CORRECT: "description" column exists in audit_logs'
        ELSE 'ERROR: Missing "description" column!'
    END as column_check,
    column_type,
    is_nullable,
    column_default
FROM information_schema.COLUMNS
WHERE table_schema = DATABASE()
  AND table_name = 'audit_logs'
  AND column_name = 'description';

-- ============================================
-- EXAMPLE CORRECT USAGE PATTERNS
-- ============================================

/*
EXAMPLE 1: Simple Audit Log (Minimal Fields)
==============================================
INSERT INTO audit_logs (
    tenant_id, user_id, action, entity_type, entity_id,
    description, ip_address, severity, status
) VALUES (
    1,
    123,
    'password_change',
    'user',
    123,
    'Password cambiata (scadenza policy 90 giorni)',
    '192.168.1.100',
    'info',
    'success'
);


EXAMPLE 2: Full Audit Log (All Fields)
========================================
INSERT INTO audit_logs (
    tenant_id, user_id, action, entity_type, entity_id,
    old_values, new_values, description,
    ip_address, user_agent, session_id,
    request_method, request_url, request_data, response_code,
    execution_time_ms, memory_usage_kb,
    severity, status, created_at
) VALUES (
    1,
    123,
    'update',
    'user',
    456,
    '{"role": "user"}',
    '{"role": "manager"}',
    'User promoted to Manager role',
    '192.168.1.100',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    'abc123xyz',
    'POST',
    'https://example.com/api/users/update',
    '{"user_id": 456, "new_role": "manager"}',
    200,
    150,
    2048,
    'info',
    'success',
    NOW()
);


EXAMPLE 3: Using SELECT to Get tenant_id
==========================================
INSERT INTO audit_logs (
    tenant_id, user_id, action, entity_type, entity_id,
    description, ip_address, severity, status, created_at
)
SELECT
    u.tenant_id,
    u.id,
    'password_change',
    'user',
    u.id,
    'Password cambiata (scadenza)',
    '192.168.1.100',
    'info',
    'success',
    NOW()
FROM users u
WHERE u.id = 123;


EXAMPLE 4: Using AuditLogger PHP Class (Recommended)
======================================================
PHP Code:
---------
require_once 'includes/audit_logger.php';

$logger = new AuditLogger();

// Simple logging
$logger->logSimple(
    AuditLogger::ACTION_PASSWORD_CHANGE,
    AuditLogger::ENTITY_USER,
    $userId,
    'Password cambiata (scadenza policy 90 giorni)',
    AuditLogger::SEVERITY_INFO,
    AuditLogger::STATUS_SUCCESS
);

// Full logging
$logger->log([
    'action' => AuditLogger::ACTION_UPDATE,
    'entity_type' => AuditLogger::ENTITY_USER,
    'entity_id' => $userId,
    'old_values' => ['role' => 'user'],
    'new_values' => ['role' => 'manager'],
    'description' => 'User promoted to Manager',
    'severity' => AuditLogger::SEVERITY_INFO
]);
*/

-- ============================================
-- COMMON QUERIES FOR AUDIT LOGS
-- ============================================

-- Query 1: Get recent audit logs for a user
-- SELECT * FROM audit_logs
-- WHERE user_id = ?
-- ORDER BY created_at DESC
-- LIMIT 50;

-- Query 2: Get audit logs for specific entity
-- SELECT * FROM audit_logs
-- WHERE entity_type = 'user' AND entity_id = ?
-- ORDER BY created_at DESC;

-- Query 3: Get failed actions
-- SELECT * FROM audit_logs
-- WHERE status = 'failed'
-- ORDER BY created_at DESC;

-- Query 4: Get critical/error logs
-- SELECT * FROM audit_logs
-- WHERE severity IN ('error', 'critical')
-- ORDER BY created_at DESC;

-- Query 5: Get logs with JSON data
-- SELECT
--     id, action, entity_type,
--     description,
--     JSON_EXTRACT(old_values, '$.role') as old_role,
--     JSON_EXTRACT(new_values, '$.role') as new_role,
--     created_at
-- FROM audit_logs
-- WHERE old_values IS NOT NULL;

-- ============================================
-- INDEX VERIFICATION
-- ============================================
SELECT
    'Checking audit_logs indexes...' as status;

SELECT
    index_name,
    column_name,
    seq_in_index,
    non_unique,
    cardinality
FROM information_schema.STATISTICS
WHERE table_schema = DATABASE()
  AND table_name = 'audit_logs'
ORDER BY index_name, seq_in_index;

-- ============================================
-- FOREIGN KEY VERIFICATION
-- ============================================
SELECT
    'Checking audit_logs foreign keys...' as status;

SELECT
    constraint_name,
    column_name,
    referenced_table_name,
    referenced_column_name
FROM information_schema.KEY_COLUMN_USAGE
WHERE table_schema = DATABASE()
  AND table_name = 'audit_logs'
  AND referenced_table_name IS NOT NULL;

-- ============================================
-- FINAL VERIFICATION SUMMARY
-- ============================================
SELECT
    'Audit Logs Schema Verification Complete' as status,
    (SELECT COUNT(*) FROM audit_logs) as total_logs,
    (SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as logs_last_24h,
    (SELECT COUNT(*) FROM audit_logs WHERE severity IN ('error', 'critical')) as critical_logs,
    NOW() as executed_at;

-- ============================================
-- IMPORTANT NOTES FOR DEVELOPERS
-- ============================================
/*
1. NEVER use 'details' column - it doesn't exist!
2. ALWAYS use 'description' for human-readable text
3. Use 'old_values' and 'new_values' JSON columns for structured data
4. Use AuditLogger PHP class (includes/audit_logger.php) for consistent logging
5. All audit logs MUST include tenant_id for multi-tenant isolation
6. Sensitive data in request_data is automatically sanitized by AuditLogger
7. Use try-catch when logging to prevent audit failures from breaking app logic

FILES FIXED:
- /mnt/c/xampp/htdocs/CollaboraNexio/change_password.php (line 108-132)
  Changed: 'details' -> 'description'
  Added: Proper error handling with try-catch
  Added: IP address logging
  Added: severity and status fields

SEARCH FOR VIOLATIONS:
grep -r "details" --include="*.php" /path/to/project | grep "audit_logs"

CORRECT AUDIT LOG CHECKLIST:
✓ tenant_id (required)
✓ user_id (nullable for system actions)
✓ action (required)
✓ entity_type (required)
✓ entity_id (nullable)
✓ description (use this, not 'details')
✓ ip_address (recommended)
✓ severity (default: 'info')
✓ status (default: 'success')
✓ created_at (auto-populated)
*/
