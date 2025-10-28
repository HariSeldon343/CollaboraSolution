-- ============================================
-- Module: Support Ticket System
-- Version: 2025-10-26
-- Author: Database Architect - CollaboraNexio
-- Description: Complete multi-tenant support ticket system with email notifications
-- ============================================

USE collaboranexio;

-- ============================================
-- VERIFY DEPENDENCIES
-- ============================================

-- Verify tenants table exists
SELECT 'Checking tenants table...' as status;
SELECT COUNT(*) as tenant_count FROM tenants WHERE deleted_at IS NULL;

-- Verify users table exists
SELECT 'Checking users table...' as status;
SELECT COUNT(*) as user_count FROM users WHERE deleted_at IS NULL;

-- ============================================
-- TABLE 1: TICKETS
-- ============================================

DROP TABLE IF EXISTS tickets;

CREATE TABLE tickets (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Multi-tenant isolation',

    -- Ownership
    created_by INT UNSIGNED NOT NULL COMMENT 'Ticket creator (cannot be deleted)',
    assigned_to INT UNSIGNED NULL COMMENT 'Currently assigned admin/super_admin',

    -- Core ticket fields
    subject VARCHAR(500) NOT NULL COMMENT 'Ticket subject (searchable)',
    description TEXT NOT NULL COMMENT 'Detailed problem description',
    ticket_number VARCHAR(50) NULL UNIQUE COMMENT 'Human-readable ticket ID (e.g., TICK-2025-0001)',

    -- Classification
    category ENUM('technical', 'billing', 'feature_request', 'bug_report', 'general', 'other')
        NOT NULL DEFAULT 'general' COMMENT 'Ticket category/topic',
    urgency ENUM('low', 'medium', 'high', 'critical')
        NOT NULL DEFAULT 'medium' COMMENT 'Urgency level',
    status ENUM('open', 'in_progress', 'waiting_response', 'resolved', 'closed')
        NOT NULL DEFAULT 'open' COMMENT 'Ticket workflow status',
    priority INT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Priority score (1-100, higher = more urgent)',

    -- Metadata
    attachments JSON NULL COMMENT 'Array of file IDs attached to ticket',
    tags JSON NULL COMMENT 'Tags for categorization and search',

    -- Resolution tracking
    resolved_at TIMESTAMP NULL COMMENT 'When ticket was marked resolved',
    resolved_by INT UNSIGNED NULL COMMENT 'Who marked ticket resolved',
    resolution_notes TEXT NULL COMMENT 'Summary of resolution',

    closed_at TIMESTAMP NULL COMMENT 'When ticket was closed',
    closed_by INT UNSIGNED NULL COMMENT 'Who closed ticket',

    -- Metrics
    first_response_at TIMESTAMP NULL COMMENT 'Timestamp of first admin response',
    first_response_time_minutes INT UNSIGNED NULL COMMENT 'Minutes until first response',
    resolution_time_minutes INT UNSIGNED NULL COMMENT 'Minutes from open to resolved',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_tickets_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tickets_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_tickets_assigned
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_resolver
        FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_closer
        FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_tickets_tenant_created (tenant_id, created_at),
    INDEX idx_tickets_tenant_deleted (tenant_id, deleted_at),
    INDEX idx_tickets_tenant_status (tenant_id, status, deleted_at),
    INDEX idx_tickets_tenant_urgency (tenant_id, urgency, deleted_at),
    INDEX idx_tickets_tenant_category (tenant_id, category, deleted_at),

    -- User-centric indexes
    INDEX idx_tickets_created_by (created_by, status),
    INDEX idx_tickets_assigned_to (assigned_to, status, deleted_at),

    -- Search and lookup indexes
    INDEX idx_tickets_number (ticket_number),
    INDEX idx_tickets_status (status, created_at),
    INDEX idx_tickets_priority (priority DESC, created_at),

    -- Full-text search
    FULLTEXT INDEX idx_tickets_search (subject, description)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Support tickets with multi-tenant isolation and workflow management';

-- ============================================
-- TABLE 2: TICKET_RESPONSES
-- ============================================

DROP TABLE IF EXISTS ticket_responses;

CREATE TABLE ticket_responses (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Multi-tenant isolation',

    -- Relationships
    ticket_id INT UNSIGNED NOT NULL COMMENT 'Parent ticket',
    user_id INT UNSIGNED NOT NULL COMMENT 'Response author',

    -- Response content
    response_text TEXT NOT NULL COMMENT 'Response message content',
    is_internal_note BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE = internal admin note, FALSE = visible to user',
    attachments JSON NULL COMMENT 'Array of file IDs attached to response',

    -- Edit tracking
    is_edited BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Has response been edited',
    edited_at TIMESTAMP NULL COMMENT 'Last edit timestamp',

    -- Email tracking
    email_sent BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Was email notification sent',
    email_sent_at TIMESTAMP NULL COMMENT 'When email was sent',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_ticket_responses_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_responses_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_responses_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_ticket_responses_tenant_created (tenant_id, created_at),
    INDEX idx_ticket_responses_tenant_deleted (tenant_id, deleted_at),

    -- Ticket timeline index
    INDEX idx_ticket_responses_ticket (ticket_id, deleted_at, created_at),

    -- User activity index
    INDEX idx_ticket_responses_user (user_id, created_at),

    -- Internal notes filter
    INDEX idx_ticket_responses_internal (ticket_id, is_internal_note, deleted_at),

    -- Full-text search
    FULLTEXT INDEX idx_ticket_responses_search (response_text)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Ticket responses and conversation thread with internal notes';

-- ============================================
-- TABLE 3: TICKET_ASSIGNMENTS
-- ============================================

DROP TABLE IF EXISTS ticket_assignments;

CREATE TABLE ticket_assignments (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Multi-tenant isolation',

    -- Relationships
    ticket_id INT UNSIGNED NOT NULL COMMENT 'Ticket being assigned',
    assigned_to INT UNSIGNED NOT NULL COMMENT 'Admin/super_admin assigned',
    assigned_by INT UNSIGNED NOT NULL COMMENT 'Who made the assignment',

    -- Assignment metadata
    assignment_note TEXT NULL COMMENT 'Optional note about assignment',

    -- Timestamps
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Assignment timestamp',
    unassigned_at TIMESTAMP NULL COMMENT 'When assignment was removed',
    unassigned_by INT UNSIGNED NULL COMMENT 'Who removed assignment',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',

    -- Audit fields (MANDATORY - created_at)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_ticket_assignments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_assignments_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_assignments_assigned_to
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_assignments_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_assignments_unassigned_by
        FOREIGN KEY (unassigned_by) REFERENCES users(id) ON DELETE SET NULL,

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_ticket_assignments_tenant_created (tenant_id, created_at),
    INDEX idx_ticket_assignments_tenant_deleted (tenant_id, deleted_at),

    -- Query indexes
    INDEX idx_ticket_assignments_ticket (ticket_id, deleted_at),
    INDEX idx_ticket_assignments_assigned_to (assigned_to, deleted_at, assigned_at),
    INDEX idx_ticket_assignments_active (ticket_id, assigned_to, unassigned_at, deleted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Ticket assignment history tracking who handled each ticket';

-- ============================================
-- TABLE 4: TICKET_NOTIFICATIONS
-- ============================================

DROP TABLE IF EXISTS ticket_notifications;

CREATE TABLE ticket_notifications (
    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key (large for high volume)',

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Multi-tenant isolation',

    -- Relationships
    ticket_id INT UNSIGNED NOT NULL COMMENT 'Related ticket',
    user_id INT UNSIGNED NOT NULL COMMENT 'Notification recipient',

    -- Notification details
    notification_type ENUM('ticket_created', 'ticket_assigned', 'ticket_response', 'status_changed', 'ticket_resolved', 'ticket_closed', 'urgency_changed')
        NOT NULL COMMENT 'Type of notification',

    -- Email details
    email_to VARCHAR(255) NOT NULL COMMENT 'Recipient email address',
    email_subject VARCHAR(500) NOT NULL COMMENT 'Email subject line',
    email_body TEXT NOT NULL COMMENT 'Email body content',

    -- Delivery tracking
    delivery_status ENUM('pending', 'sent', 'failed', 'bounced')
        NOT NULL DEFAULT 'pending' COMMENT 'Email delivery status',
    sent_at TIMESTAMP NULL COMMENT 'When email was sent',
    error_message TEXT NULL COMMENT 'Error details if delivery failed',

    -- Additional metadata
    trigger_data JSON NULL COMMENT 'Additional context about notification trigger',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_ticket_notifications_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_notifications_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes for multi-tenant queries (MANDATORY)
    INDEX idx_ticket_notifications_tenant_created (tenant_id, created_at),
    INDEX idx_ticket_notifications_tenant_deleted (tenant_id, deleted_at),

    -- Query indexes
    INDEX idx_ticket_notifications_ticket (ticket_id, created_at),
    INDEX idx_ticket_notifications_user (user_id, created_at),
    INDEX idx_ticket_notifications_status (delivery_status, created_at),
    INDEX idx_ticket_notifications_type (notification_type, created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Email notification audit trail for tickets';

-- ============================================
-- TABLE 5: TICKET_HISTORY
-- ============================================

DROP TABLE IF EXISTS ticket_history;

CREATE TABLE ticket_history (
    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key (large for high volume)',

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Multi-tenant isolation',

    -- Relationships
    ticket_id INT UNSIGNED NOT NULL COMMENT 'Ticket being audited',
    user_id INT UNSIGNED NULL COMMENT 'Who made change (NULL = system)',

    -- Change tracking
    action VARCHAR(100) NOT NULL COMMENT 'Action type (created, updated, status_changed, etc.)',
    field_name VARCHAR(100) NULL COMMENT 'Field changed (NULL for create/delete)',
    old_value TEXT NULL COMMENT 'Previous value',
    new_value TEXT NULL COMMENT 'New value',

    -- Additional context
    change_summary VARCHAR(500) NULL COMMENT 'Human-readable summary of change',

    -- Request tracking
    ip_address VARCHAR(45) NULL COMMENT 'User IP address',
    user_agent VARCHAR(500) NULL COMMENT 'Browser/client user agent',

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Change timestamp',

    -- Primary key
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_ticket_history_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_history_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_history_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,

    -- Indexes for multi-tenant queries
    INDEX idx_ticket_history_tenant (tenant_id, created_at),

    -- Query indexes
    INDEX idx_ticket_history_ticket (ticket_id, created_at DESC),
    INDEX idx_ticket_history_user (user_id, created_at DESC),
    INDEX idx_ticket_history_action (action, created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Complete audit trail of all ticket changes (NO soft delete - preserve history)';

-- ============================================
-- DEMO/SEED DATA
-- ============================================

-- Only insert if tables are empty
INSERT INTO tickets (tenant_id, created_by, subject, description, category, urgency, status, ticket_number, created_at)
SELECT
    1 as tenant_id,
    (SELECT id FROM users WHERE tenant_id = 1 AND role = 'user' AND deleted_at IS NULL LIMIT 1) as created_by,
    'Cannot upload PDF files' as subject,
    'When I try to upload PDF files in the File Manager, I get an error 500. Other file types work fine.' as description,
    'bug_report' as category,
    'high' as urgency,
    'open' as status,
    CONCAT('TICK-', YEAR(NOW()), '-', LPAD(1, 4, '0')) as ticket_number,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM tickets LIMIT 1)
  AND EXISTS (SELECT 1 FROM users WHERE tenant_id = 1 AND deleted_at IS NULL);

INSERT INTO tickets (tenant_id, created_by, subject, description, category, urgency, status, ticket_number, created_at)
SELECT
    1 as tenant_id,
    (SELECT id FROM users WHERE tenant_id = 1 AND role = 'user' AND deleted_at IS NULL LIMIT 1) as created_by,
    'Request for monthly billing option' as subject,
    'We currently have annual billing. Can we switch to monthly payments?' as description,
    'billing' as category,
    'low' as urgency,
    'in_progress' as status,
    CONCAT('TICK-', YEAR(NOW()), '-', LPAD(2, 4, '0')) as ticket_number,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM tickets WHERE ticket_number LIKE 'TICK-%-0002')
  AND EXISTS (SELECT 1 FROM users WHERE tenant_id = 1 AND deleted_at IS NULL);

-- Sample response
INSERT INTO ticket_responses (tenant_id, ticket_id, user_id, response_text, is_internal_note, email_sent, created_at)
SELECT
    1 as tenant_id,
    (SELECT id FROM tickets WHERE ticket_number LIKE 'TICK-%-0001' LIMIT 1) as ticket_id,
    (SELECT id FROM users WHERE tenant_id = 1 AND role IN ('admin', 'super_admin') AND deleted_at IS NULL LIMIT 1) as user_id,
    'Thank you for reporting this issue. We are investigating the PDF upload problem and will have a fix deployed soon.' as response_text,
    FALSE as is_internal_note,
    TRUE as email_sent,
    NOW() as created_at
WHERE EXISTS (SELECT 1 FROM tickets WHERE ticket_number LIKE 'TICK-%-0001')
  AND EXISTS (SELECT 1 FROM users WHERE tenant_id = 1 AND role IN ('admin', 'super_admin') AND deleted_at IS NULL);

-- ============================================
-- VERIFICATION
-- ============================================

SELECT 'Migration completed successfully' as status,
       (SELECT COUNT(*) FROM tickets) as ticket_count,
       (SELECT COUNT(*) FROM ticket_responses) as response_count,
       (SELECT COUNT(*) FROM ticket_assignments) as assignment_count,
       (SELECT COUNT(*) FROM ticket_notifications) as notification_count,
       (SELECT COUNT(*) FROM ticket_history) as history_count,
       NOW() as executed_at;

-- Verify indexes
SELECT 'Verifying indexes...' as status;
SHOW INDEX FROM tickets WHERE Key_name LIKE 'idx_%';
SHOW INDEX FROM ticket_responses WHERE Key_name LIKE 'idx_%';
SHOW INDEX FROM ticket_assignments WHERE Key_name LIKE 'idx_%';
SHOW INDEX FROM ticket_notifications WHERE Key_name LIKE 'idx_%';
SHOW INDEX FROM ticket_history WHERE Key_name LIKE 'idx_%';

-- ============================================
-- END OF MIGRATION
-- ============================================
