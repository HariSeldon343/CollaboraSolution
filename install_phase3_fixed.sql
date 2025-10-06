-- Module: Productivity Tools (Fixed for XAMPP)
-- Version: 2025-01-22

USE collabora;

-- ============================================
-- CLEANUP
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
-- TABLES
-- ============================================

-- Recurring patterns
CREATE TABLE recurring_patterns (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rrule VARCHAR(500) NOT NULL,
    freq ENUM('DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY') NOT NULL,
    interval_value INT UNSIGNED DEFAULT 1,
    until_date DATETIME NULL,
    count INT UNSIGNED NULL,
    by_day VARCHAR(50) NULL,
    by_month_day VARCHAR(100) NULL,
    by_month VARCHAR(50) NULL,
    by_set_pos VARCHAR(50) NULL,
    exception_dates JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_recurring_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendars
CREATE TABLE calendars (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    owner_id INT UNSIGNED NOT NULL,
    color VARCHAR(7) DEFAULT '#0066CC',
    icon VARCHAR(50) NULL,
    timezone VARCHAR(50) DEFAULT 'UTC',
    visibility ENUM('private', 'team', 'public') DEFAULT 'private',
    is_default BOOLEAN DEFAULT FALSE,
    is_shared BOOLEAN DEFAULT FALSE,
    default_reminder_minutes INT DEFAULT 15,
    week_start_day TINYINT DEFAULT 1,
    working_hours_start TIME DEFAULT '09:00:00',
    working_hours_end TIME DEFAULT '18:00:00',
    is_active BOOLEAN DEFAULT TRUE,
    deleted_at TIMESTAMP NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_calendar_tenant_owner (tenant_id, owner_id),
    INDEX idx_calendar_visibility (tenant_id, visibility, is_active),
    INDEX idx_calendar_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendar shares
CREATE TABLE calendar_shares (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    calendar_id INT UNSIGNED NOT NULL,
    shared_with_user_id INT UNSIGNED NULL,
    shared_with_team_id INT UNSIGNED NULL,
    can_view BOOLEAN DEFAULT TRUE,
    can_edit BOOLEAN DEFAULT FALSE,
    can_delete BOOLEAN DEFAULT FALSE,
    can_share BOOLEAN DEFAULT FALSE,
    hide_details BOOLEAN DEFAULT FALSE,
    shared_by INT UNSIGNED NOT NULL,
    shared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    UNIQUE KEY uk_calendar_share (calendar_id, shared_with_user_id, shared_with_team_id),
    INDEX idx_share_tenant_user (tenant_id, shared_with_user_id),
    INDEX idx_share_tenant_team (tenant_id, shared_with_team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Events
CREATE TABLE events (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    calendar_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    location VARCHAR(255) NULL,
    location_details JSON NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    is_all_day BOOLEAN DEFAULT FALSE,
    timezone VARCHAR(50) DEFAULT 'UTC',
    recurring_pattern_id INT UNSIGNED NULL,
    recurrence_id VARCHAR(100) NULL,
    is_recurring_exception BOOLEAN DEFAULT FALSE,
    status ENUM('confirmed', 'tentative', 'cancelled') DEFAULT 'confirmed',
    visibility ENUM('public', 'private', 'confidential') DEFAULT 'public',
    busy_status ENUM('free', 'busy', 'tentative', 'oof') DEFAULT 'busy',
    category VARCHAR(50) NULL,
    tags JSON NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    color VARCHAR(7) NULL,
    url VARCHAR(500) NULL,
    meeting_url VARCHAR(500) NULL,
    linked_task_id INT UNSIGNED NULL,
    organizer_id INT UNSIGNED NOT NULL,
    is_private BOOLEAN DEFAULT FALSE,
    allow_comments BOOLEAN DEFAULT TRUE,
    deleted_at TIMESTAMP NULL,
    deleted_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    FOREIGN KEY (recurring_pattern_id) REFERENCES recurring_patterns(id) ON DELETE SET NULL,
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
    name VARCHAR(100) NULL,
    role ENUM('organizer', 'required', 'optional', 'resource') DEFAULT 'required',
    rsvp_status ENUM('pending', 'accepted', 'declined', 'tentative', 'delegated') DEFAULT 'pending',
    rsvp_comment TEXT NULL,
    rsvp_at TIMESTAMP NULL,
    send_notifications BOOLEAN DEFAULT TRUE,
    notification_method ENUM('email', 'in_app', 'both', 'none') DEFAULT 'both',
    reminder_sent BOOLEAN DEFAULT FALSE,
    delegated_to INT UNSIGNED NULL,
    delegated_from INT UNSIGNED NULL,
    invited_by INT UNSIGNED NOT NULL,
    invited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    UNIQUE KEY uk_event_participant (event_id, user_id, external_email),
    INDEX idx_participant_tenant_user (tenant_id, user_id),
    INDEX idx_participant_rsvp (event_id, rsvp_status),
    INDEX idx_participant_role (event_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event reminders
CREATE TABLE event_reminders (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    reminder_type ENUM('email', 'notification', 'sms', 'popup') DEFAULT 'notification',
    minutes_before INT UNSIGNED NOT NULL DEFAULT 15,
    is_sent BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP NULL,
    is_snoozed BOOLEAN DEFAULT FALSE,
    snoozed_until TIMESTAMP NULL,
    custom_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
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
    attached_by INT UNSIGNED NOT NULL,
    attached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    UNIQUE KEY uk_event_file (event_id, file_id),
    INDEX idx_event_attach_tenant (tenant_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task lists
CREATE TABLE task_lists (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    owner_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NULL,
    board_type ENUM('kanban', 'list', 'timeline', 'calendar') DEFAULT 'kanban',
    is_template BOOLEAN DEFAULT FALSE,
    template_category VARCHAR(50) NULL,
    color VARCHAR(7) DEFAULT '#4A90E2',
    icon VARCHAR(50) NULL,
    background_image VARCHAR(500) NULL,
    default_assignee_id INT UNSIGNED NULL,
    auto_archive_days INT NULL,
    allow_subtasks BOOLEAN DEFAULT TRUE,
    require_time_tracking BOOLEAN DEFAULT FALSE,
    visibility ENUM('private', 'team', 'public') DEFAULT 'team',
    is_shared BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    is_archived BOOLEAN DEFAULT FALSE,
    archived_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    task_count INT UNSIGNED DEFAULT 0,
    completed_count INT UNSIGNED DEFAULT 0,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_tasklist_tenant_owner (tenant_id, owner_id),
    INDEX idx_tasklist_project (tenant_id, project_id),
    INDEX idx_tasklist_active (tenant_id, is_active, is_archived),
    INDEX idx_tasklist_template (is_template, template_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task list columns
CREATE TABLE task_list_columns (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_list_id INT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    color VARCHAR(7) DEFAULT '#E0E0E0',
    position INT UNSIGNED NOT NULL DEFAULT 0,
    status_mapping VARCHAR(50) NULL,
    is_default BOOLEAN DEFAULT FALSE,
    marks_complete BOOLEAN DEFAULT FALSE,
    wip_limit INT UNSIGNED NULL,
    auto_assign_to INT UNSIGNED NULL,
    auto_move_after_hours INT NULL,
    auto_move_to_column_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (task_list_id) REFERENCES task_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (auto_move_to_column_id) REFERENCES task_list_columns(id) ON DELETE SET NULL,
    UNIQUE KEY uk_column_position (task_list_id, position),
    INDEX idx_column_tenant_list (tenant_id, task_list_id),
    INDEX idx_column_default (task_list_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks
CREATE TABLE tasks (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_list_id INT UNSIGNED NOT NULL,
    column_id INT UNSIGNED NULL,
    parent_task_id INT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    assignee_id INT UNSIGNED NULL,
    reporter_id INT UNSIGNED NOT NULL,
    due_date DATETIME NULL,
    start_date DATETIME NULL,
    completed_at TIMESTAMP NULL,
    estimated_hours DECIMAL(8,2) NULL,
    actual_hours DECIMAL(8,2) DEFAULT 0,
    remaining_hours DECIMAL(8,2) NULL,
    is_billable BOOLEAN DEFAULT FALSE,
    hourly_rate DECIMAL(10,2) NULL,
    priority ENUM('urgent', 'high', 'medium', 'low') DEFAULT 'medium',
    status ENUM('backlog', 'todo', 'in_progress', 'review', 'done', 'cancelled') DEFAULT 'todo',
    progress_percentage TINYINT UNSIGNED DEFAULT 0,
    category VARCHAR(50) NULL,
    tags JSON NULL,
    labels JSON NULL,
    position INT UNSIGNED DEFAULT 0,
    sort_order DECIMAL(20,10) DEFAULT 0,
    recurring_pattern_id INT UNSIGNED NULL,
    recurrence_parent_id INT UNSIGNED NULL,
    linked_event_id INT UNSIGNED NULL,
    external_id VARCHAR(100) NULL,
    external_url VARCHAR(500) NULL,
    is_milestone BOOLEAN DEFAULT FALSE,
    is_private BOOLEAN DEFAULT FALSE,
    is_flagged BOOLEAN DEFAULT FALSE,
    is_blocked BOOLEAN DEFAULT FALSE,
    block_reason TEXT NULL,
    deleted_at TIMESTAMP NULL,
    deleted_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (task_list_id) REFERENCES task_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (column_id) REFERENCES task_list_columns(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (recurring_pattern_id) REFERENCES recurring_patterns(id) ON DELETE SET NULL,
    FOREIGN KEY (recurrence_parent_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (linked_event_id) REFERENCES events(id) ON DELETE SET NULL,
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

-- Other tables without complex constraints
CREATE TABLE task_dependencies (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    predecessor_id INT UNSIGNED NOT NULL,
    successor_id INT UNSIGNED NOT NULL,
    dependency_type ENUM('finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish') DEFAULT 'finish_to_start',
    lag_days INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    is_blocking BOOLEAN DEFAULT TRUE,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (predecessor_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (successor_id) REFERENCES tasks(id) ON DELETE CASCADE,
    UNIQUE KEY uk_task_dependency (predecessor_id, successor_id, dependency_type),
    INDEX idx_dependency_tenant (tenant_id),
    INDEX idx_dependency_successor (successor_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_comments (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    parent_comment_id INT UNSIGNED NULL,
    comment_text TEXT NOT NULL,
    comment_html TEXT NULL,
    mentioned_users JSON NULL,
    is_edited BOOLEAN DEFAULT FALSE,
    edited_at TIMESTAMP NULL,
    edit_count INT UNSIGNED DEFAULT 0,
    edit_history JSON NULL,
    is_system_generated BOOLEAN DEFAULT FALSE,
    is_pinned BOOLEAN DEFAULT FALSE,
    deleted_at TIMESTAMP NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES task_comments(id) ON DELETE CASCADE,
    INDEX idx_comment_tenant_task (tenant_id, task_id),
    INDEX idx_comment_parent (parent_comment_id),
    INDEX idx_comment_created (task_id, created_at),
    FULLTEXT idx_comment_search (comment_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_attachments (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    file_id INT UNSIGNED NOT NULL,
    version_number INT UNSIGNED DEFAULT 1,
    is_latest_version BOOLEAN DEFAULT TRUE,
    previous_version_id INT UNSIGNED NULL,
    attachment_comment TEXT NULL,
    attached_by INT UNSIGNED NOT NULL,
    attached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    FOREIGN KEY (previous_version_id) REFERENCES task_attachments(id) ON DELETE SET NULL,
    UNIQUE KEY uk_task_file_version (task_id, file_id, version_number),
    INDEX idx_task_attach_tenant (tenant_id, task_id),
    INDEX idx_task_attach_latest (task_id, is_latest_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_watchers (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    watch_reason ENUM('assigned', 'mentioned', 'creator', 'manual', 'participant') DEFAULT 'manual',
    notify_on_comment BOOLEAN DEFAULT TRUE,
    notify_on_status_change BOOLEAN DEFAULT TRUE,
    notify_on_assignment BOOLEAN DEFAULT TRUE,
    notify_on_due_date BOOLEAN DEFAULT TRUE,
    notification_method ENUM('email', 'in_app', 'both', 'none') DEFAULT 'both',
    is_active BOOLEAN DEFAULT TRUE,
    muted_until TIMESTAMP NULL,
    started_watching_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    UNIQUE KEY uk_task_watcher (task_id, user_id),
    INDEX idx_watcher_tenant_user (tenant_id, user_id, is_active),
    INDEX idx_watcher_task (task_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE time_entries (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_minutes INT UNSIGNED NULL,
    activity_type VARCHAR(50) NULL,
    time_category VARCHAR(50) NULL,
    is_billable BOOLEAN DEFAULT FALSE,
    billable_rate DECIMAL(10,2) NULL,
    is_billed BOOLEAN DEFAULT FALSE,
    invoice_id INT UNSIGNED NULL,
    description TEXT NULL,
    is_running BOOLEAN DEFAULT FALSE,
    is_approved BOOLEAN DEFAULT FALSE,
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    INDEX idx_time_tenant_user (tenant_id, user_id),
    INDEX idx_time_task (task_id),
    INDEX idx_time_date (tenant_id, start_time),
    INDEX idx_time_billable (tenant_id, is_billable, is_billed),
    INDEX idx_time_running (tenant_id, is_running)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom fields
CREATE TABLE custom_fields (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    field_name VARCHAR(50) NOT NULL,
    field_label VARCHAR(100) NOT NULL,
    field_type ENUM('text', 'number', 'date', 'datetime', 'dropdown', 'checkbox', 'multiselect', 'url', 'email', 'user') NOT NULL,
    entity_type ENUM('task', 'event', 'project', 'calendar') NOT NULL,
    is_required BOOLEAN DEFAULT FALSE,
    is_unique BOOLEAN DEFAULT FALSE,
    min_value DECIMAL(20,4) NULL,
    max_value DECIMAL(20,4) NULL,
    regex_pattern VARCHAR(255) NULL,
    field_options JSON NULL,
    default_value VARCHAR(500) NULL,
    display_order INT UNSIGNED DEFAULT 0,
    help_text VARCHAR(255) NULL,
    placeholder VARCHAR(100) NULL,
    visible_to_roles JSON NULL,
    editable_by_roles JSON NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_custom_field (tenant_id, entity_type, field_name),
    INDEX idx_custom_field_entity (tenant_id, entity_type, is_active),
    INDEX idx_custom_field_order (entity_type, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE custom_field_values (
    tenant_id INT NOT NULL,
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    custom_field_id INT UNSIGNED NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    text_value TEXT NULL,
    number_value DECIMAL(20,4) NULL,
    date_value DATE NULL,
    datetime_value DATETIME NULL,
    boolean_value BOOLEAN NULL,
    json_value JSON NULL,
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (custom_field_id) REFERENCES custom_fields(id) ON DELETE CASCADE,
    UNIQUE KEY uk_field_entity (custom_field_id, entity_id),
    INDEX idx_custom_value_tenant (tenant_id),
    INDEX idx_custom_value_entity (entity_id),
    INDEX idx_custom_value_field (custom_field_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample data
INSERT INTO calendars (tenant_id, name, description, owner_id, color, visibility, is_default, created_by) VALUES
    (1, 'Company Calendar', 'Main company events and meetings', 1, '#0066CC', 'team', TRUE, 1),
    (1, 'Personal Calendar', 'Personal appointments and reminders', 2, '#00AA00', 'private', FALSE, 2)
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO task_lists (tenant_id, name, description, owner_id, board_type, color, visibility, created_by) VALUES
    (1, 'Development Sprint', 'Current sprint tasks', 1, 'kanban', '#4A90E2', 'team', 1),
    (1, 'Bug Tracker', 'Active bugs and issues', 1, 'kanban', '#E74C3C', 'team', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name);

SELECT 'Phase 3: Productivity Tools installed successfully' as status;