-- Module: Productivity Tools (Calendar & Task Management)
-- Version: 2025-01-22
-- Author: Database Architect
-- Description: Comprehensive schema for calendar events and task management with enterprise features

USE collabora;

-- ============================================
-- CLEANUP (Development only)
-- ============================================
DROP TABLE IF EXISTS time_entries;
DROP TABLE IF EXISTS task_watchers;
DROP TABLE IF EXISTS task_attachments;
DROP TABLE IF EXISTS task_comments;
DROP TABLE IF EXISTS task_dependencies;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS task_list_columns;
DROP TABLE IF EXISTS task_lists;
DROP TABLE IF EXISTS event_reminders;
DROP TABLE IF EXISTS event_participants;
DROP TABLE IF EXISTS event_attachments;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS recurring_patterns;
DROP TABLE IF EXISTS calendar_shares;
DROP TABLE IF EXISTS calendars;
DROP TABLE IF EXISTS custom_field_values;
DROP TABLE IF EXISTS custom_fields;

-- ============================================
-- TABLE DEFINITIONS
-- ============================================

-- Recurring patterns for both events and tasks
CREATE TABLE recurring_patterns (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- RRULE standard fields
    rrule VARCHAR(500) NOT NULL COMMENT 'RFC 5545 RRULE format',
    freq ENUM('DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY') NOT NULL,
    interval_value INT UNSIGNED DEFAULT 1,
    until_date DATETIME NULL,
    count INT UNSIGNED NULL,
    by_day VARCHAR(50) NULL COMMENT 'MO,TU,WE,TH,FR,SA,SU',
    by_month_day VARCHAR(100) NULL,
    by_month VARCHAR(50) NULL,
    by_set_pos VARCHAR(50) NULL,

    -- Exception handling
    exception_dates JSON NULL COMMENT 'Array of excluded dates',

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_recurring_tenant (tenant_id),
    CONSTRAINT chk_recur_end CHECK (
        (until_date IS NULL AND count IS NULL) OR
        (until_date IS NOT NULL AND count IS NULL) OR
        (until_date IS NULL AND count IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendar management
CREATE TABLE calendars (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    owner_id INT UNSIGNED NOT NULL,

    -- Customization
    color VARCHAR(7) DEFAULT '#0066CC' COMMENT 'Hex color code',
    icon VARCHAR(50) NULL,
    timezone VARCHAR(50) DEFAULT 'UTC',

    -- Visibility and sharing
    visibility ENUM('private', 'team', 'public') DEFAULT 'private',
    is_default BOOLEAN DEFAULT FALSE,
    is_shared BOOLEAN DEFAULT FALSE,

    -- Settings
    default_reminder_minutes INT DEFAULT 15,
    week_start_day TINYINT DEFAULT 1 COMMENT '1=Monday, 7=Sunday',
    working_hours_start TIME DEFAULT '09:00:00',
    working_hours_end TIME DEFAULT '18:00:00',

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    deleted_at TIMESTAMP NULL,

    -- Audit fields
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_calendar_tenant_owner (tenant_id, owner_id),
    INDEX idx_calendar_visibility (tenant_id, visibility, is_active),
    INDEX idx_calendar_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendar sharing permissions
CREATE TABLE calendar_shares (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    calendar_id INT UNSIGNED NOT NULL,
    shared_with_user_id INT UNSIGNED NULL,
    shared_with_team_id INT UNSIGNED NULL,

    -- Permissions
    can_view BOOLEAN DEFAULT TRUE,
    can_edit BOOLEAN DEFAULT FALSE,
    can_delete BOOLEAN DEFAULT FALSE,
    can_share BOOLEAN DEFAULT FALSE,

    -- Share settings
    hide_details BOOLEAN DEFAULT FALSE COMMENT 'Show only free/busy',

    -- Audit
    shared_by INT UNSIGNED NOT NULL,
    shared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with_team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_calendar_share (calendar_id, shared_with_user_id, shared_with_team_id),
    INDEX idx_share_tenant_user (tenant_id, shared_with_user_id),
    INDEX idx_share_tenant_team (tenant_id, shared_with_team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendar events
CREATE TABLE events (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Association
    calendar_id INT UNSIGNED NOT NULL,

    -- Event details
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    location VARCHAR(255) NULL,
    location_details JSON NULL COMMENT 'Address, coordinates, room info',

    -- Timing
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    is_all_day BOOLEAN DEFAULT FALSE,
    timezone VARCHAR(50) DEFAULT 'UTC',

    -- Recurrence
    recurring_pattern_id INT UNSIGNED NULL,
    recurrence_id VARCHAR(100) NULL COMMENT 'ID for this instance in series',
    is_recurring_exception BOOLEAN DEFAULT FALSE,

    -- Classification
    status ENUM('confirmed', 'tentative', 'cancelled') DEFAULT 'confirmed',
    visibility ENUM('public', 'private', 'confidential') DEFAULT 'public',
    busy_status ENUM('free', 'busy', 'tentative', 'oof') DEFAULT 'busy',

    -- Categories and organization
    category VARCHAR(50) NULL,
    tags JSON NULL COMMENT 'Array of tag strings',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    color VARCHAR(7) NULL COMMENT 'Override calendar color',

    -- Links
    url VARCHAR(500) NULL,
    meeting_url VARCHAR(500) NULL,
    linked_task_id INT UNSIGNED NULL,

    -- Metadata
    organizer_id INT UNSIGNED NOT NULL,
    is_private BOOLEAN DEFAULT FALSE,
    allow_comments BOOLEAN DEFAULT TRUE,

    -- Soft delete
    deleted_at TIMESTAMP NULL,
    deleted_by INT UNSIGNED NULL,

    -- Audit fields
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    FOREIGN KEY (recurring_pattern_id) REFERENCES recurring_patterns(id) ON DELETE SET NULL,
    FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_event_tenant_calendar (tenant_id, calendar_id),
    INDEX idx_event_datetime (tenant_id, start_datetime, end_datetime),
    INDEX idx_event_organizer (tenant_id, organizer_id),
    INDEX idx_event_status (tenant_id, status, deleted_at),
    INDEX idx_event_recurring (recurring_pattern_id),
    INDEX idx_event_task (linked_task_id),
    FULLTEXT idx_event_search (title, description, location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event participants
CREATE TABLE event_participants (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    external_email VARCHAR(255) NULL,

    -- Participant details
    name VARCHAR(100) NULL,
    role ENUM('organizer', 'required', 'optional', 'resource') DEFAULT 'required',

    -- RSVP
    rsvp_status ENUM('pending', 'accepted', 'declined', 'tentative', 'delegated') DEFAULT 'pending',
    rsvp_comment TEXT NULL,
    rsvp_at TIMESTAMP NULL,

    -- Notifications
    send_notifications BOOLEAN DEFAULT TRUE,
    notification_method ENUM('email', 'in_app', 'both', 'none') DEFAULT 'both',
    reminder_sent BOOLEAN DEFAULT FALSE,

    -- Delegation
    delegated_to INT UNSIGNED NULL,
    delegated_from INT UNSIGNED NULL,

    -- Audit
    invited_by INT UNSIGNED NOT NULL,
    invited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (delegated_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (delegated_from) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_event_participant (event_id, user_id, external_email),
    INDEX idx_participant_tenant_user (tenant_id, user_id),
    INDEX idx_participant_rsvp (event_id, rsvp_status),
    INDEX idx_participant_role (event_id, role),
    CONSTRAINT chk_participant_contact CHECK (user_id IS NOT NULL OR external_email IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event reminders
CREATE TABLE event_reminders (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- Reminder configuration
    reminder_type ENUM('email', 'notification', 'sms', 'popup') DEFAULT 'notification',
    minutes_before INT UNSIGNED NOT NULL DEFAULT 15,

    -- Status
    is_sent BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP NULL,
    is_snoozed BOOLEAN DEFAULT FALSE,
    snoozed_until TIMESTAMP NULL,

    -- Custom message
    custom_message TEXT NULL,

    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_event_reminder (event_id, user_id, reminder_type, minutes_before),
    INDEX idx_reminder_tenant_user (tenant_id, user_id),
    INDEX idx_reminder_pending (tenant_id, is_sent, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event attachments
CREATE TABLE event_attachments (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    event_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,

    -- Metadata
    attached_by INT UNSIGNED NOT NULL,
    attached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (attached_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_event_file (event_id, file_id),
    INDEX idx_event_attach_tenant (tenant_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task lists / Kanban boards
CREATE TABLE task_lists (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    owner_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NULL,

    -- Board configuration
    board_type ENUM('kanban', 'list', 'timeline', 'calendar') DEFAULT 'kanban',
    is_template BOOLEAN DEFAULT FALSE,
    template_category VARCHAR(50) NULL,

    -- Customization
    color VARCHAR(7) DEFAULT '#4A90E2',
    icon VARCHAR(50) NULL,
    background_image VARCHAR(500) NULL,

    -- Settings
    default_assignee_id INT UNSIGNED NULL,
    auto_archive_days INT NULL COMMENT 'Auto-archive completed tasks after N days',
    allow_subtasks BOOLEAN DEFAULT TRUE,
    require_time_tracking BOOLEAN DEFAULT FALSE,

    -- Sharing
    visibility ENUM('private', 'team', 'public') DEFAULT 'team',
    is_shared BOOLEAN DEFAULT FALSE,

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_archived BOOLEAN DEFAULT FALSE,
    archived_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,

    -- Statistics cache
    task_count INT UNSIGNED DEFAULT 0,
    completed_count INT UNSIGNED DEFAULT 0,

    -- Audit fields
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (default_assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_tasklist_tenant_owner (tenant_id, owner_id),
    INDEX idx_tasklist_project (tenant_id, project_id),
    INDEX idx_tasklist_active (tenant_id, is_active, is_archived),
    INDEX idx_tasklist_template (is_template, template_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kanban board columns
CREATE TABLE task_list_columns (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    task_list_id INT UNSIGNED NOT NULL,

    -- Column configuration
    name VARCHAR(50) NOT NULL,
    color VARCHAR(7) DEFAULT '#E0E0E0',
    position INT UNSIGNED NOT NULL DEFAULT 0,

    -- Workflow
    status_mapping VARCHAR(50) NULL COMMENT 'Maps to task status',
    is_default BOOLEAN DEFAULT FALSE,
    marks_complete BOOLEAN DEFAULT FALSE,

    -- Limits
    wip_limit INT UNSIGNED NULL COMMENT 'Work in progress limit',

    -- Auto-actions
    auto_assign_to INT UNSIGNED NULL,
    auto_move_after_hours INT NULL,
    auto_move_to_column_id INT UNSIGNED NULL,

    -- Audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (task_list_id) REFERENCES task_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (auto_assign_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (auto_move_to_column_id) REFERENCES task_list_columns(id) ON DELETE SET NULL,
    UNIQUE KEY uk_column_position (task_list_id, position),
    INDEX idx_column_tenant_list (tenant_id, task_list_id),
    INDEX idx_column_default (task_list_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks
CREATE TABLE tasks (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Association
    task_list_id INT UNSIGNED NOT NULL,
    column_id INT UNSIGNED NULL,
    parent_task_id INT UNSIGNED NULL COMMENT 'For subtasks',

    -- Core fields
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,

    -- Assignment
    assignee_id INT UNSIGNED NULL,
    reporter_id INT UNSIGNED NOT NULL,

    -- Timing
    due_date DATETIME NULL,
    start_date DATETIME NULL,
    completed_at TIMESTAMP NULL,

    -- Time tracking
    estimated_hours DECIMAL(8,2) NULL,
    actual_hours DECIMAL(8,2) DEFAULT 0,
    remaining_hours DECIMAL(8,2) NULL,
    is_billable BOOLEAN DEFAULT FALSE,
    hourly_rate DECIMAL(10,2) NULL,

    -- Priority and status
    priority ENUM('urgent', 'high', 'medium', 'low') DEFAULT 'medium',
    status ENUM('backlog', 'todo', 'in_progress', 'review', 'done', 'cancelled') DEFAULT 'todo',
    progress_percentage TINYINT UNSIGNED DEFAULT 0 CHECK (progress_percentage <= 100),

    -- Categorization
    category VARCHAR(50) NULL,
    tags JSON NULL,
    labels JSON NULL COMMENT 'Color-coded labels',

    -- Position and ordering
    position INT UNSIGNED DEFAULT 0 COMMENT 'Order within column',
    sort_order DECIMAL(20,10) DEFAULT 0 COMMENT 'Flexible ordering',

    -- Recurrence
    recurring_pattern_id INT UNSIGNED NULL,
    recurrence_parent_id INT UNSIGNED NULL,

    -- Links
    linked_event_id INT UNSIGNED NULL,
    external_id VARCHAR(100) NULL COMMENT 'ID from external system',
    external_url VARCHAR(500) NULL,

    -- Flags
    is_milestone BOOLEAN DEFAULT FALSE,
    is_private BOOLEAN DEFAULT FALSE,
    is_flagged BOOLEAN DEFAULT FALSE,
    is_blocked BOOLEAN DEFAULT FALSE,
    block_reason TEXT NULL,

    -- Soft delete
    deleted_at TIMESTAMP NULL,
    deleted_by INT UNSIGNED NULL,

    -- Audit fields
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (task_list_id) REFERENCES task_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (column_id) REFERENCES task_list_columns(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (assignee_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (recurring_pattern_id) REFERENCES recurring_patterns(id) ON DELETE SET NULL,
    FOREIGN KEY (recurrence_parent_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (linked_event_id) REFERENCES events(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (deleted_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_task_tenant_list (tenant_id, task_list_id),
    INDEX idx_task_column_position (column_id, position),
    INDEX idx_task_assignee (tenant_id, assignee_id, status),
    INDEX idx_task_due_date (tenant_id, due_date, status),
    INDEX idx_task_status (tenant_id, status, deleted_at),
    INDEX idx_task_parent (parent_task_id),
    INDEX idx_task_priority (tenant_id, priority, status),
    INDEX idx_task_completed (tenant_id, completed_at),
    FULLTEXT idx_task_search (title, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task dependencies
CREATE TABLE task_dependencies (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    predecessor_id INT UNSIGNED NOT NULL,
    successor_id INT UNSIGNED NOT NULL,

    -- Dependency configuration
    dependency_type ENUM('finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish') DEFAULT 'finish_to_start',
    lag_days INT DEFAULT 0 COMMENT 'Days of lag/lead time',

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_blocking BOOLEAN DEFAULT TRUE COMMENT 'Whether this blocks the successor',

    -- Audit
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (predecessor_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (successor_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_task_dependency (predecessor_id, successor_id, dependency_type),
    INDEX idx_dependency_tenant (tenant_id),
    INDEX idx_dependency_successor (successor_id, is_active),
    CONSTRAINT chk_no_self_dependency CHECK (predecessor_id != successor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task comments
CREATE TABLE task_comments (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    task_id INT UNSIGNED NOT NULL,
    parent_comment_id INT UNSIGNED NULL COMMENT 'For threaded replies',

    -- Content
    comment_text TEXT NOT NULL,
    comment_html TEXT NULL COMMENT 'Rich text HTML version',

    -- Mentions
    mentioned_users JSON NULL COMMENT 'Array of mentioned user IDs',

    -- Edit tracking
    is_edited BOOLEAN DEFAULT FALSE,
    edited_at TIMESTAMP NULL,
    edit_count INT UNSIGNED DEFAULT 0,
    edit_history JSON NULL COMMENT 'Previous versions',

    -- Status
    is_system_generated BOOLEAN DEFAULT FALSE,
    is_pinned BOOLEAN DEFAULT FALSE,

    -- Soft delete
    deleted_at TIMESTAMP NULL,

    -- Audit fields
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES task_comments(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_comment_tenant_task (tenant_id, task_id),
    INDEX idx_comment_parent (parent_comment_id),
    INDEX idx_comment_created (task_id, created_at),
    FULLTEXT idx_comment_search (comment_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task attachments
CREATE TABLE task_attachments (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    task_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,

    -- Version tracking
    version_number INT UNSIGNED DEFAULT 1,
    is_latest_version BOOLEAN DEFAULT TRUE,
    previous_version_id INT UNSIGNED NULL,

    -- Metadata
    attachment_comment TEXT NULL,

    -- Audit
    attached_by INT UNSIGNED NOT NULL,
    attached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (previous_version_id) REFERENCES task_attachments(id) ON DELETE SET NULL,
    FOREIGN KEY (attached_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_task_file_version (task_id, file_id, version_number),
    INDEX idx_task_attach_tenant (tenant_id, task_id),
    INDEX idx_task_attach_latest (task_id, is_latest_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task watchers
CREATE TABLE task_watchers (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- Watch configuration
    watch_reason ENUM('assigned', 'mentioned', 'creator', 'manual', 'participant') DEFAULT 'manual',

    -- Notification preferences
    notify_on_comment BOOLEAN DEFAULT TRUE,
    notify_on_status_change BOOLEAN DEFAULT TRUE,
    notify_on_assignment BOOLEAN DEFAULT TRUE,
    notify_on_due_date BOOLEAN DEFAULT TRUE,
    notification_method ENUM('email', 'in_app', 'both', 'none') DEFAULT 'both',

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    muted_until TIMESTAMP NULL,

    -- Audit
    started_watching_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uk_task_watcher (task_id, user_id),
    INDEX idx_watcher_tenant_user (tenant_id, user_id, is_active),
    INDEX idx_watcher_task (task_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Time tracking entries
CREATE TABLE time_entries (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Association
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,

    -- Time tracking
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_minutes INT UNSIGNED NULL COMMENT 'Calculated or manual entry',

    -- Categorization
    activity_type VARCHAR(50) NULL COMMENT 'Development, Testing, Meeting, etc',
    time_category VARCHAR(50) NULL,

    -- Billing
    is_billable BOOLEAN DEFAULT FALSE,
    billable_rate DECIMAL(10,2) NULL,
    is_billed BOOLEAN DEFAULT FALSE,
    invoice_id INT UNSIGNED NULL,

    -- Description
    description TEXT NULL,

    -- Status
    is_running BOOLEAN DEFAULT FALSE,
    is_approved BOOLEAN DEFAULT FALSE,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,

    -- Audit fields
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_time_tenant_user (tenant_id, user_id),
    INDEX idx_time_task (task_id),
    INDEX idx_time_date (tenant_id, start_time),
    INDEX idx_time_billable (tenant_id, is_billable, is_billed),
    INDEX idx_time_running (tenant_id, is_running),
    CONSTRAINT chk_time_duration CHECK (
        (end_time IS NULL AND is_running = TRUE) OR
        (end_time IS NOT NULL AND end_time > start_time)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom fields definition
CREATE TABLE custom_fields (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Configuration
    field_name VARCHAR(50) NOT NULL,
    field_label VARCHAR(100) NOT NULL,
    field_type ENUM('text', 'number', 'date', 'datetime', 'dropdown', 'checkbox', 'multiselect', 'url', 'email', 'user') NOT NULL,
    entity_type ENUM('task', 'event', 'project', 'calendar') NOT NULL,

    -- Validation
    is_required BOOLEAN DEFAULT FALSE,
    is_unique BOOLEAN DEFAULT FALSE,
    min_value DECIMAL(20,4) NULL,
    max_value DECIMAL(20,4) NULL,
    regex_pattern VARCHAR(255) NULL,

    -- Options for dropdowns/multiselect
    field_options JSON NULL COMMENT 'Array of {value, label, color}',
    default_value VARCHAR(500) NULL,

    -- Display
    display_order INT UNSIGNED DEFAULT 0,
    help_text VARCHAR(255) NULL,
    placeholder VARCHAR(100) NULL,

    -- Permissions
    visible_to_roles JSON NULL COMMENT 'Array of role IDs',
    editable_by_roles JSON NULL COMMENT 'Array of role IDs',

    -- Status
    is_active BOOLEAN DEFAULT TRUE,

    -- Audit
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    UNIQUE KEY uk_custom_field (tenant_id, entity_type, field_name),
    INDEX idx_custom_field_entity (tenant_id, entity_type, is_active),
    INDEX idx_custom_field_order (entity_type, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom field values
CREATE TABLE custom_field_values (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    custom_field_id INT UNSIGNED NOT NULL,
    entity_id INT UNSIGNED NOT NULL,

    -- Values (use appropriate column based on field type)
    text_value TEXT NULL,
    number_value DECIMAL(20,4) NULL,
    date_value DATE NULL,
    datetime_value DATETIME NULL,
    boolean_value BOOLEAN NULL,
    json_value JSON NULL COMMENT 'For multiselect and complex types',

    -- Audit
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (custom_field_id) REFERENCES custom_fields(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uk_field_entity (custom_field_id, entity_id),
    INDEX idx_custom_value_tenant (tenant_id),
    INDEX idx_custom_value_entity (entity_id),
    INDEX idx_custom_value_field (custom_field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- VIEWS
-- ============================================

-- Upcoming events view (next 30 days)
CREATE OR REPLACE VIEW v_upcoming_events AS
SELECT
    e.tenant_id,
    e.id,
    e.title,
    e.start_datetime,
    e.end_datetime,
    e.is_all_day,
    e.location,
    e.status,
    c.name as calendar_name,
    c.color as calendar_color,
    u.name as organizer_name,
    u.email as organizer_email
FROM events e
INNER JOIN calendars c ON e.calendar_id = c.id
INNER JOIN users u ON e.organizer_id = u.id
WHERE e.deleted_at IS NULL
    AND e.status != 'cancelled'
    AND e.start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
ORDER BY e.start_datetime;

-- Overdue tasks view
CREATE OR REPLACE VIEW v_overdue_tasks AS
SELECT
    t.tenant_id,
    t.id,
    t.title,
    t.due_date,
    t.priority,
    t.status,
    t.assignee_id,
    u.name as assignee_name,
    u.email as assignee_email,
    tl.name as list_name,
    DATEDIFF(NOW(), t.due_date) as days_overdue
FROM tasks t
LEFT JOIN users u ON t.assignee_id = u.id
INNER JOIN task_lists tl ON t.task_list_id = tl.id
WHERE t.deleted_at IS NULL
    AND t.status NOT IN ('done', 'cancelled')
    AND t.due_date < NOW()
ORDER BY t.priority DESC, days_overdue DESC;

-- Task workload by user view
CREATE OR REPLACE VIEW v_user_workload AS
SELECT
    t.tenant_id,
    t.assignee_id,
    u.name as user_name,
    COUNT(t.id) as total_tasks,
    SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
    SUM(CASE WHEN t.status = 'todo' THEN 1 ELSE 0 END) as todo_count,
    SUM(CASE WHEN t.priority = 'urgent' THEN 1 ELSE 0 END) as urgent_count,
    SUM(CASE WHEN t.due_date < NOW() AND t.status NOT IN ('done', 'cancelled') THEN 1 ELSE 0 END) as overdue_count,
    SUM(t.estimated_hours) as total_estimated_hours,
    SUM(t.actual_hours) as total_actual_hours
FROM tasks t
INNER JOIN users u ON t.assignee_id = u.id
WHERE t.deleted_at IS NULL
    AND t.status NOT IN ('done', 'cancelled')
GROUP BY t.tenant_id, t.assignee_id, u.name;

-- Calendar availability view
CREATE OR REPLACE VIEW v_calendar_availability AS
SELECT
    c.tenant_id,
    c.id as calendar_id,
    c.name as calendar_name,
    c.owner_id,
    e.start_datetime,
    e.end_datetime,
    e.busy_status,
    CASE
        WHEN e.visibility = 'private' THEN 'Busy'
        ELSE e.title
    END as event_title
FROM calendars c
LEFT JOIN events e ON c.id = e.calendar_id
WHERE c.is_active = TRUE
    AND c.deleted_at IS NULL
    AND (e.id IS NULL OR (e.deleted_at IS NULL AND e.status != 'cancelled'))
    AND (e.id IS NULL OR e.start_datetime >= NOW())
ORDER BY c.id, e.start_datetime;

-- ============================================
-- TRIGGERS
-- ============================================

DELIMITER $$

-- Trigger to update parent task progress when subtasks complete
CREATE TRIGGER trg_update_parent_task_progress
AFTER UPDATE ON tasks
FOR EACH ROW
BEGIN
    IF NEW.parent_task_id IS NOT NULL AND OLD.status != NEW.status THEN
        UPDATE tasks
        SET progress_percentage = (
            SELECT AVG(
                CASE
                    WHEN status = 'done' THEN 100
                    WHEN status = 'cancelled' THEN 0
                    ELSE IFNULL(progress_percentage, 0)
                END
            )
            FROM tasks
            WHERE parent_task_id = NEW.parent_task_id
                AND deleted_at IS NULL
        )
        WHERE id = NEW.parent_task_id;
    END IF;
END$$

-- Trigger to maintain task list statistics
CREATE TRIGGER trg_task_list_stats_insert
AFTER INSERT ON tasks
FOR EACH ROW
BEGIN
    UPDATE task_lists
    SET task_count = task_count + 1,
        completed_count = completed_count + IF(NEW.status = 'done', 1, 0)
    WHERE id = NEW.task_list_id;
END$$

CREATE TRIGGER trg_task_list_stats_update
AFTER UPDATE ON tasks
FOR EACH ROW
BEGIN
    IF OLD.status != NEW.status THEN
        UPDATE task_lists
        SET completed_count = completed_count +
            CASE
                WHEN NEW.status = 'done' AND OLD.status != 'done' THEN 1
                WHEN NEW.status != 'done' AND OLD.status = 'done' THEN -1
                ELSE 0
            END
        WHERE id = NEW.task_list_id;
    END IF;
END$$

CREATE TRIGGER trg_task_list_stats_delete
AFTER DELETE ON tasks
FOR EACH ROW
BEGIN
    UPDATE task_lists
    SET task_count = task_count - 1,
        completed_count = completed_count - IF(OLD.status = 'done', 1, 0)
    WHERE id = OLD.task_list_id;
END$$

-- Trigger to sync calendar events with task due dates
CREATE TRIGGER trg_sync_task_event
AFTER UPDATE ON tasks
FOR EACH ROW
BEGIN
    IF NEW.linked_event_id IS NOT NULL AND OLD.due_date != NEW.due_date THEN
        UPDATE events
        SET end_datetime = NEW.due_date,
            updated_at = NOW()
        WHERE id = NEW.linked_event_id;
    END IF;
END$$

-- Trigger to calculate time entry duration
CREATE TRIGGER trg_time_entry_duration
BEFORE INSERT ON time_entries
FOR EACH ROW
BEGIN
    IF NEW.end_time IS NOT NULL AND NEW.start_time IS NOT NULL THEN
        SET NEW.duration_minutes = TIMESTAMPDIFF(MINUTE, NEW.start_time, NEW.end_time);
        SET NEW.is_running = FALSE;
    END IF;
END$$

CREATE TRIGGER trg_time_entry_duration_update
BEFORE UPDATE ON time_entries
FOR EACH ROW
BEGIN
    IF NEW.end_time IS NOT NULL AND NEW.start_time IS NOT NULL THEN
        SET NEW.duration_minutes = TIMESTAMPDIFF(MINUTE, NEW.start_time, NEW.end_time);
        SET NEW.is_running = FALSE;
    END IF;

    -- Update task actual hours
    IF NEW.duration_minutes != OLD.duration_minutes THEN
        UPDATE tasks
        SET actual_hours = (
            SELECT SUM(duration_minutes) / 60
            FROM time_entries
            WHERE task_id = NEW.task_id
        )
        WHERE id = NEW.task_id;
    END IF;
END$$

DELIMITER ;

-- ============================================
-- STORED PROCEDURES
-- ============================================

DELIMITER $$

-- Generate recurring event instances
CREATE PROCEDURE sp_generate_recurring_events(
    IN p_tenant_id INT,
    IN p_event_id INT UNSIGNED,
    IN p_start_date DATE,
    IN p_end_date DATE
)
BEGIN
    DECLARE v_pattern_id INT UNSIGNED;
    DECLARE v_freq VARCHAR(20);
    DECLARE v_interval INT;
    DECLARE v_until_date DATETIME;
    DECLARE v_count INT;
    DECLARE v_current_date DATETIME;
    DECLARE v_instance_count INT DEFAULT 0;

    -- Get recurrence pattern
    SELECT rp.id, rp.freq, rp.interval_value, rp.until_date, rp.count
    INTO v_pattern_id, v_freq, v_interval, v_until_date, v_count
    FROM events e
    JOIN recurring_patterns rp ON e.recurring_pattern_id = rp.id
    WHERE e.id = p_event_id AND e.tenant_id = p_tenant_id;

    IF v_pattern_id IS NOT NULL THEN
        SET v_current_date = (SELECT start_datetime FROM events WHERE id = p_event_id);

        -- Generate instances based on frequency
        WHILE v_current_date <= p_end_date
            AND (v_until_date IS NULL OR v_current_date <= v_until_date)
            AND (v_count IS NULL OR v_instance_count < v_count) DO

            -- Check if this date should be excluded
            IF NOT EXISTS (
                SELECT 1 FROM recurring_patterns
                WHERE id = v_pattern_id
                AND JSON_CONTAINS(exception_dates, JSON_QUOTE(DATE(v_current_date)))
            ) THEN
                -- Create instance (implementation would insert into events table)
                SET v_instance_count = v_instance_count + 1;
            END IF;

            -- Calculate next occurrence
            CASE v_freq
                WHEN 'DAILY' THEN
                    SET v_current_date = DATE_ADD(v_current_date, INTERVAL v_interval DAY);
                WHEN 'WEEKLY' THEN
                    SET v_current_date = DATE_ADD(v_current_date, INTERVAL v_interval WEEK);
                WHEN 'MONTHLY' THEN
                    SET v_current_date = DATE_ADD(v_current_date, INTERVAL v_interval MONTH);
                WHEN 'YEARLY' THEN
                    SET v_current_date = DATE_ADD(v_current_date, INTERVAL v_interval YEAR);
            END CASE;
        END WHILE;
    END IF;
END$$

-- Calculate task completion percentage
CREATE PROCEDURE sp_calculate_task_completion(
    IN p_task_id INT UNSIGNED
)
BEGIN
    DECLARE v_total_subtasks INT DEFAULT 0;
    DECLARE v_completed_subtasks INT DEFAULT 0;
    DECLARE v_completion_percentage INT DEFAULT 0;

    -- Count subtasks
    SELECT
        COUNT(*),
        SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END)
    INTO v_total_subtasks, v_completed_subtasks
    FROM tasks
    WHERE parent_task_id = p_task_id
        AND deleted_at IS NULL;

    -- Calculate percentage
    IF v_total_subtasks > 0 THEN
        SET v_completion_percentage = ROUND((v_completed_subtasks * 100.0) / v_total_subtasks);
    ELSE
        -- No subtasks, use the task's own progress
        SELECT progress_percentage INTO v_completion_percentage
        FROM tasks WHERE id = p_task_id;
    END IF;

    -- Update task
    UPDATE tasks
    SET progress_percentage = v_completion_percentage,
        updated_at = NOW()
    WHERE id = p_task_id;

    SELECT v_completion_percentage as completion_percentage;
END$$

-- Move task between columns
CREATE PROCEDURE sp_move_task_to_column(
    IN p_task_id INT UNSIGNED,
    IN p_target_column_id INT UNSIGNED,
    IN p_position INT UNSIGNED
)
BEGIN
    DECLARE v_old_column_id INT UNSIGNED;
    DECLARE v_task_list_id INT UNSIGNED;

    -- Get current column
    SELECT column_id, task_list_id
    INTO v_old_column_id, v_task_list_id
    FROM tasks WHERE id = p_task_id;

    -- Update positions in old column
    IF v_old_column_id IS NOT NULL THEN
        UPDATE tasks
        SET position = position - 1
        WHERE column_id = v_old_column_id
            AND position > (SELECT position FROM tasks WHERE id = p_task_id);
    END IF;

    -- Update positions in new column
    UPDATE tasks
    SET position = position + 1
    WHERE column_id = p_target_column_id
        AND position >= p_position;

    -- Move the task
    UPDATE tasks
    SET column_id = p_target_column_id,
        position = p_position,
        updated_at = NOW()
    WHERE id = p_task_id;

    -- Update status based on column mapping
    UPDATE tasks t
    JOIN task_list_columns c ON c.id = p_target_column_id
    SET t.status = IFNULL(c.status_mapping, t.status),
        t.completed_at = IF(c.marks_complete, NOW(), NULL)
    WHERE t.id = p_task_id;
END$$

-- Bulk update task status
CREATE PROCEDURE sp_bulk_update_task_status(
    IN p_tenant_id INT,
    IN p_task_ids JSON,
    IN p_new_status VARCHAR(20),
    IN p_user_id INT UNSIGNED
)
BEGIN
    DECLARE v_task_id INT UNSIGNED;
    DECLARE v_index INT DEFAULT 0;
    DECLARE v_count INT;

    SET v_count = JSON_LENGTH(p_task_ids);

    WHILE v_index < v_count DO
        SET v_task_id = JSON_EXTRACT(p_task_ids, CONCAT('$[', v_index, ']'));

        UPDATE tasks
        SET status = p_new_status,
            completed_at = IF(p_new_status = 'done', NOW(), NULL),
            updated_by = p_user_id,
            updated_at = NOW()
        WHERE id = v_task_id
            AND tenant_id = p_tenant_id;

        SET v_index = v_index + 1;
    END WHILE;

    SELECT v_count as tasks_updated;
END$$

DELIMITER ;

-- ============================================
-- DEMO DATA
-- ============================================

-- Sample tenants (if not exists)
INSERT INTO tenants (id, name) VALUES
    (1, 'Acme Corporation'),
    (2, 'TechStart Inc')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample calendars
INSERT INTO calendars (tenant_id, name, description, owner_id, color, visibility, is_default, created_by) VALUES
    (1, 'Company Calendar', 'Main company events and meetings', 1, '#0066CC', 'team', TRUE, 1),
    (1, 'Personal Calendar', 'Personal appointments and reminders', 2, '#00AA00', 'private', FALSE, 2),
    (1, 'Project Milestones', 'Important project deadlines', 1, '#FF6633', 'team', FALSE, 1),
    (2, 'Team Calendar', 'Team meetings and events', 3, '#9933FF', 'team', TRUE, 3);

-- Sample recurring patterns
INSERT INTO recurring_patterns (tenant_id, rrule, freq, interval_value, by_day) VALUES
    (1, 'FREQ=WEEKLY;BYDAY=MO,WE,FR', 'WEEKLY', 1, 'MO,WE,FR'),
    (1, 'FREQ=MONTHLY;BYMONTHDAY=15', 'MONTHLY', 1, NULL),
    (1, 'FREQ=DAILY;INTERVAL=1', 'DAILY', 1, NULL);

-- Sample events
INSERT INTO events (tenant_id, calendar_id, title, description, start_datetime, end_datetime,
                   is_all_day, status, organizer_id, created_by) VALUES
    (1, 1, 'Q1 Planning Meeting', 'Quarterly planning session for all teams',
     '2025-01-25 10:00:00', '2025-01-25 12:00:00', FALSE, 'confirmed', 1, 1),
    (1, 1, 'Team Standup', 'Daily team sync',
     '2025-01-23 09:00:00', '2025-01-23 09:30:00', FALSE, 'confirmed', 1, 1),
    (1, 2, 'Dentist Appointment', 'Annual checkup',
     '2025-01-28 14:00:00', '2025-01-28 15:00:00', FALSE, 'tentative', 2, 2),
    (1, 3, 'Project Alpha Launch', 'Go-live date for Project Alpha',
     '2025-02-01 00:00:00', '2025-02-01 23:59:59', TRUE, 'confirmed', 1, 1);

-- Sample task lists
INSERT INTO task_lists (tenant_id, name, description, owner_id, board_type, color,
                       visibility, created_by) VALUES
    (1, 'Development Sprint', 'Current sprint tasks', 1, 'kanban', '#4A90E2', 'team', 1),
    (1, 'Bug Tracker', 'Active bugs and issues', 1, 'kanban', '#E74C3C', 'team', 1),
    (1, 'Product Roadmap', 'Long-term product goals', 1, 'timeline', '#2ECC71', 'team', 1),
    (2, 'Marketing Campaign', 'Q1 marketing tasks', 3, 'list', '#9B59B6', 'team', 3);

-- Sample kanban columns
INSERT INTO task_list_columns (tenant_id, task_list_id, name, color, position,
                              status_mapping, is_default, marks_complete) VALUES
    (1, 1, 'Backlog', '#E0E0E0', 0, 'backlog', TRUE, FALSE),
    (1, 1, 'To Do', '#FFE082', 1, 'todo', FALSE, FALSE),
    (1, 1, 'In Progress', '#64B5F6', 2, 'in_progress', FALSE, FALSE),
    (1, 1, 'Code Review', '#BA68C8', 3, 'review', FALSE, FALSE),
    (1, 1, 'Done', '#81C784', 4, 'done', FALSE, TRUE),
    (1, 2, 'New', '#FFCDD2', 0, 'backlog', TRUE, FALSE),
    (1, 2, 'In Progress', '#FFF9C4', 1, 'in_progress', FALSE, FALSE),
    (1, 2, 'Testing', '#C5E1A5', 2, 'review', FALSE, FALSE),
    (1, 2, 'Resolved', '#B2DFDB', 3, 'done', FALSE, TRUE);

-- Sample tasks
INSERT INTO tasks (tenant_id, task_list_id, column_id, title, description,
                  assignee_id, reporter_id, due_date, priority, status,
                  estimated_hours, created_by) VALUES
    (1, 1, 1, 'Implement user authentication', 'Add JWT-based auth system',
     2, 1, '2025-01-30 17:00:00', 'high', 'backlog', 16, 1),
    (1, 1, 2, 'Design database schema', 'Create tables for new features',
     2, 1, '2025-01-25 17:00:00', 'urgent', 'todo', 8, 1),
    (1, 1, 3, 'API endpoint development', 'REST API for user management',
     2, 1, '2025-01-28 17:00:00', 'high', 'in_progress', 12, 1),
    (1, 2, 6, 'Fix login page CSS', 'Button alignment issues on mobile',
     NULL, 2, '2025-01-24 17:00:00', 'medium', 'backlog', 2, 2),
    (1, 2, 7, 'Database connection timeout', 'Connection drops after 5 minutes',
     2, 1, '2025-01-23 17:00:00', 'urgent', 'in_progress', 4, 1);

-- Sample subtasks
INSERT INTO tasks (tenant_id, task_list_id, column_id, parent_task_id, title,
                  assignee_id, reporter_id, priority, status, created_by) VALUES
    (1, 1, 2, 1, 'Create user model', 2, 1, 'high', 'todo', 1),
    (1, 1, 2, 1, 'Implement password hashing', 2, 1, 'high', 'todo', 1),
    (1, 1, 3, 1, 'Add session management', 2, 1, 'high', 'in_progress', 1);

-- Sample task comments
INSERT INTO task_comments (tenant_id, task_id, comment_text, created_by) VALUES
    (1, 1, 'We should use bcrypt for password hashing', 2),
    (1, 1, 'Agreed. Also need to implement refresh tokens', 1),
    (1, 3, 'API documentation is available in the wiki', 2),
    (1, 5, 'This seems to be related to the connection pool settings', 1);

-- Sample task dependencies
INSERT INTO task_dependencies (tenant_id, predecessor_id, successor_id,
                              dependency_type, created_by) VALUES
    (1, 2, 3, 'finish_to_start', 1),
    (1, 1, 3, 'finish_to_start', 1);

-- Sample time entries
INSERT INTO time_entries (tenant_id, task_id, user_id, start_time, end_time,
                         duration_minutes, activity_type, is_billable, description, created_by) VALUES
    (1, 3, 2, '2025-01-22 09:00:00', '2025-01-22 11:30:00', 150, 'Development', TRUE,
     'Implemented GET and POST endpoints', 2),
    (1, 3, 2, '2025-01-22 13:00:00', '2025-01-22 16:00:00', 180, 'Development', TRUE,
     'Added validation and error handling', 2),
    (1, 5, 2, '2025-01-22 16:00:00', '2025-01-22 17:30:00', 90, 'Debugging', TRUE,
     'Investigating timeout issue', 2);

-- Sample custom fields
INSERT INTO custom_fields (tenant_id, field_name, field_label, field_type,
                          entity_type, is_required, field_options, created_by) VALUES
    (1, 'sprint_number', 'Sprint', 'number', 'task', FALSE, NULL, 1),
    (1, 'story_points', 'Story Points', 'dropdown', 'task', FALSE,
     '[{"value":"1","label":"1 Point"},{"value":"2","label":"2 Points"},{"value":"3","label":"3 Points"},{"value":"5","label":"5 Points"},{"value":"8","label":"8 Points"}]', 1),
    (1, 'meeting_room', 'Meeting Room', 'dropdown', 'event', FALSE,
     '[{"value":"boardroom","label":"Boardroom"},{"value":"conference_a","label":"Conference Room A"},{"value":"virtual","label":"Virtual"}]', 1);

-- Sample custom field values
INSERT INTO custom_field_values (tenant_id, custom_field_id, entity_id,
                                number_value, created_by) VALUES
    (1, 1, 1, 14, 1),
    (1, 1, 2, 14, 1),
    (1, 1, 3, 14, 1);

-- Sample event participants
INSERT INTO event_participants (tenant_id, event_id, user_id, role,
                               rsvp_status, invited_by) VALUES
    (1, 1, 1, 'organizer', 'accepted', 1),
    (1, 1, 2, 'required', 'accepted', 1),
    (1, 1, 3, 'optional', 'pending', 1),
    (1, 2, 1, 'organizer', 'accepted', 1),
    (1, 2, 2, 'required', 'accepted', 1);

-- Sample event reminders
INSERT INTO event_reminders (tenant_id, event_id, user_id, reminder_type,
                            minutes_before) VALUES
    (1, 1, 1, 'notification', 15),
    (1, 1, 1, 'email', 60),
    (1, 1, 2, 'notification', 15),
    (1, 3, 2, 'email', 1440);

-- Sample task watchers
INSERT INTO task_watchers (tenant_id, task_id, user_id, watch_reason) VALUES
    (1, 1, 1, 'creator'),
    (1, 1, 3, 'manual'),
    (1, 3, 1, 'creator'),
    (1, 5, 3, 'mentioned');

-- ============================================
-- PERFORMANCE INDEXES (Additional)
-- ============================================

-- Composite indexes for common queries
CREATE INDEX idx_event_date_range ON events(tenant_id, start_datetime, end_datetime, status);
CREATE INDEX idx_task_assignee_status ON tasks(tenant_id, assignee_id, status, due_date);
CREATE INDEX idx_task_list_active ON task_lists(tenant_id, is_active, is_archived);
CREATE INDEX idx_time_entry_report ON time_entries(tenant_id, user_id, start_time, is_billable);

-- Covering indexes for frequently accessed data
CREATE INDEX idx_calendar_quick_access ON calendars(tenant_id, owner_id, is_active, visibility, name);
CREATE INDEX idx_task_dashboard ON tasks(tenant_id, status, priority, due_date, assignee_id);

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Productivity Tools database setup completed successfully' as status,
       (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = 'collabora'
        AND table_name IN ('calendars', 'events', 'task_lists', 'tasks')) as tables_created,
       (SELECT COUNT(*) FROM events) as sample_events,
       (SELECT COUNT(*) FROM tasks) as sample_tasks,
       NOW() as execution_time;

-- Table size summary
SELECT
    'Calendar & Task Management Schema' as module,
    COUNT(DISTINCT table_name) as total_tables,
    COUNT(DISTINCT CASE WHEN index_name != 'PRIMARY' THEN index_name END) as total_indexes
FROM information_schema.statistics
WHERE table_schema = 'collabora'
    AND table_name IN (
        'calendars', 'calendar_shares', 'events', 'event_participants',
        'event_reminders', 'event_attachments', 'task_lists', 'task_list_columns',
        'tasks', 'task_dependencies', 'task_comments', 'task_attachments',
        'task_watchers', 'time_entries', 'custom_fields', 'custom_field_values',
        'recurring_patterns'
    );