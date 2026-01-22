-- ============================================
-- Migration 18: Ticket Attachments Support
-- Version: 2025-12-15
-- Author: Claude Code (Staff Engineer)
-- Description: Add ticket_attachments table for file attachments on ticket creation
-- ============================================

USE collaboranexio;

-- ============================================
-- PRE-FLIGHT CHECKS
-- ============================================

SELECT 'Checking dependencies...' as status;

-- Verify tickets table exists
SELECT 'Checking tickets table...' as status;
SELECT COUNT(*) as ticket_count FROM tickets WHERE deleted_at IS NULL;

-- ============================================
-- TABLE: TICKET_ATTACHMENTS
-- ============================================

-- Drop table if exists (for clean re-run)
DROP TABLE IF EXISTS ticket_attachments;

CREATE TABLE ticket_attachments (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Multi-tenant isolation',

    -- Relationships
    ticket_id INT UNSIGNED NOT NULL COMMENT 'Parent ticket',

    -- File metadata
    original_name VARCHAR(255) NOT NULL COMMENT 'Original filename as uploaded by user',
    stored_name VARCHAR(255) NOT NULL COMMENT 'Sanitized filename stored on disk',
    file_path VARCHAR(500) NOT NULL COMMENT 'Full path relative to uploads directory',
    file_size INT UNSIGNED NOT NULL COMMENT 'File size in bytes',
    mime_type VARCHAR(127) NOT NULL COMMENT 'MIME type (e.g., image/jpeg, application/pdf)',
    file_extension VARCHAR(20) NOT NULL COMMENT 'File extension without dot (e.g., pdf, jpg)',

    -- Upload tracking
    uploaded_by INT UNSIGNED NOT NULL COMMENT 'User who uploaded the file',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_ticket_attachments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_attachments_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_attachments_uploader
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_ticket_attachments_tenant_created (tenant_id, created_at),
    INDEX idx_ticket_attachments_tenant_deleted (tenant_id, deleted_at),

    -- Ticket lookup index
    INDEX idx_ticket_attachments_ticket (ticket_id, deleted_at),

    -- User uploads index
    INDEX idx_ticket_attachments_uploaded_by (uploaded_by, created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='File attachments for support tickets with multi-tenant isolation';

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Migration 18 completed successfully' as status,
       (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_attachments') as table_created,
       NOW() as executed_at;

-- Verify table structure
SELECT 'Verifying table structure...' as status;
DESCRIBE ticket_attachments;

-- Verify indexes
SELECT 'Verifying indexes...' as status;
SHOW INDEX FROM ticket_attachments;

-- Verify foreign keys
SELECT 'Verifying foreign keys...' as status;
SELECT
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'ticket_attachments'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- ============================================
-- END OF MIGRATION
-- ============================================
