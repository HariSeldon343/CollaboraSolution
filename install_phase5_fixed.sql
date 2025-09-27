-- Module: CollaboraNexio Phase 5 - External Collaboration (FIXED)
-- Version: 2025-01-23
-- Author: Database Architect
-- Description: Complete database schema for external collaboration features - Fixed for existing schema

SET FOREIGN_KEY_CHECKS = 0;

USE collabora;

-- ============================================
-- CLEANUP (Development only)
-- ============================================
DROP TABLE IF EXISTS approval_step_delegates;
DROP TABLE IF EXISTS approval_steps;
DROP TABLE IF EXISTS approval_requests;
DROP TABLE IF EXISTS approval_workflows;
DROP TABLE IF EXISTS file_comment_resolutions;
DROP TABLE IF EXISTS file_comments;
DROP TABLE IF EXISTS file_version_comparisons;
DROP TABLE IF EXISTS file_versions;
DROP TABLE IF EXISTS share_access_logs;
DROP TABLE IF EXISTS share_link_permissions;
DROP TABLE IF EXISTS share_links;
DROP TABLE IF EXISTS collaborative_editing_locks;
DROP TABLE IF EXISTS collaboration_notifications;

-- ============================================
-- SECURE FILE SHARING SYSTEM
-- ============================================

