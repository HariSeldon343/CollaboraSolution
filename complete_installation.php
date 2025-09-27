<?php
/**
 * Complete Database Installation - CollaboraNexio
 * Installs missing tables one by one
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Installation - CollaboraNexio</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .progress {
            background: #f0f0f0;
            border-radius: 10px;
            height: 30px;
            overflow: hidden;
            margin-bottom: 30px;
        }
        .progress-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .log {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            max-height: 400px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        .info { color: #17a2b8; }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            margin: 10px 5px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn-success {
            background: #28a745;
        }
        .actions {
            text-align: center;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 Complete CollaboraNexio Installation</h1>

        <div class="progress">
            <div class="progress-bar" id="progressBar" style="width: 0%">0%</div>
        </div>

        <div class="log" id="log">
            <div class="info">Starting installation...</div>
        </div>

        <?php
        ob_flush();
        flush();

        try {
            $db = Database::getInstance();
            $pdo = $db->getConnection();

            // Enable buffered queries
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

            // Disable foreign key checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

            $tables = [];
            $total_tables = 0;
            $created_tables = 0;

            // Core tables that might be missing
            $tables['Core System'] = [
                'teams' => "CREATE TABLE IF NOT EXISTS teams (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    tenant_id INT NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    description TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    INDEX idx_teams_tenant (tenant_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'team_members' => "CREATE TABLE IF NOT EXISTS team_members (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    team_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NOT NULL,
                    role VARCHAR(50) DEFAULT 'member',
                    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                    UNIQUE KEY uk_team_member (team_id, user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'projects' => "CREATE TABLE IF NOT EXISTS projects (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    tenant_id INT NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    description TEXT NULL,
                    status VARCHAR(50) DEFAULT 'active',
                    start_date DATE NULL,
                    end_date DATE NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    INDEX idx_projects_tenant (tenant_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'project_members' => "CREATE TABLE IF NOT EXISTS project_members (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    project_id INT UNSIGNED NOT NULL,
                    user_id INT UNSIGNED NOT NULL,
                    role VARCHAR(50) DEFAULT 'member',
                    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                    UNIQUE KEY uk_project_member (project_id, user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ];

            // Calendar System tables
            $tables['Calendar System'] = [
                'recurring_patterns' => "CREATE TABLE IF NOT EXISTS recurring_patterns (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'calendars' => "CREATE TABLE IF NOT EXISTS calendars (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'calendar_shares' => "CREATE TABLE IF NOT EXISTS calendar_shares (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'events' => "CREATE TABLE IF NOT EXISTS events (
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
                    INDEX idx_event_task (linked_task_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'event_participants' => "CREATE TABLE IF NOT EXISTS event_participants (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'event_reminders' => "CREATE TABLE IF NOT EXISTS event_reminders (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'event_attachments' => "CREATE TABLE IF NOT EXISTS event_attachments (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ];

            // Task Management tables
            $tables['Task Management'] = [
                'task_lists' => "CREATE TABLE IF NOT EXISTS task_lists (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'task_list_columns' => "CREATE TABLE IF NOT EXISTS task_list_columns (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'tasks' => "CREATE TABLE IF NOT EXISTS tasks (
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
                    INDEX idx_task_completed (tenant_id, completed_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'task_dependencies' => "CREATE TABLE IF NOT EXISTS task_dependencies (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'task_comments' => "CREATE TABLE IF NOT EXISTS task_comments (
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
                    INDEX idx_comment_created (task_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'task_attachments' => "CREATE TABLE IF NOT EXISTS task_attachments (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'task_watchers' => "CREATE TABLE IF NOT EXISTS task_watchers (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'time_entries' => "CREATE TABLE IF NOT EXISTS time_entries (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ];

            // Custom Fields tables
            $tables['Custom Fields'] = [
                'custom_fields' => "CREATE TABLE IF NOT EXISTS custom_fields (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

                'custom_field_values' => "CREATE TABLE IF NOT EXISTS custom_field_values (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            ];

            // Count total tables
            foreach ($tables as $module => $module_tables) {
                $total_tables += count($module_tables);
            }

            echo '<script>
                function updateProgress(percent, message) {
                    document.getElementById("progressBar").style.width = percent + "%";
                    document.getElementById("progressBar").textContent = percent + "%";
                    var log = document.getElementById("log");
                    log.innerHTML += "<div>" + message + "</div>";
                    log.scrollTop = log.scrollHeight;
                }
            </script>';

            // Install tables
            foreach ($tables as $module => $module_tables) {
                echo '<script>updateProgress(' . round(($created_tables / $total_tables) * 100) . ', "<div class=\"info\">📦 Installing ' . $module . '...</div>");</script>';
                ob_flush();
                flush();

                foreach ($module_tables as $table_name => $sql) {
                    try {
                        $pdo->exec($sql);
                        $created_tables++;
                        echo '<script>updateProgress(' . round(($created_tables / $total_tables) * 100) . ', "<div class=\"success\">✅ Table ' . $table_name . ' created</div>");</script>';
                    } catch (PDOException $e) {
                        echo '<script>updateProgress(' . round(($created_tables / $total_tables) * 100) . ', "<div class=\"warning\">⚠️ Table ' . $table_name . ': ' . htmlspecialchars($e->getMessage()) . '</div>");</script>';
                    }
                    ob_flush();
                    flush();
                }
            }

            // Re-enable foreign key checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            echo '<script>updateProgress(100, "<div class=\"success\"><strong>✅ Installation complete!</strong></div>");</script>';

            // Add sample data
            echo '<script>updateProgress(100, "<div class=\"info\">📝 Adding sample data...</div>");</script>';

            try {
                // Sample calendars
                $pdo->exec("INSERT IGNORE INTO calendars (tenant_id, name, description, owner_id, color, visibility, is_default, created_by) VALUES
                    (1, 'Company Calendar', 'Main company events and meetings', 1, '#0066CC', 'team', TRUE, 1),
                    (1, 'Personal Calendar', 'Personal appointments and reminders', 1, '#00AA00', 'private', FALSE, 1)");

                // Sample task lists
                $pdo->exec("INSERT IGNORE INTO task_lists (tenant_id, name, description, owner_id, board_type, color, visibility, created_by) VALUES
                    (1, 'Development Sprint', 'Current sprint tasks', 1, 'kanban', '#4A90E2', 'team', 1),
                    (1, 'Bug Tracker', 'Active bugs and issues', 1, 'kanban', '#E74C3C', 'team', 1)");

                echo '<script>updateProgress(100, "<div class=\"success\">✅ Sample data added</div>");</script>';
            } catch (Exception $e) {
                echo '<script>updateProgress(100, "<div class=\"warning\">⚠️ Could not add sample data: ' . htmlspecialchars($e->getMessage()) . '</div>");</script>';
            }

            echo '<div class="actions">';
            echo '<a href="verify_database.php" class="btn btn-success">🔍 Verify Installation</a>';
            echo '<a href="dashboard.php" class="btn">🚀 Go to Dashboard</a>';
            echo '</div>';

        } catch (Exception $e) {
            echo '<script>updateProgress(0, "<div class=\"error\">❌ Fatal error: ' . htmlspecialchars($e->getMessage()) . '</div>");</script>';
        }
        ?>
    </div>
</body>
</html>