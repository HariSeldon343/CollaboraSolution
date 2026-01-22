-- ============================================
-- MIGRATION 22: WORK SHIFTS MANAGEMENT SYSTEM
-- Feature: Gestione Turni di Lavoro
-- Version: 1.0.0
-- Date: 2025-12-19
-- Author: Database Architect (CollaboraNexio)
-- ============================================
--
-- This migration adds a complete work shift management system:
-- 1. shift_types: Definizione tipi di turno per tenant
-- 2. work_shifts: Assegnazione turni a utenti per date specifiche
-- 3. shift_change_requests: Richieste di modifica/scambio turno
--
-- Integration: Calendar view support via date-range queries
--
-- ============================================

USE collaboranexio;

-- Pre-flight check
SELECT 'Starting Migration 22: Work Shifts Management System' AS status;
SELECT CONCAT('Executed at: ', NOW()) AS execution_time;

-- ============================================
-- STEP 1: CREATE shift_types TABLE
-- Definizione dei tipi di turno per tenant
-- (es: "Mattina 6-14", "Pomeriggio 14-22", "Notte 22-6")
-- ============================================

SELECT 'Step 1: Creating shift_types table...' AS status;

CREATE TABLE IF NOT EXISTS shift_types (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant that owns this shift type',

    -- Shift Type Definition
    name VARCHAR(100) NOT NULL COMMENT 'Display name (e.g., "Mattina 6-14")',
    code VARCHAR(50) NOT NULL COMMENT 'Short code for programmatic use (e.g., "mattina")',
    description TEXT NULL COMMENT 'Optional description of shift details',

    -- Time Configuration (stored as TIME for flexibility)
    start_time TIME NOT NULL COMMENT 'Shift start time (e.g., 06:00:00)',
    end_time TIME NOT NULL COMMENT 'Shift end time (e.g., 14:00:00)',

    -- Duration in minutes (computed field for convenience, or NULL to auto-calc)
    duration_minutes INT UNSIGNED NULL COMMENT 'Optional explicit duration in minutes',

    -- Visual Configuration
    color VARCHAR(7) NOT NULL DEFAULT '#3B82F6' COMMENT 'HEX color for calendar display (e.g., #FF5733)',
    icon VARCHAR(50) NULL COMMENT 'Optional icon identifier for UI',

    -- Ordering and Status
    sort_order INT UNSIGNED DEFAULT 0 COMMENT 'Display order in selection lists',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Can be temporarily disabled',

    -- Soft Delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL COMMENT 'User who created this shift type',

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys
    CONSTRAINT fk_shift_types_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_shift_types_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- Unique Constraints (include deleted_at for soft-delete compatibility)
    -- Allows re-using same code/name after soft delete
    UNIQUE KEY uk_shift_types_code (tenant_id, code, deleted_at),
    UNIQUE KEY uk_shift_types_name (tenant_id, name, deleted_at),

    -- Indexes for Multi-Tenant Queries (MANDATORY)
    INDEX idx_shift_types_tenant_created (tenant_id, created_at),
    INDEX idx_shift_types_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_shift_types_tenant_active (tenant_id, is_active, deleted_at),
    INDEX idx_shift_types_sort (tenant_id, sort_order)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Shift type definitions per tenant (Tipi di Turno)';

SELECT 'shift_types table created successfully' AS status;


-- ============================================
-- STEP 2: CREATE work_shifts TABLE
-- Assegnazione turni a utenti per date specifiche
-- ============================================

SELECT 'Step 2: Creating work_shifts table...' AS status;

CREATE TABLE IF NOT EXISTS work_shifts (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation',

    -- Core Assignment Fields
    shift_type_id INT UNSIGNED NOT NULL COMMENT 'FK to shift_types.id - which shift type',
    user_id INT UNSIGNED NOT NULL COMMENT 'FK to users.id - assigned worker',
    shift_date DATE NOT NULL COMMENT 'The specific date of the shift',

    -- Override times (NULL = use shift_type defaults)
    start_time_override TIME NULL COMMENT 'Override start time for this specific shift',
    end_time_override TIME NULL COMMENT 'Override end time for this specific shift',

    -- Status and Notes
    status ENUM('scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show')
        NOT NULL DEFAULT 'scheduled'
        COMMENT 'Shift status lifecycle',
    notes TEXT NULL COMMENT 'Manager/system notes for this shift',
    user_notes TEXT NULL COMMENT 'Worker notes (e.g., availability issues)',

    -- Actual work tracking (optional)
    actual_start_time DATETIME NULL COMMENT 'When worker actually started',
    actual_end_time DATETIME NULL COMMENT 'When worker actually ended',

    -- Soft Delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL COMMENT 'User who created this assignment',
    updated_by INT UNSIGNED NULL COMMENT 'User who last modified this assignment',

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys
    CONSTRAINT fk_work_shifts_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_work_shifts_shift_type
        FOREIGN KEY (shift_type_id)
        REFERENCES shift_types(id)
        ON DELETE RESTRICT,  -- Prevent deleting shift type with existing assignments

    CONSTRAINT fk_work_shifts_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_work_shifts_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_work_shifts_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- Unique constraint: one shift type per user per date (per tenant)
    -- A user cannot have the same shift type twice on the same day
    UNIQUE KEY uk_work_shifts_user_date_type (tenant_id, user_id, shift_date, shift_type_id, deleted_at),

    -- Indexes for Multi-Tenant Queries (MANDATORY)
    INDEX idx_work_shifts_tenant_created (tenant_id, created_at),
    INDEX idx_work_shifts_tenant_deleted (tenant_id, deleted_at),

    -- Calendar integration: query by date range
    INDEX idx_work_shifts_tenant_date (tenant_id, shift_date, deleted_at),
    INDEX idx_work_shifts_date_range (tenant_id, shift_date, status, deleted_at),

    -- User-specific queries
    INDEX idx_work_shifts_user_date (tenant_id, user_id, shift_date, deleted_at),
    INDEX idx_work_shifts_user_status (tenant_id, user_id, status, deleted_at),

    -- Status filtering
    INDEX idx_work_shifts_status (tenant_id, status, shift_date),

    -- Shift type filtering (for reports)
    INDEX idx_work_shifts_type_date (tenant_id, shift_type_id, shift_date)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Work shift assignments to users for specific dates (Turni Assegnati)';

SELECT 'work_shifts table created successfully' AS status;


-- ============================================
-- STEP 3: CREATE shift_change_requests TABLE
-- Richieste di modifica/scambio turno
-- ============================================

SELECT 'Step 3: Creating shift_change_requests table...' AS status;

CREATE TABLE IF NOT EXISTS shift_change_requests (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation',

    -- Core Request Fields
    work_shift_id INT UNSIGNED NOT NULL COMMENT 'FK to work_shifts.id - the shift to modify',
    requester_id INT UNSIGNED NOT NULL COMMENT 'FK to users.id - user making the request',

    -- Request Type
    request_type ENUM('change', 'swap', 'cancel') NOT NULL
        COMMENT 'change=change shift type, swap=exchange with colleague, cancel=remove shift',

    -- For CHANGE requests: new desired shift type
    new_shift_type_id INT UNSIGNED NULL COMMENT 'FK to shift_types.id - requested new shift type (for change)',

    -- For SWAP requests: target colleague
    target_user_id INT UNSIGNED NULL COMMENT 'FK to users.id - colleague to swap with (for swap)',
    target_work_shift_id INT UNSIGNED NULL COMMENT 'FK to work_shifts.id - colleague shift to receive (for swap)',

    -- Request Details
    reason TEXT NOT NULL COMMENT 'User explanation for the request',
    preferred_date DATE NULL COMMENT 'Alternative date if change involves date (optional)',

    -- Approval Workflow
    status ENUM('pending', 'approved', 'rejected', 'cancelled', 'expired')
        NOT NULL DEFAULT 'pending'
        COMMENT 'Request status',

    -- Manager Decision
    manager_id INT UNSIGNED NULL COMMENT 'FK to users.id - manager who handled the request',
    decision_at TIMESTAMP NULL COMMENT 'When the decision was made',
    manager_notes TEXT NULL COMMENT 'Manager explanation for approval/rejection',

    -- Target User Acceptance (for SWAP only)
    target_accepted TINYINT(1) NULL COMMENT 'NULL=pending, 1=accepted, 0=rejected (swap only)',
    target_accepted_at TIMESTAMP NULL COMMENT 'When target user responded',
    target_notes TEXT NULL COMMENT 'Target user notes on swap request',

    -- Soft Delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys
    CONSTRAINT fk_shift_requests_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_shift_requests_work_shift
        FOREIGN KEY (work_shift_id)
        REFERENCES work_shifts(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_shift_requests_requester
        FOREIGN KEY (requester_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_shift_requests_new_type
        FOREIGN KEY (new_shift_type_id)
        REFERENCES shift_types(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_shift_requests_target_user
        FOREIGN KEY (target_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_shift_requests_target_shift
        FOREIGN KEY (target_work_shift_id)
        REFERENCES work_shifts(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_shift_requests_manager
        FOREIGN KEY (manager_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- Indexes for Multi-Tenant Queries (MANDATORY)
    INDEX idx_shift_requests_tenant_created (tenant_id, created_at),
    INDEX idx_shift_requests_tenant_deleted (tenant_id, deleted_at),

    -- Status-based queries (managers dashboard)
    INDEX idx_shift_requests_tenant_status (tenant_id, status, deleted_at),
    INDEX idx_shift_requests_pending (tenant_id, status, created_at),

    -- User-specific queries
    INDEX idx_shift_requests_requester (tenant_id, requester_id, status, deleted_at),
    INDEX idx_shift_requests_target (tenant_id, target_user_id, status, deleted_at),

    -- Work shift lookup
    INDEX idx_shift_requests_work_shift (work_shift_id, status),

    -- Manager workload
    INDEX idx_shift_requests_manager (tenant_id, manager_id, decision_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Shift change/swap/cancel requests with approval workflow (Richieste Modifica Turno)';

SELECT 'shift_change_requests table created successfully' AS status;


-- ============================================
-- STEP 4: OPTIONAL FEATURE FLAG ON TENANTS
-- Add has_shift_management flag (BUG-156 pattern)
-- ============================================

SELECT 'Step 4: Adding has_shift_management flag to tenants...' AS status;

SET @col_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'has_shift_management'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE tenants
     ADD COLUMN has_shift_management TINYINT(1) NOT NULL DEFAULT 0
     COMMENT ''TRUE if tenant uses work shift management feature''
     AFTER has_custom_roles',
    'SELECT ''Column has_shift_management already exists'' AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for filtering tenants with shifts feature
SET @idx_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'collaboranexio'
    AND TABLE_NAME = 'tenants'
    AND INDEX_NAME = 'idx_tenants_shift_management'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE tenants ADD INDEX idx_tenants_shift_management (has_shift_management)',
    'SELECT ''Index idx_tenants_shift_management already exists'' AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT 'tenants.has_shift_management added/verified' AS status;


-- ============================================
-- VERIFICATION QUERIES
-- ============================================

SELECT '=== Migration 22 Verification ===' AS section;

-- Check shift_types table
SELECT
    'shift_types table' AS check_item,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END AS status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'shift_types';

-- Check work_shifts table
SELECT
    'work_shifts table' AS check_item,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END AS status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'work_shifts';

-- Check shift_change_requests table
SELECT
    'shift_change_requests table' AS check_item,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END AS status
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'shift_change_requests';

-- Check tenants.has_shift_management
SELECT
    'tenants.has_shift_management' AS check_item,
    CASE WHEN COUNT(*) > 0 THEN 'EXISTS' ELSE 'MISSING' END AS status
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio'
AND TABLE_NAME = 'tenants'
AND COLUMN_NAME = 'has_shift_management';

-- Column counts
SELECT 'Column counts:' AS section;

SELECT
    'shift_types columns' AS check_item,
    COUNT(*) AS count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'shift_types';

SELECT
    'work_shifts columns' AS check_item,
    COUNT(*) AS count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'work_shifts';

SELECT
    'shift_change_requests columns' AS check_item,
    COUNT(*) AS count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'shift_change_requests';

-- Check FK constraints
SELECT 'Foreign Key Constraints:' AS section;

SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME,
    DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'collaboranexio'
AND TABLE_NAME IN ('shift_types', 'work_shifts', 'shift_change_requests')
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- Check indexes
SELECT 'Indexes on shift_types:' AS section;
SHOW INDEX FROM shift_types WHERE Key_name LIKE 'idx_%' OR Key_name LIKE 'uk_%';

SELECT 'Indexes on work_shifts:' AS section;
SHOW INDEX FROM work_shifts WHERE Key_name LIKE 'idx_%' OR Key_name LIKE 'uk_%';

SELECT 'Indexes on shift_change_requests:' AS section;
SHOW INDEX FROM shift_change_requests WHERE Key_name LIKE 'idx_%';

-- Final summary
SELECT '=== Migration 22 Summary ===' AS section;

SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'shift_types') AS shift_types_exists,
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'work_shifts') AS work_shifts_exists,
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'shift_change_requests') AS shift_requests_exists,
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'collaboranexio' AND TABLE_NAME = 'tenants'
     AND COLUMN_NAME = 'has_shift_management') AS tenant_flag_exists;

SELECT 'Migration 22 completed successfully!' AS final_status;
SELECT CONCAT('Completed at: ', NOW()) AS completion_time;


-- ============================================
-- EXAMPLE QUERIES FOR CALENDAR INTEGRATION
-- (Reference only - not executed)
-- ============================================

/*
-- Query: Get shifts for a user in a date range (calendar view)
SELECT
    ws.id,
    ws.shift_date,
    COALESCE(ws.start_time_override, st.start_time) AS start_time,
    COALESCE(ws.end_time_override, st.end_time) AS end_time,
    st.name AS shift_name,
    st.color,
    ws.status,
    ws.notes
FROM work_shifts ws
INNER JOIN shift_types st ON ws.shift_type_id = st.id
WHERE ws.tenant_id = ?
  AND ws.user_id = ?
  AND ws.shift_date BETWEEN ? AND ?
  AND ws.deleted_at IS NULL
  AND ws.status != 'cancelled'
ORDER BY ws.shift_date, start_time;

-- Query: Get all shifts for a tenant in a date range (manager view)
SELECT
    ws.id,
    ws.shift_date,
    COALESCE(ws.start_time_override, st.start_time) AS start_time,
    COALESCE(ws.end_time_override, st.end_time) AS end_time,
    st.name AS shift_name,
    st.color,
    u.name AS user_name,
    ws.status
FROM work_shifts ws
INNER JOIN shift_types st ON ws.shift_type_id = st.id
INNER JOIN users u ON ws.user_id = u.id
WHERE ws.tenant_id = ?
  AND ws.shift_date BETWEEN ? AND ?
  AND ws.deleted_at IS NULL
ORDER BY ws.shift_date, start_time, u.name;

-- Query: Pending change requests for manager
SELECT
    scr.id,
    scr.request_type,
    scr.reason,
    scr.created_at,
    u_req.name AS requester_name,
    ws.shift_date,
    st_current.name AS current_shift,
    st_new.name AS requested_shift,
    u_target.name AS swap_target_name
FROM shift_change_requests scr
INNER JOIN users u_req ON scr.requester_id = u_req.id
INNER JOIN work_shifts ws ON scr.work_shift_id = ws.id
INNER JOIN shift_types st_current ON ws.shift_type_id = st_current.id
LEFT JOIN shift_types st_new ON scr.new_shift_type_id = st_new.id
LEFT JOIN users u_target ON scr.target_user_id = u_target.id
WHERE scr.tenant_id = ?
  AND scr.status = 'pending'
  AND scr.deleted_at IS NULL
ORDER BY scr.created_at ASC;
*/