-- Share Links table - Main sharing configuration
CREATE TABLE share_links (
    -- Multi-tenancy support (REQUIRED for all tables)
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    file_id INT UNSIGNED NOT NULL,
    unique_token VARCHAR(64) NOT NULL,
    created_by INT UNSIGNED NOT NULL,

    -- Security settings
    password_hash VARCHAR(255) NULL,
    requires_authentication BOOLEAN DEFAULT FALSE,

    -- Access control
    expiration_date DATETIME NULL,
    max_downloads INT UNSIGNED NULL DEFAULT NULL,
    current_downloads INT UNSIGNED DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,

    -- Permissions
    allow_download BOOLEAN DEFAULT TRUE,
    allow_view BOOLEAN DEFAULT TRUE,
    allow_comment BOOLEAN DEFAULT FALSE,
    allow_upload_version BOOLEAN DEFAULT FALSE,

    -- Metadata
    title VARCHAR(255) NULL,
    description TEXT NULL,
    custom_message TEXT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_accessed_at DATETIME NULL,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_share_token (unique_token),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_share_tenant_active (tenant_id, is_active, expiration_date),
    INDEX idx_share_token_lookup (unique_token, is_active),
    INDEX idx_share_file (file_id, tenant_id),
    CHECK (max_downloads IS NULL OR max_downloads > 0),
    CHECK (current_downloads >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Share Access Logs - Complete tracking of share link usage
CREATE TABLE share_access_logs (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    share_link_id INT UNSIGNED NOT NULL,

    -- Access information
    access_type ENUM('view', 'download', 'comment', 'upload', 'denied') NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    referer_url TEXT NULL,

    -- Authentication tracking
    authenticated_user_id INT UNSIGNED NULL,
    authentication_method ENUM('password', 'oauth', 'link_only', 'failed') NULL,

    -- Session tracking
    session_id VARCHAR(64) NULL,
    country_code CHAR(2) NULL,
    city VARCHAR(100) NULL,

    -- Result tracking
    success BOOLEAN DEFAULT TRUE,
    failure_reason VARCHAR(255) NULL,
    bytes_transferred BIGINT UNSIGNED NULL,
    duration_ms INT UNSIGNED NULL,

    -- Audit fields
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (share_link_id) REFERENCES share_links(id) ON DELETE CASCADE,
    FOREIGN KEY (authenticated_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_access_log_share (share_link_id, accessed_at),
    INDEX idx_access_log_tenant_date (tenant_id, accessed_at),
    INDEX idx_access_log_ip (ip_address, accessed_at),
    INDEX idx_access_log_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Additional permissions for share links
CREATE TABLE share_link_permissions (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    share_link_id INT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,

    -- Permission details
    can_access BOOLEAN DEFAULT TRUE,
    notification_sent BOOLEAN DEFAULT FALSE,
    first_accessed_at DATETIME NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_share_email (share_link_id, email),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (share_link_id) REFERENCES share_links(id) ON DELETE CASCADE,
    INDEX idx_share_perm_email (email, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- VERSION CONTROL SYSTEM
-- ============================================

-- File Versions table - Automatic versioning system
CREATE TABLE file_versions (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    file_id INT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,

    -- Version metadata
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    mime_type VARCHAR(127) NOT NULL,
    file_hash VARCHAR(64) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,

    -- Author information
    created_by INT UNSIGNED NOT NULL,
    modification_type ENUM('create', 'update', 'restore', 'merge', 'auto_save') NOT NULL DEFAULT 'update',
    change_summary TEXT NULL,

    -- Version control
    parent_version_id BIGINT UNSIGNED NULL,
    is_current BOOLEAN DEFAULT FALSE,
    is_archived BOOLEAN DEFAULT FALSE,

    -- Technical metadata
    checksum_md5 CHAR(32) NULL,
    checksum_sha256 CHAR(64) NULL,
    compression_type VARCHAR(20) NULL,
    original_size BIGINT UNSIGNED NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_file_version (file_id, version_number),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (parent_version_id) REFERENCES file_versions(id) ON DELETE SET NULL,
    INDEX idx_version_tenant_file (tenant_id, file_id, version_number DESC),
    INDEX idx_version_current (file_id, is_current),
    INDEX idx_version_hash (file_hash),
    INDEX idx_version_created (created_at),
    CHECK (version_number > 0),
    CHECK (file_size >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Version comparison tracking
CREATE TABLE file_version_comparisons (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Comparison details
    version_from_id BIGINT UNSIGNED NOT NULL,
    version_to_id BIGINT UNSIGNED NOT NULL,
    compared_by INT UNSIGNED NOT NULL,

    -- Diff information
    additions_count INT UNSIGNED DEFAULT 0,
    deletions_count INT UNSIGNED DEFAULT 0,
    modifications_count INT UNSIGNED DEFAULT 0,
    diff_data JSON NULL,

    -- Audit fields
    compared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (version_from_id) REFERENCES file_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (version_to_id) REFERENCES file_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (compared_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_comparison_versions (version_from_id, version_to_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- COMMENTS AND ANNOTATIONS SYSTEM
-- ============================================

-- File Comments table - Threaded discussions with annotations
CREATE TABLE file_comments (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    file_id INT UNSIGNED NOT NULL,
    file_version_id BIGINT UNSIGNED NULL,
    parent_comment_id BIGINT UNSIGNED NULL,

    -- Comment content
    comment_text TEXT NOT NULL,
    comment_type ENUM('general', 'annotation', 'suggestion', 'question', 'approval', 'rejection') NOT NULL DEFAULT 'general',

    -- Author information
    author_id INT UNSIGNED NOT NULL,
    author_name VARCHAR(100) NULL,
    author_email VARCHAR(255) NULL,
    is_external BOOLEAN DEFAULT FALSE,

    -- Positional annotations (for images/PDFs)
    annotation_type ENUM('text', 'highlight', 'box', 'arrow', 'stamp') NULL,
    position_x DECIMAL(10,4) NULL,
    position_y DECIMAL(10,4) NULL,
    position_width DECIMAL(10,4) NULL,
    position_height DECIMAL(10,4) NULL,
    page_number INT UNSIGNED NULL,

    -- Status tracking
    status ENUM('active', 'resolved', 'archived', 'deleted') NOT NULL DEFAULT 'active',
    priority ENUM('low', 'normal', 'high', 'critical') DEFAULT 'normal',

    -- Threading
    thread_id VARCHAR(36) NULL,
    thread_position INT UNSIGNED DEFAULT 0,

    -- Mentions and notifications
    mentioned_users JSON NULL,
    requires_response BOOLEAN DEFAULT FALSE,

    -- Edit tracking
    edited_at DATETIME NULL,
    edit_count INT UNSIGNED DEFAULT 0,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    deleted_by INT UNSIGNED NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (file_version_id) REFERENCES file_versions(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_comment_id) REFERENCES file_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_comment_file (file_id, tenant_id, status),
    INDEX idx_comment_thread (thread_id, thread_position),
    INDEX idx_comment_parent (parent_comment_id),
    INDEX idx_comment_author (author_id, created_at),
    INDEX idx_comment_version (file_version_id),
    INDEX idx_comment_position (file_id, page_number, position_x, position_y),
    CHECK (position_x IS NULL OR (position_x >= 0 AND position_x <= 100)),
    CHECK (position_y IS NULL OR (position_y >= 0 AND position_y <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comment Resolution tracking
CREATE TABLE file_comment_resolutions (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    comment_id BIGINT UNSIGNED NOT NULL,
    resolved_by INT UNSIGNED NOT NULL,

    -- Resolution details
    resolution_type ENUM('fixed', 'wont_fix', 'duplicate', 'invalid', 'completed') NOT NULL,
    resolution_note TEXT NULL,
    related_version_id BIGINT UNSIGNED NULL,

    -- Audit fields
    resolved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES file_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (related_version_id) REFERENCES file_versions(id) ON DELETE SET NULL,
    UNIQUE KEY uk_comment_resolution (comment_id),
    INDEX idx_resolution_user (resolved_by, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- APPROVAL WORKFLOW SYSTEM
-- ============================================

-- Approval Workflows - Define multi-step approval processes
CREATE TABLE approval_workflows (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,

    -- Workflow configuration
    workflow_type ENUM('sequential', 'parallel', 'custom') NOT NULL DEFAULT 'sequential',
    auto_approve_on_timeout BOOLEAN DEFAULT FALSE,
    timeout_hours INT UNSIGNED NULL,

    -- Applicability
    applies_to_file_types JSON NULL,
    applies_to_folders JSON NULL,
    min_file_size BIGINT UNSIGNED NULL,
    max_file_size BIGINT UNSIGNED NULL,

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_default BOOLEAN DEFAULT FALSE,

    -- Notification settings
    notify_on_submission BOOLEAN DEFAULT TRUE,
    notify_on_approval BOOLEAN DEFAULT TRUE,
    notify_on_rejection BOOLEAN DEFAULT TRUE,
    notify_on_completion BOOLEAN DEFAULT TRUE,
    reminder_frequency_hours INT UNSIGNED DEFAULT 24,

    -- Audit fields
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_workflow_tenant_active (tenant_id, is_active),
    INDEX idx_workflow_default (tenant_id, is_default),
    CHECK (timeout_hours IS NULL OR timeout_hours > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Approval Requests - Track files through approval process
CREATE TABLE approval_requests (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    workflow_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    file_version_id BIGINT UNSIGNED NULL,

    -- Request metadata
    request_number VARCHAR(50) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',

    -- Requester information
    requested_by INT UNSIGNED NOT NULL,
    requested_for INT UNSIGNED NULL,
    department VARCHAR(100) NULL,

    -- Status tracking
    status ENUM('draft', 'pending', 'in_review', 'approved', 'rejected', 'cancelled', 'expired') NOT NULL DEFAULT 'draft',
    current_step_number INT UNSIGNED DEFAULT 1,

    -- Timing
    submitted_at DATETIME NULL,
    due_date DATETIME NULL,
    completed_at DATETIME NULL,

    -- Result tracking
    final_decision ENUM('approved', 'rejected', 'cancelled') NULL,
    final_comments TEXT NULL,

    -- Metrics
    total_duration_hours INT UNSIGNED NULL,
    approval_score DECIMAL(5,2) NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_request_number (tenant_id, request_number),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (workflow_id) REFERENCES approval_workflows(id) ON DELETE RESTRICT,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (file_version_id) REFERENCES file_versions(id) ON DELETE SET NULL,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (requested_for) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_request_workflow (workflow_id, status),
    INDEX idx_request_file (file_id, tenant_id),
    INDEX idx_request_status (tenant_id, status, due_date),
    INDEX idx_request_requester (requested_by, created_at),
    CHECK (current_step_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Approval Steps - Individual steps in approval process
CREATE TABLE approval_steps (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    approval_request_id BIGINT UNSIGNED NOT NULL,
    step_number INT UNSIGNED NOT NULL,

    -- Step configuration
    step_name VARCHAR(100) NOT NULL,
    step_type ENUM('approval', 'review', 'notification', 'conditional') NOT NULL DEFAULT 'approval',
    required_approvals INT UNSIGNED DEFAULT 1,
    received_approvals INT UNSIGNED DEFAULT 0,

    -- Assignee information
    assigned_to INT UNSIGNED NULL,
    assigned_role VARCHAR(100) NULL,
    assigned_group VARCHAR(100) NULL,

    -- Status tracking
    status ENUM('pending', 'in_progress', 'approved', 'rejected', 'skipped', 'delegated') NOT NULL DEFAULT 'pending',
    decision ENUM('approve', 'reject', 'conditionally_approve') NULL,

    -- Response details
    comments TEXT NULL,
    conditions_met JSON NULL,
    attachments JSON NULL,

    -- Timing
    assigned_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    reminder_sent_at DATETIME NULL,
    escalated_at DATETIME NULL,

    -- Delegation
    delegated_to INT UNSIGNED NULL,
    delegation_reason TEXT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_request_step (approval_request_id, step_number),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (approval_request_id) REFERENCES approval_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (delegated_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_step_request (approval_request_id, step_number),
    INDEX idx_step_assignee (assigned_to, status),
    INDEX idx_step_status (tenant_id, status, assigned_at),
    CHECK (step_number > 0),
    CHECK (required_approvals >= received_approvals)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step Delegates - Track delegation history
CREATE TABLE approval_step_delegates (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    approval_step_id BIGINT UNSIGNED NOT NULL,
    delegated_from INT UNSIGNED NOT NULL,
    delegated_to INT UNSIGNED NOT NULL,

    -- Delegation details
    delegation_reason TEXT NOT NULL,
    delegation_type ENUM('temporary', 'permanent', 'auto') NOT NULL DEFAULT 'temporary',
    auto_delegated BOOLEAN DEFAULT FALSE,

    -- Audit fields
    delegated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (approval_step_id) REFERENCES approval_steps(id) ON DELETE CASCADE,
    FOREIGN KEY (delegated_from) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (delegated_to) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_delegate_step (approval_step_id),
    INDEX idx_delegate_from (delegated_from, delegated_at),
    INDEX idx_delegate_to (delegated_to, delegated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- COLLABORATIVE EDITING LOCKS
-- ============================================

-- Collaborative Editing Locks - Prevent conflicts during editing
CREATE TABLE collaborative_editing_locks (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    file_id INT UNSIGNED NOT NULL,
    locked_by INT UNSIGNED NOT NULL,

    -- Lock details
    lock_token VARCHAR(64) NOT NULL,
    lock_type ENUM('exclusive', 'shared', 'read', 'write') NOT NULL DEFAULT 'exclusive',
    lock_scope ENUM('file', 'section', 'page') NOT NULL DEFAULT 'file',

    -- Scope details (for partial locks)
    section_id VARCHAR(100) NULL,
    page_number INT UNSIGNED NULL,
    start_position INT UNSIGNED NULL,
    end_position INT UNSIGNED NULL,

    -- Session information
    session_id VARCHAR(64) NOT NULL,
    client_info JSON NULL,

    -- Timing
    acquired_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    renewed_at DATETIME NULL,
    renewal_count INT UNSIGNED DEFAULT 0,

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    force_released BOOLEAN DEFAULT FALSE,
    release_reason VARCHAR(255) NULL,

    -- Audit fields
    released_at DATETIME NULL,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_active_lock (file_id, locked_by, is_active),
    UNIQUE KEY uk_lock_token (lock_token),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_lock_file (file_id, is_active),
    INDEX idx_lock_user (locked_by, is_active),
    INDEX idx_lock_expiry (expires_at, is_active),
    INDEX idx_lock_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- COLLABORATION NOTIFICATIONS
-- ============================================

-- Notifications for collaboration events
CREATE TABLE collaboration_notifications (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,

    -- Notification details
    type ENUM('comment', 'mention', 'approval_request', 'approval_decision', 'share', 'version_update', 'lock_released') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,

    -- Related entities
    file_id INT UNSIGNED NULL,
    comment_id BIGINT UNSIGNED NULL,
    approval_request_id BIGINT UNSIGNED NULL,
    share_link_id INT UNSIGNED NULL,

    -- Status
    is_read BOOLEAN DEFAULT FALSE,
    is_archived BOOLEAN DEFAULT FALSE,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',

    -- Actions
    action_url VARCHAR(500) NULL,
    action_label VARCHAR(100) NULL,

    -- Timing
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    expires_at DATETIME NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES file_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (approval_request_id) REFERENCES approval_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (share_link_id) REFERENCES share_links(id) ON DELETE CASCADE,
    INDEX idx_notification_user (user_id, is_read, created_at DESC),
    INDEX idx_notification_tenant (tenant_id, created_at DESC),
    INDEX idx_notification_type (type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- DEMO DATA
-- ============================================

-- Sample data only if users don't exist already
-- Check and insert sample users (matching existing schema)
INSERT IGNORE INTO users (id, tenant_id, name, email, password) VALUES
    (1, 1, 'John Doe', 'john.doe@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (2, 1, 'Jane Smith', 'jane.smith@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (3, 1, 'Bob Manager', 'bob.manager@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
    (4, 2, 'Alice Cooper', 'alice.cooper@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Check and insert sample files (matching existing schema)
INSERT IGNORE INTO files (id, tenant_id, user_id, file_name, file_path, file_size, mime_type) VALUES
    (1, 1, 1, 'project_proposal.pdf', '/storage/1/docs/proposal.pdf', 2048576, 'application/pdf'),
    (2, 1, 2, 'design_mockup.png', '/storage/1/images/mockup.png', 1536000, 'image/png'),
    (3, 1, 1, 'quarterly_report.xlsx', '/storage/1/docs/report.xlsx', 512000, 'application/vnd.ms-excel'),
    (4, 2, 4, 'contract_draft.docx', '/storage/2/docs/contract.docx', 768000, 'application/msword');

-- Sample share links with proper token generation
INSERT INTO share_links (tenant_id, file_id, unique_token, created_by, expiration_date, max_downloads, title, description) VALUES
    (1, 1, MD5(CONCAT('SHR_token1_', UNIX_TIMESTAMP())), 1, DATE_ADD(NOW(), INTERVAL 7 DAY), 10, 'Project Proposal Review', 'Please review and provide feedback'),
    (1, 2, MD5(CONCAT('SHR_token2_', UNIX_TIMESTAMP())), 2, DATE_ADD(NOW(), INTERVAL 30 DAY), NULL, 'Design Mockup Share', 'Latest design iteration for client'),
    (1, 3, MD5(CONCAT('SHR_token3_', UNIX_TIMESTAMP())), 1, NULL, 5, 'Q4 Report', 'Quarterly financial report');

-- Sample file versions
INSERT INTO file_versions (tenant_id, file_id, version_number, file_name, file_size, mime_type, file_hash, storage_path, created_by, modification_type, change_summary, is_current) VALUES
    (1, 1, 1, 'project_proposal_v1.pdf', 2000000, 'application/pdf', MD5('file1_v1'), '/versions/1/1/v1.pdf', 1, 'create', 'Initial version', FALSE),
    (1, 1, 2, 'project_proposal_v2.pdf', 2048576, 'application/pdf', MD5('file1_v2'), '/versions/1/1/v2.pdf', 2, 'update', 'Updated budget section', TRUE),
    (1, 2, 1, 'design_mockup_v1.png', 1500000, 'image/png', MD5('file2_v1'), '/versions/1/2/v1.png', 2, 'create', 'Initial mockup', FALSE),
    (1, 2, 2, 'design_mockup_v2.png', 1536000, 'image/png', MD5('file2_v2'), '/versions/1/2/v2.png', 2, 'update', 'Color scheme adjustments', TRUE);

-- Sample file comments with annotations
INSERT INTO file_comments (tenant_id, file_id, file_version_id, parent_comment_id, comment_text, comment_type, author_id, position_x, position_y, page_number, status) VALUES
    (1, 1, 2, NULL, 'The budget section needs more detail on Q3 projections', 'suggestion', 2, NULL, NULL, 3, 'active'),
    (1, 1, 2, 1, 'I agree, I will add the missing projections', 'general', 1, NULL, NULL, NULL, 'active'),
    (1, 2, 4, NULL, 'Love the new color scheme!', 'general', 3, 45.5, 22.3, NULL, 'active'),
    (1, 2, 4, NULL, 'This button should be more prominent', 'annotation', 1, 78.2, 55.8, NULL, 'active');

-- Sample approval workflows
INSERT INTO approval_workflows (tenant_id, name, description, workflow_type, timeout_hours, is_active, is_default, created_by) VALUES
    (1, 'Standard Document Approval', 'Default approval process for documents', 'sequential', 48, TRUE, TRUE, 3),
    (1, 'Fast Track Review', 'Expedited review for urgent items', 'parallel', 24, TRUE, FALSE, 3),
    (2, 'Contract Approval', 'Multi-level contract approval', 'sequential', 72, TRUE, TRUE, 4);

-- Sample approval requests
INSERT INTO approval_requests (tenant_id, workflow_id, file_id, file_version_id, request_number, title, description, requested_by, status, submitted_at, due_date) VALUES
    (1, 1, 1, 2, 'APR-2025-001', 'Project Proposal Approval', 'Final approval for Q1 project proposal', 1, 'in_review', NOW(), DATE_ADD(NOW(), INTERVAL 2 DAY)),
    (1, 2, 3, NULL, 'APR-2025-002', 'Quarterly Report Review', 'Urgent review needed for board meeting', 1, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY));

-- Sample approval steps
INSERT INTO approval_steps (tenant_id, approval_request_id, step_number, step_name, step_type, required_approvals, assigned_to, status, assigned_at) VALUES
    (1, 1, 1, 'Manager Review', 'approval', 1, 3, 'in_progress', NOW()),
    (1, 1, 2, 'Director Approval', 'approval', 1, 2, 'pending', NULL),
    (1, 2, 1, 'Finance Review', 'review', 1, 2, 'pending', NOW()),
    (1, 2, 2, 'Executive Sign-off', 'approval', 1, 3, 'pending', NULL);

-- Sample collaborative editing locks
INSERT INTO collaborative_editing_locks (tenant_id, file_id, locked_by, lock_token, lock_type, session_id, expires_at, is_active) VALUES
    (1, 1, 1, MD5(CONCAT('lock1', NOW())), 'exclusive', 'SESSION_001', DATE_ADD(NOW(), INTERVAL 30 MINUTE), TRUE),
    (1, 2, 2, MD5(CONCAT('lock2', NOW())), 'write', 'SESSION_002', DATE_ADD(NOW(), INTERVAL 15 MINUTE), TRUE);

-- Sample access logs
INSERT INTO share_access_logs (tenant_id, share_link_id, access_type, ip_address, user_agent, success) VALUES
    (1, 1, 'view', '192.168.1.100', 'Mozilla/5.0 Chrome/96.0', TRUE),
    (1, 1, 'download', '192.168.1.100', 'Mozilla/5.0 Chrome/96.0', TRUE),
    (1, 2, 'view', '10.0.0.50', 'Mozilla/5.0 Firefox/95.0', TRUE);

-- Sample notifications
INSERT INTO collaboration_notifications (tenant_id, user_id, type, title, message, file_id, approval_request_id, priority) VALUES
    (1, 2, 'approval_request', 'New Approval Request', 'You have been assigned to review: Project Proposal', 1, 1, 'high'),
    (1, 1, 'comment', 'New Comment', 'Jane Smith commented on your file', 1, NULL, 'normal'),
    (1, 3, 'mention', 'You were mentioned', 'You were mentioned in a comment on Design Mockup', 2, NULL, 'normal');

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

-- Verify table creation
SELECT 'Phase 5 External Collaboration tables created' as status,
       (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = 'collabora'
        AND table_name IN ('share_links', 'share_access_logs', 'file_versions',
                          'file_comments', 'approval_workflows', 'approval_requests',
                          'approval_steps', 'collaborative_editing_locks')) as tables_created;

-- Verify sample data
SELECT 'Sample data loaded' as status,
       (SELECT COUNT(*) FROM share_links) as share_links,
       (SELECT COUNT(*) FROM file_versions) as file_versions,
       (SELECT COUNT(*) FROM file_comments) as comments,
       (SELECT COUNT(*) FROM approval_requests) as approval_requests,
       (SELECT COUNT(*) FROM collaborative_editing_locks) as active_locks;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- SUCCESS MESSAGE
-- ============================================
SELECT '✓ CollaboraNexio Phase 5 - External Collaboration module installed successfully!' as result,
       NOW() as installation_time;