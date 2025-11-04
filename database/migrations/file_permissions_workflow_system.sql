-- ============================================
-- FILE PERMISSIONS AND DOCUMENT WORKFLOW SYSTEM
-- Version: 1.0.0
-- Date: 2025-10-29
-- Author: Database Architect
-- Description: Comprehensive file assignment and document approval workflow
-- ============================================

USE collaboranexio;

-- ============================================
-- TABLE 1: FILE_ASSIGNMENTS
-- Purpose: Track file/folder assignments to specific users
-- Business Rule: Only assigned users + managers + super_admins can access
-- ============================================

CREATE TABLE IF NOT EXISTS file_assignments (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation',

    -- Entity Reference (File OR Folder)
    file_id INT UNSIGNED NOT NULL COMMENT 'References files.id (can be file or folder)',
    entity_type ENUM('file', 'folder') NOT NULL DEFAULT 'file' COMMENT 'Type of entity assigned',

    -- Assignment Tracking
    assigned_to_user_id INT UNSIGNED NOT NULL COMMENT 'User receiving access',
    assigned_by_user_id INT UNSIGNED NOT NULL COMMENT 'Manager/Super Admin who made assignment',

    -- Assignment Metadata
    assignment_reason TEXT NULL COMMENT 'Why this file/folder was assigned',
    expires_at TIMESTAMP NULL COMMENT 'Optional expiration date for temporary access',

    -- Soft Delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete - revoked assignment',

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys (CASCADE for tenant isolation)
    CONSTRAINT fk_file_assignments_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_file_assignments_file
        FOREIGN KEY (file_id)
        REFERENCES files(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_file_assignments_assigned_to
        FOREIGN KEY (assigned_to_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_file_assignments_assigned_by
        FOREIGN KEY (assigned_by_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    -- Unique Constraint: One user can't have duplicate active assignments
    UNIQUE KEY uk_file_assignments_unique (file_id, assigned_to_user_id, deleted_at),

    -- Indexes for Multi-Tenant Queries (MANDATORY)
    INDEX idx_file_assignments_tenant_created (tenant_id, created_at),
    INDEX idx_file_assignments_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_file_assignments_file (file_id, deleted_at),
    INDEX idx_file_assignments_user (assigned_to_user_id, deleted_at),
    INDEX idx_file_assignments_assigner (assigned_by_user_id),
    INDEX idx_file_assignments_expires (expires_at),
    INDEX idx_file_assignments_entity (entity_type, file_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='File and folder assignments to specific users with audit trail';

-- ============================================
-- TABLE 2: WORKFLOW_ROLES
-- Purpose: Define validators and approvers per tenant
-- Business Rule: Only managers/super_admins can configure
-- ============================================

CREATE TABLE IF NOT EXISTS workflow_roles (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation',

    -- User and Role
    user_id INT UNSIGNED NOT NULL COMMENT 'User with workflow role',
    workflow_role ENUM('validator', 'approver') NOT NULL COMMENT 'Workflow permission level',

    -- Configuration Metadata
    assigned_by_user_id INT UNSIGNED NOT NULL COMMENT 'Manager/Super Admin who configured',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Can be temporarily disabled',

    -- Soft Delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete - role revoked',

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys (CASCADE for tenant isolation)
    CONSTRAINT fk_workflow_roles_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_workflow_roles_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_workflow_roles_assigned_by
        FOREIGN KEY (assigned_by_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    -- Unique Constraint: One user can't have duplicate active roles of same type
    UNIQUE KEY uk_workflow_roles_unique (tenant_id, user_id, workflow_role, deleted_at),

    -- Indexes for Multi-Tenant Queries (MANDATORY)
    INDEX idx_workflow_roles_tenant_created (tenant_id, created_at),
    INDEX idx_workflow_roles_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_workflow_roles_user (user_id, deleted_at),
    INDEX idx_workflow_roles_role (workflow_role, is_active, deleted_at),
    INDEX idx_workflow_roles_active (tenant_id, is_active, deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Validators and approvers configuration per tenant';

-- ============================================
-- TABLE 3: DOCUMENT_WORKFLOW
-- Purpose: Track current workflow state for each document
-- Business Rule: One active workflow per document
-- ============================================

CREATE TABLE IF NOT EXISTS document_workflow (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation',

    -- Document Reference
    file_id INT UNSIGNED NOT NULL COMMENT 'References files.id (documents only)',

    -- Workflow State
    current_state ENUM(
        'bozza',
        'in_validazione',
        'validato',
        'in_approvazione',
        'approvato',
        'rifiutato'
    ) NOT NULL DEFAULT 'bozza' COMMENT 'Current workflow state',

    -- User Tracking
    created_by_user_id INT UNSIGNED NOT NULL COMMENT 'Document creator',
    current_handler_user_id INT UNSIGNED NULL COMMENT 'Current validator/approver assigned',

    -- State Metadata
    submitted_at TIMESTAMP NULL COMMENT 'When submitted for validation',
    validated_at TIMESTAMP NULL COMMENT 'When validator approved',
    approved_at TIMESTAMP NULL COMMENT 'When approver approved',
    rejected_at TIMESTAMP NULL COMMENT 'When rejected',

    -- Rejection Handling
    rejection_reason TEXT NULL COMMENT 'Why document was rejected',
    rejection_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of times rejected',

    -- Soft Delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete - workflow cancelled',

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys (CASCADE for tenant isolation)
    CONSTRAINT fk_document_workflow_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_document_workflow_file
        FOREIGN KEY (file_id)
        REFERENCES files(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_document_workflow_creator
        FOREIGN KEY (created_by_user_id)
        REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_document_workflow_handler
        FOREIGN KEY (current_handler_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- Unique Constraint: One active workflow per document
    UNIQUE KEY uk_document_workflow_file (file_id, deleted_at),

    -- Indexes for Multi-Tenant Queries (MANDATORY)
    INDEX idx_document_workflow_tenant_created (tenant_id, created_at),
    INDEX idx_document_workflow_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_document_workflow_state (tenant_id, current_state, deleted_at),
    INDEX idx_document_workflow_creator (created_by_user_id, deleted_at),
    INDEX idx_document_workflow_handler (current_handler_user_id, deleted_at),
    INDEX idx_document_workflow_dates (submitted_at, validated_at, approved_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Current workflow state for documents requiring approval';

-- ============================================
-- TABLE 4: DOCUMENT_WORKFLOW_HISTORY
-- Purpose: Complete audit trail of ALL workflow transitions
-- Business Rule: Immutable - never delete transitions
-- ============================================

CREATE TABLE IF NOT EXISTS document_workflow_history (
    -- Primary Key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-Tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation',

    -- Workflow Reference
    workflow_id INT UNSIGNED NOT NULL COMMENT 'References document_workflow.id',
    file_id INT UNSIGNED NOT NULL COMMENT 'References files.id (denormalized for performance)',

    -- State Transition
    from_state ENUM(
        'bozza',
        'in_validazione',
        'validato',
        'in_approvazione',
        'approvato',
        'rifiutato'
    ) NULL COMMENT 'Previous state (NULL for creation)',

    to_state ENUM(
        'bozza',
        'in_validazione',
        'validato',
        'in_approvazione',
        'approvato',
        'rifiutato'
    ) NOT NULL COMMENT 'New state',

    -- Transition Metadata
    transition_type ENUM(
        'submit',          -- Creator submits for validation
        'validate',        -- Validator approves for next stage
        'reject_to_creator', -- Validator/Approver rejects
        'approve',         -- Approver final approval
        'recall',          -- Creator recalls from validation
        'cancel'           -- Workflow cancelled
    ) NOT NULL COMMENT 'Type of transition',

    -- User Tracking
    performed_by_user_id INT UNSIGNED NULL COMMENT 'User who made transition (NULL if user deleted)',
    user_role_at_time ENUM('creator', 'validator', 'approver', 'admin', 'super_admin') NOT NULL COMMENT 'Role of user at transition time',

    -- Rejection/Comment Data
    comment TEXT NULL COMMENT 'Comment, rejection reason, or notes',
    metadata JSON NULL COMMENT 'Additional transition metadata',

    -- Request Context (for audit)
    ip_address VARCHAR(45) NULL COMMENT 'IP address of user',
    user_agent VARCHAR(255) NULL COMMENT 'Browser user agent',

    -- NO SOFT DELETE (Immutable History)
    -- NO deleted_at COLUMN

    -- Audit Fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Transition timestamp',

    -- Primary Key
    PRIMARY KEY (id),

    -- Foreign Keys (CASCADE for tenant isolation, SET NULL for users to preserve history)
    CONSTRAINT fk_document_workflow_history_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_document_workflow_history_workflow
        FOREIGN KEY (workflow_id)
        REFERENCES document_workflow(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_document_workflow_history_file
        FOREIGN KEY (file_id)
        REFERENCES files(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_document_workflow_history_user
        FOREIGN KEY (performed_by_user_id)
        REFERENCES users(id)
        ON DELETE SET NULL,

    -- Indexes for Multi-Tenant Queries (MANDATORY)
    INDEX idx_workflow_history_tenant_created (tenant_id, created_at),
    INDEX idx_workflow_history_workflow (workflow_id, created_at),
    INDEX idx_workflow_history_file (file_id, created_at),
    INDEX idx_workflow_history_user (performed_by_user_id, created_at),
    INDEX idx_workflow_history_state (to_state, created_at),
    INDEX idx_workflow_history_transition (transition_type, created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Immutable audit trail of all document workflow transitions';

-- ============================================
-- DEMO DATA INSERTION
-- ============================================

-- Insert sample workflow roles (if tenant_id=1 exists and table is empty)
INSERT INTO workflow_roles (tenant_id, user_id, workflow_role, assigned_by_user_id, is_active)
SELECT 1, 2, 'validator', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM workflow_roles LIMIT 1)
  AND EXISTS (SELECT 1 FROM tenants WHERE id = 1)
  AND EXISTS (SELECT 1 FROM users WHERE id = 1)
  AND EXISTS (SELECT 1 FROM users WHERE id = 2);

INSERT INTO workflow_roles (tenant_id, user_id, workflow_role, assigned_by_user_id, is_active)
SELECT 1, 3, 'approver', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM workflow_roles WHERE id >= 2 LIMIT 1)
  AND EXISTS (SELECT 1 FROM tenants WHERE id = 1)
  AND EXISTS (SELECT 1 FROM users WHERE id = 1)
  AND EXISTS (SELECT 1 FROM users WHERE id = 3);

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Verify table creation
SELECT
    'Migration completed successfully' as status,
    (SELECT COUNT(*) FROM file_assignments) as file_assignments_count,
    (SELECT COUNT(*) FROM workflow_roles) as workflow_roles_count,
    (SELECT COUNT(*) FROM document_workflow) as document_workflow_count,
    (SELECT COUNT(*) FROM document_workflow_history) as workflow_history_count,
    NOW() as executed_at;

-- Verify indexes on file_assignments
SHOW INDEX FROM file_assignments WHERE Key_name LIKE 'idx_%';

-- Verify indexes on workflow_roles
SHOW INDEX FROM workflow_roles WHERE Key_name LIKE 'idx_%';

-- Verify indexes on document_workflow
SHOW INDEX FROM document_workflow WHERE Key_name LIKE 'idx_%';

-- Verify indexes on document_workflow_history
SHOW INDEX FROM document_workflow_history WHERE Key_name LIKE 'idx_%';

-- ============================================
-- MIGRATION NOTES
-- ============================================

/*
IMPORTANT CONSIDERATIONS:

1. MULTI-TENANT ISOLATION:
   - All tables have tenant_id with CASCADE foreign key
   - All queries MUST filter by tenant_id
   - Exception: super_admin role bypasses tenant filters

2. SOFT DELETE PATTERN:
   - file_assignments: Has deleted_at (revoke assignment)
   - workflow_roles: Has deleted_at (revoke validator/approver role)
   - document_workflow: Has deleted_at (cancel workflow)
   - document_workflow_history: NO deleted_at (immutable audit trail)

3. PERFORMANCE INDEXES:
   - Composite indexes: (tenant_id, created_at) for chronological listings
   - Composite indexes: (tenant_id, deleted_at) for active record filtering
   - State-specific indexes for workflow queries
   - User-specific indexes for assignment queries

4. FOREIGN KEY CASCADE RULES:
   - tenant_id: CASCADE (delete all tenant data on tenant deletion)
   - file_id: CASCADE (delete assignments/workflows on file deletion)
   - user_id: CASCADE for active assignments, SET NULL for history preservation

5. WORKFLOW STATE MACHINE:
   Valid transitions:
   - bozza → in_validazione (creator submits)
   - in_validazione → validato (validator approves)
   - in_validazione → rifiutato (validator rejects)
   - validato → in_approvazione (system auto-transition)
   - in_approvazione → approvato (approver approves)
   - in_approvazione → rifiutato (approver rejects)
   - rifiutato → bozza (creator edits and resubmits)
   - Any state → bozza (creator recalls)

6. EMAIL NOTIFICATION TRIGGERS:
   - Submit for validation: Notify validators
   - Validation approved: Notify creator + approvers
   - Validation rejected: Notify creator
   - Final approval: Notify creator
   - Final rejection: Notify creator

7. AUDIT LOGGING REQUIREMENTS:
   All operations MUST use AuditLogger:
   - Assignment creation: AuditLogger::logCreate('file_assignment', ...)
   - Assignment revocation: AuditLogger::logDelete('file_assignment', ...)
   - Workflow state change: AuditLogger::logUpdate('document_workflow', ...)
   - Role assignment: AuditLogger::logCreate('workflow_role', ...)

8. QUERY PATTERNS:
   See section below for common query examples

9. SECURITY:
   - Assignment creation: Only manager or super_admin
   - Assignment viewing: Assigned user + creator + manager + super_admin
   - Workflow configuration: Only manager or super_admin
   - Workflow transitions: Role-based (creator, validator, approver)

10. FILE EXPIRATION:
    - expires_at column in file_assignments for temporary access
    - Cron job should check expired assignments and revoke access
    - Email notifications before expiration
*/

-- ============================================
-- COMMON QUERY PATTERNS
-- ============================================

-- Pattern 1: Get all active assignments for a file (with user details)
/*
SELECT
    fa.id,
    fa.file_id,
    fa.entity_type,
    fa.assigned_to_user_id,
    u_assigned.name as assigned_to_name,
    u_assigned.email as assigned_to_email,
    fa.assigned_by_user_id,
    u_assigner.name as assigned_by_name,
    fa.assignment_reason,
    fa.expires_at,
    fa.created_at
FROM file_assignments fa
INNER JOIN users u_assigned ON fa.assigned_to_user_id = u_assigned.id
INNER JOIN users u_assigner ON fa.assigned_by_user_id = u_assigner.id
WHERE fa.tenant_id = ?
  AND fa.file_id = ?
  AND fa.deleted_at IS NULL
ORDER BY fa.created_at DESC;
*/

-- Pattern 2: Get all files assigned to a user
/*
SELECT
    f.id,
    f.name,
    f.file_path,
    f.file_type,
    f.file_size,
    fa.entity_type,
    fa.expires_at,
    fa.assignment_reason,
    fa.created_at as assigned_at
FROM file_assignments fa
INNER JOIN files f ON fa.file_id = f.id
WHERE fa.tenant_id = ?
  AND fa.assigned_to_user_id = ?
  AND fa.deleted_at IS NULL
  AND f.deleted_at IS NULL
  AND (fa.expires_at IS NULL OR fa.expires_at > NOW())
ORDER BY fa.created_at DESC;
*/

-- Pattern 3: Get all validators for a tenant
/*
SELECT
    wr.id,
    wr.user_id,
    u.name,
    u.email,
    wr.is_active,
    wr.created_at
FROM workflow_roles wr
INNER JOIN users u ON wr.user_id = u.id
WHERE wr.tenant_id = ?
  AND wr.workflow_role = 'validator'
  AND wr.deleted_at IS NULL
  AND wr.is_active = 1
  AND u.deleted_at IS NULL
ORDER BY u.name;
*/

-- Pattern 4: Get documents pending validation (for validator dashboard)
/*
SELECT
    dw.id,
    dw.file_id,
    f.name as document_name,
    f.file_path,
    dw.current_state,
    dw.submitted_at,
    dw.created_by_user_id,
    u.name as creator_name,
    u.email as creator_email,
    dw.rejection_count
FROM document_workflow dw
INNER JOIN files f ON dw.file_id = f.id
INNER JOIN users u ON dw.created_by_user_id = u.id
WHERE dw.tenant_id = ?
  AND dw.current_state = 'in_validazione'
  AND dw.deleted_at IS NULL
  AND f.deleted_at IS NULL
ORDER BY dw.submitted_at ASC;
*/

-- Pattern 5: Get complete workflow history for a document
/*
SELECT
    dwh.id,
    dwh.from_state,
    dwh.to_state,
    dwh.transition_type,
    dwh.performed_by_user_id,
    u.name as performed_by_name,
    u.email as performed_by_email,
    dwh.user_role_at_time,
    dwh.comment,
    dwh.metadata,
    dwh.ip_address,
    dwh.created_at as transition_date
FROM document_workflow_history dwh
LEFT JOIN users u ON dwh.performed_by_user_id = u.id
WHERE dwh.tenant_id = ?
  AND dwh.file_id = ?
ORDER BY dwh.created_at DESC;
*/

-- Pattern 6: Check if user can access a file (via assignment OR role)
/*
SELECT
    CASE
        WHEN ? IN ('super_admin', 'manager') THEN 1  -- Managers/Super admins bypass
        WHEN f.uploaded_by = ? THEN 1                 -- Creator always has access
        WHEN EXISTS (
            SELECT 1 FROM file_assignments fa
            WHERE fa.file_id = f.id
              AND fa.assigned_to_user_id = ?
              AND fa.tenant_id = f.tenant_id
              AND fa.deleted_at IS NULL
              AND (fa.expires_at IS NULL OR fa.expires_at > NOW())
        ) THEN 1                                       -- Assigned user has access
        ELSE 0
    END as can_access
FROM files f
WHERE f.id = ?
  AND f.tenant_id = ?
  AND f.deleted_at IS NULL;
*/

-- Pattern 7: Get workflow statistics for a tenant
/*
SELECT
    current_state,
    COUNT(*) as count,
    AVG(TIMESTAMPDIFF(DAY, created_at, COALESCE(approved_at, rejected_at, NOW()))) as avg_days
FROM document_workflow
WHERE tenant_id = ?
  AND deleted_at IS NULL
GROUP BY current_state
ORDER BY
    CASE current_state
        WHEN 'bozza' THEN 1
        WHEN 'in_validazione' THEN 2
        WHEN 'validato' THEN 3
        WHEN 'in_approvazione' THEN 4
        WHEN 'approvato' THEN 5
        WHEN 'rifiutato' THEN 6
    END;
*/

-- ============================================
-- END OF MIGRATION
-- ============================================
