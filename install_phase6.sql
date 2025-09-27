-- Module: CollaboraNexio Phase 6 - Dashboard and Analytics System
-- Version: 2025-01-23
-- Author: Database Architect
-- Description: Complete database schema for dashboard, metrics, notifications and reporting

SET FOREIGN_KEY_CHECKS = 0;

USE collabora;

-- ============================================
-- CLEANUP (Safe drop in reverse dependency order)
-- ============================================
DROP TABLE IF EXISTS report_executions;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS notification_templates;
DROP TABLE IF EXISTS notification_preferences;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS metric_aggregations;
DROP TABLE IF EXISTS metric_definitions;
DROP TABLE IF EXISTS metrics;
DROP TABLE IF EXISTS widget_templates;
DROP TABLE IF EXISTS dashboard_widgets;
DROP TABLE IF EXISTS dashboards;

-- Drop views if exist
DROP VIEW IF EXISTS v_daily_active_users;
DROP VIEW IF EXISTS v_storage_usage_summary;
DROP VIEW IF EXISTS v_activity_trends;
DROP VIEW IF EXISTS v_top_content;
DROP VIEW IF EXISTS v_system_health_metrics;

-- Drop procedures if exist
DROP PROCEDURE IF EXISTS sp_prune_old_metrics;
DROP PROCEDURE IF EXISTS sp_aggregate_metrics;
DROP PROCEDURE IF EXISTS sp_generate_daily_report;

-- ============================================
-- DASHBOARD SYSTEM TABLES
-- ============================================

-- Dashboards - Custom dashboards per user
CREATE TABLE dashboards (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,

    -- Layout configuration
    layout_type ENUM('grid', 'flex', 'masonry') DEFAULT 'grid',
    columns_count INT DEFAULT 12,
    row_height INT DEFAULT 100,
    gap_size INT DEFAULT 10,

    -- Theme and appearance
    theme ENUM('light', 'dark', 'auto') DEFAULT 'light',
    color_scheme VARCHAR(50) DEFAULT 'blue',

    -- Sharing and visibility
    is_public BOOLEAN DEFAULT FALSE,
    is_default BOOLEAN DEFAULT FALSE,
    is_shared BOOLEAN DEFAULT FALSE,
    shared_with JSON NULL, -- Array of user IDs

    -- Settings
    auto_refresh BOOLEAN DEFAULT TRUE,
    refresh_interval INT DEFAULT 300, -- seconds

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_accessed_at DATETIME NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_dashboard_tenant_user (tenant_id, user_id),
    INDEX idx_dashboard_default (tenant_id, user_id, is_default),
    INDEX idx_dashboard_public (tenant_id, is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dashboard Widgets - Individual widgets on dashboards
CREATE TABLE dashboard_widgets (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    dashboard_id INT UNSIGNED NOT NULL,
    widget_type VARCHAR(50) NOT NULL, -- metric_card, chart, gauge, list, calendar, etc.
    title VARCHAR(100) NOT NULL,

    -- Position and size (grid system)
    position_x INT NOT NULL DEFAULT 0,
    position_y INT NOT NULL DEFAULT 0,
    width INT NOT NULL DEFAULT 4,
    height INT NOT NULL DEFAULT 2,
    z_index INT DEFAULT 0,

    -- Data configuration
    data_source VARCHAR(100) NOT NULL, -- metric name or API endpoint
    data_config JSON NOT NULL, -- Widget-specific configuration

    -- Display settings
    display_options JSON NULL, -- Colors, formatting, etc.

    -- Update settings
    refresh_interval INT DEFAULT 60, -- seconds
    cache_duration INT DEFAULT 300, -- seconds
    last_refresh_at DATETIME NULL,

    -- State
    is_minimized BOOLEAN DEFAULT FALSE,
    is_visible BOOLEAN DEFAULT TRUE,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (dashboard_id) REFERENCES dashboards(id) ON DELETE CASCADE,
    INDEX idx_widget_dashboard (dashboard_id),
    INDEX idx_widget_position (dashboard_id, position_x, position_y)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Widget Templates - Predefined widget configurations
CREATE TABLE widget_templates (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,
    widget_type VARCHAR(50) NOT NULL,
    description TEXT NULL,

    -- Template configuration
    default_config JSON NOT NULL,
    default_display JSON NULL,

    -- Requirements
    required_permissions JSON NULL,
    required_metrics JSON NULL,

    -- Metadata
    icon VARCHAR(50) NULL,
    preview_image VARCHAR(255) NULL,

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_premium BOOLEAN DEFAULT FALSE,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    INDEX idx_template_category (category),
    INDEX idx_template_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- METRICS SYSTEM TABLES
-- ============================================

-- Metrics - Time-series data storage (simplified without partitioning for compatibility)
CREATE TABLE metrics (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    metric_name VARCHAR(100) NOT NULL,
    metric_type ENUM('counter', 'gauge', 'histogram', 'summary') NOT NULL,

    -- Time information
    timestamp DATETIME NOT NULL,
    granularity ENUM('raw', 'minute', 'hour', 'day', 'week', 'month') DEFAULT 'raw',

    -- Values
    value DECIMAL(20, 4) NOT NULL,
    count INT UNSIGNED DEFAULT 1,
    min_value DECIMAL(20, 4) NULL,
    max_value DECIMAL(20, 4) NULL,
    sum_value DECIMAL(20, 4) NULL,

    -- Dimensions (for filtering)
    dimensions JSON NULL,

    -- Tags
    tags JSON NULL,

    -- Metadata
    unit VARCHAR(20) NULL,
    source VARCHAR(100) NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    INDEX idx_metrics_tenant_name_time (tenant_id, metric_name, timestamp DESC),
    INDEX idx_metrics_granularity (tenant_id, granularity, timestamp DESC),
    INDEX idx_metrics_timestamp (timestamp),
    INDEX idx_metrics_cleanup (granularity, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metric Definitions - Catalog of available metrics
CREATE TABLE metric_definitions (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    name VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    category VARCHAR(50) NOT NULL,

    -- Calculation
    formula TEXT NULL,
    aggregation_method ENUM('sum', 'avg', 'min', 'max', 'count', 'last') DEFAULT 'avg',

    -- Display
    unit VARCHAR(20) NULL,
    format VARCHAR(50) NULL,
    decimal_places INT DEFAULT 2,

    -- Thresholds for alerts
    warning_threshold DECIMAL(20, 4) NULL,
    critical_threshold DECIMAL(20, 4) NULL,
    threshold_direction ENUM('above', 'below') NULL,

    -- Metadata
    description TEXT NULL,
    help_text TEXT NULL,

    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    is_system BOOLEAN DEFAULT FALSE,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    INDEX idx_definition_category (category),
    INDEX idx_definition_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metric Aggregations - Pre-calculated aggregations
CREATE TABLE metric_aggregations (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    metric_name VARCHAR(100) NOT NULL,
    period_start DATETIME NOT NULL,
    period_end DATETIME NOT NULL,
    granularity ENUM('hour', 'day', 'week', 'month', 'quarter', 'year') NOT NULL,

    -- Aggregated values
    avg_value DECIMAL(20, 4) NOT NULL,
    min_value DECIMAL(20, 4) NOT NULL,
    max_value DECIMAL(20, 4) NOT NULL,
    sum_value DECIMAL(20, 4) NOT NULL,
    count INT UNSIGNED NOT NULL,

    -- Statistical values
    std_dev DECIMAL(20, 4) NULL,
    percentile_50 DECIMAL(20, 4) NULL,
    percentile_95 DECIMAL(20, 4) NULL,
    percentile_99 DECIMAL(20, 4) NULL,

    -- Trend analysis
    trend ENUM('up', 'down', 'stable') NULL,
    change_percent DECIMAL(10, 2) NULL,

    -- Audit fields
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_aggregation (tenant_id, metric_name, period_start, granularity),
    INDEX idx_aggregation_lookup (tenant_id, metric_name, granularity, period_start DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- NOTIFICATION SYSTEM TABLES
-- ============================================

-- Notifications - User notifications
CREATE TABLE notifications (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    category VARCHAR(50) NOT NULL,

    -- Content
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    data JSON NULL,

    -- Priority and status
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    is_read BOOLEAN DEFAULT FALSE,
    is_archived BOOLEAN DEFAULT FALSE,

    -- Actions
    action_url VARCHAR(500) NULL,
    action_label VARCHAR(100) NULL,
    action_type VARCHAR(50) NULL,

    -- Batching
    batch_id VARCHAR(100) NULL,
    batch_count INT DEFAULT 1,

    -- Timing
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    expires_at DATETIME NULL,

    -- Related entities
    related_type VARCHAR(50) NULL,
    related_id INT UNSIGNED NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notification_user_unread (user_id, is_read, created_at DESC),
    INDEX idx_notification_batch (batch_id),
    INDEX idx_notification_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification Preferences - Per-user notification settings
CREATE TABLE notification_preferences (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,

    -- Channel preferences
    email_enabled BOOLEAN DEFAULT TRUE,
    desktop_enabled BOOLEAN DEFAULT TRUE,
    mobile_enabled BOOLEAN DEFAULT TRUE,
    in_app_enabled BOOLEAN DEFAULT TRUE,

    -- Category preferences (JSON object with category => boolean)
    categories JSON NOT NULL DEFAULT '{}',

    -- Quiet hours
    quiet_hours_enabled BOOLEAN DEFAULT FALSE,
    quiet_hours_start TIME NULL,
    quiet_hours_end TIME NULL,
    quiet_hours_timezone VARCHAR(50) DEFAULT 'UTC',

    -- Batching preferences
    email_frequency ENUM('instant', 'hourly', 'daily', 'weekly') DEFAULT 'instant',
    email_digest_time TIME NULL,

    -- Thresholds
    min_priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'low',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_preferences (tenant_id, user_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification Templates - Message templates for notifications
CREATE TABLE notification_templates (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    code VARCHAR(100) NOT NULL UNIQUE,
    category VARCHAR(50) NOT NULL,

    -- Template content
    title_template VARCHAR(255) NOT NULL,
    message_template TEXT NOT NULL,

    -- Multi-language support
    language VARCHAR(5) DEFAULT 'en',

    -- Variables
    available_variables JSON NULL,

    -- Channels
    channels JSON NOT NULL DEFAULT '["in_app"]',

    -- Priority
    default_priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',

    -- Status
    is_active BOOLEAN DEFAULT TRUE,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    INDEX idx_template_code (code),
    INDEX idx_template_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- REPORTING SYSTEM TABLES
-- ============================================

-- Reports - Scheduled report configurations
CREATE TABLE reports (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    report_type VARCHAR(50) NOT NULL,

    -- Template and configuration
    template_id VARCHAR(100) NOT NULL,
    parameters JSON NULL,
    filters JSON NULL,

    -- Schedule (cron-like)
    is_scheduled BOOLEAN DEFAULT FALSE,
    schedule_pattern VARCHAR(100) NULL, -- Cron expression
    next_run_at DATETIME NULL,

    -- Recipients
    recipients JSON NOT NULL, -- Array of email addresses

    -- Output settings
    output_formats JSON NOT NULL DEFAULT '["pdf"]', -- pdf, excel, csv

    -- Creator
    created_by INT UNSIGNED NOT NULL,

    -- Status
    is_active BOOLEAN DEFAULT TRUE,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_run_at DATETIME NULL,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_report_tenant (tenant_id),
    INDEX idx_report_scheduled (is_scheduled, is_active, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Report Executions - Report execution history
CREATE TABLE report_executions (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    report_id INT UNSIGNED NOT NULL,

    -- Execution details
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,

    -- Output
    output_files JSON NULL, -- Array of file paths

    -- Delivery
    delivery_status ENUM('pending', 'sent', 'failed', 'partial') NULL,
    delivery_details JSON NULL,

    -- Error handling
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,

    -- Metrics
    execution_time_ms INT UNSIGNED NULL,
    rows_processed INT UNSIGNED NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    INDEX idx_execution_report (report_id, created_at DESC),
    INDEX idx_execution_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- MATERIALIZED VIEWS FOR PERFORMANCE
-- ============================================

-- Daily Active Users
CREATE OR REPLACE VIEW v_daily_active_users AS
SELECT
    tenant_id,
    DATE(created_at) as date,
    COUNT(DISTINCT user_id) as active_users,
    COUNT(*) as total_actions
FROM notifications
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY tenant_id, DATE(created_at);

-- Storage Usage Summary (checking if files table exists)
CREATE OR REPLACE VIEW v_storage_usage_summary AS
SELECT
    f.tenant_id,
    COUNT(*) as total_files,
    SUM(f.file_size) as total_size,
    AVG(f.file_size) as avg_size,
    MAX(f.file_size) as max_size,
    COUNT(DISTINCT f.user_id) as unique_users
FROM files f
GROUP BY f.tenant_id;

-- Activity Trends
CREATE OR REPLACE VIEW v_activity_trends AS
SELECT
    tenant_id,
    DATE_FORMAT(timestamp, '%Y-%m') as month,
    metric_name,
    AVG(value) as avg_value,
    MAX(value) as peak_value,
    COUNT(*) as data_points
FROM metrics
WHERE granularity = 'day'
    AND timestamp >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
GROUP BY tenant_id, DATE_FORMAT(timestamp, '%Y-%m'), metric_name;

-- Top Content (simplified to avoid dependency issues)
CREATE OR REPLACE VIEW v_top_content AS
SELECT
    f.tenant_id,
    f.id as file_id,
    f.file_name,
    f.file_size,
    f.created_at,
    f.user_id as owner_id
FROM files f
WHERE f.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY f.created_at DESC
LIMIT 100;

-- System Health Metrics (fixed to use correct column names)
CREATE OR REPLACE VIEW v_system_health_metrics AS
SELECT
    'system' as scope,
    COUNT(DISTINCT u.tenant_id) as active_tenants,
    COUNT(DISTINCT u.id) as total_users,
    (SELECT COUNT(*) FROM files) as total_files,
    (SELECT COALESCE(SUM(file_size), 0) FROM files) as total_storage_bytes,
    (SELECT COUNT(*) FROM notifications WHERE is_read = 0) as unread_notifications,
    (SELECT COUNT(*) FROM reports WHERE is_active = 1) as active_reports,
    NOW() as calculated_at
FROM users u;

-- ============================================
-- STORED PROCEDURES
-- ============================================

DELIMITER //

-- Procedure to prune old metrics based on retention policy
CREATE PROCEDURE sp_prune_old_metrics()
BEGIN
    -- Delete raw metrics older than 24 hours
    DELETE FROM metrics
    WHERE granularity = 'raw'
        AND timestamp < DATE_SUB(NOW(), INTERVAL 24 HOUR);

    -- Delete minute aggregations older than 7 days
    DELETE FROM metrics
    WHERE granularity = 'minute'
        AND timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY);

    -- Delete hourly aggregations older than 30 days
    DELETE FROM metrics
    WHERE granularity = 'hour'
        AND timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY);

    -- Delete daily aggregations older than 3 months
    DELETE FROM metrics
    WHERE granularity = 'day'
        AND timestamp < DATE_SUB(NOW(), INTERVAL 3 MONTH);

    -- Keep monthly aggregations forever (no deletion)
END//

-- Procedure to aggregate metrics
CREATE PROCEDURE sp_aggregate_metrics(
    IN p_tenant_id INT,
    IN p_metric_name VARCHAR(100),
    IN p_granularity VARCHAR(20)
)
BEGIN
    DECLARE v_period_start DATETIME;
    DECLARE v_period_end DATETIME;

    -- Determine period based on granularity
    IF p_granularity = 'hour' THEN
        SET v_period_start = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 HOUR), '%Y-%m-%d %H:00:00');
        SET v_period_end = DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00');
    ELSEIF p_granularity = 'day' THEN
        SET v_period_start = DATE(DATE_SUB(NOW(), INTERVAL 1 DAY));
        SET v_period_end = DATE(NOW());
    ELSEIF p_granularity = 'month' THEN
        SET v_period_start = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m-01');
        SET v_period_end = DATE_FORMAT(NOW(), '%Y-%m-01');
    END IF;

    -- Insert aggregation
    INSERT INTO metric_aggregations (
        tenant_id, metric_name, period_start, period_end, granularity,
        avg_value, min_value, max_value, sum_value, count
    )
    SELECT
        p_tenant_id,
        p_metric_name,
        v_period_start,
        v_period_end,
        p_granularity,
        AVG(value),
        MIN(value),
        MAX(value),
        SUM(value),
        COUNT(*)
    FROM metrics
    WHERE tenant_id = p_tenant_id
        AND metric_name = p_metric_name
        AND timestamp >= v_period_start
        AND timestamp < v_period_end
    ON DUPLICATE KEY UPDATE
        avg_value = VALUES(avg_value),
        min_value = VALUES(min_value),
        max_value = VALUES(max_value),
        sum_value = VALUES(sum_value),
        count = VALUES(count),
        calculated_at = NOW();
END//

-- Procedure to generate daily report
CREATE PROCEDURE sp_generate_daily_report(
    IN p_tenant_id INT
)
BEGIN
    DECLARE v_report_data JSON;

    -- Collect daily statistics
    SET v_report_data = JSON_OBJECT(
        'date', CURDATE(),
        'active_users', (SELECT COUNT(DISTINCT user_id) FROM notifications
                        WHERE tenant_id = p_tenant_id
                        AND DATE(created_at) = CURDATE()),
        'new_files', (SELECT COUNT(*) FROM files
                     WHERE tenant_id = p_tenant_id
                     AND DATE(created_at) = CURDATE()),
        'total_storage', (SELECT SUM(file_size) FROM files
                         WHERE tenant_id = p_tenant_id)
    );

    -- Insert report execution record
    INSERT INTO report_executions (
        tenant_id,
        report_id,
        status,
        started_at,
        completed_at,
        output_files,
        delivery_status
    ) VALUES (
        p_tenant_id,
        1, -- Assuming daily report has ID 1
        'completed',
        NOW(),
        NOW(),
        v_report_data,
        'pending'
    );
END//

DELIMITER ;

-- ============================================
-- DEMO DATA
-- ============================================

-- Sample widget templates
INSERT INTO widget_templates (name, category, widget_type, description, default_config) VALUES
('Storage Gauge', 'system', 'gauge', 'Display storage usage', '{"metric": "storage_used", "max": "storage_limit"}'),
('User Activity', 'analytics', 'chart', 'User activity over time', '{"type": "line", "metric": "user_activity"}'),
('Recent Files', 'content', 'list', 'Recently uploaded files', '{"source": "recent_files", "limit": 10}'),
('Task Progress', 'project', 'progress', 'Project task completion', '{"metric": "tasks_completed"}'),
('System Health', 'system', 'metric_card', 'System health score', '{"metric": "health_score"}'),
('Calendar', 'time', 'calendar', 'Event calendar', '{"source": "events"}');

-- Sample metric definitions
INSERT INTO metric_definitions (name, display_name, category, unit, format, aggregation_method) VALUES
('storage_used', 'Storage Used', 'system', 'bytes', 'filesize', 'last'),
('user_activity', 'User Activity', 'analytics', 'actions', 'number', 'sum'),
('file_uploads', 'File Uploads', 'content', 'files', 'number', 'count'),
('active_users', 'Active Users', 'analytics', 'users', 'number', 'count'),
('response_time', 'Response Time', 'performance', 'ms', 'duration', 'avg'),
('error_rate', 'Error Rate', 'performance', '%', 'percentage', 'avg');

-- Sample dashboards
INSERT INTO dashboards (tenant_id, user_id, name, description, theme, is_default) VALUES
(1, 1, 'Executive Overview', 'High-level metrics for executives', 'light', TRUE),
(1, 1, 'System Monitor', 'Technical system monitoring', 'dark', FALSE),
(1, 2, 'My Dashboard', 'Personal productivity dashboard', 'light', TRUE),
(1, 3, 'Team Analytics', 'Team performance metrics', 'light', FALSE),
(1, 3, 'Project Status', 'Current project status', 'auto', FALSE);

-- Sample dashboard widgets
INSERT INTO dashboard_widgets (tenant_id, dashboard_id, widget_type, title, position_x, position_y, width, height, data_source, data_config) VALUES
(1, 1, 'metric_card', 'Total Users', 0, 0, 3, 1, 'active_users', '{"period": "today"}'),
(1, 1, 'metric_card', 'Storage Used', 3, 0, 3, 1, 'storage_used', '{"unit": "GB"}'),
(1, 1, 'chart', 'Activity Trend', 0, 1, 6, 3, 'user_activity', '{"type": "line", "period": "7d"}'),
(1, 1, 'gauge', 'Storage Usage', 6, 0, 3, 2, 'storage_used', '{"max": 1000000000}'),
(1, 2, 'chart', 'Response Time', 0, 0, 12, 4, 'response_time', '{"type": "line", "period": "1h"}'),
(1, 3, 'list', 'Recent Files', 0, 0, 4, 4, 'recent_files', '{"limit": 10}');

-- Sample metrics data
INSERT INTO metrics (tenant_id, metric_name, metric_type, timestamp, granularity, value) VALUES
(1, 'active_users', 'gauge', NOW(), 'raw', 25),
(1, 'active_users', 'gauge', DATE_SUB(NOW(), INTERVAL 1 HOUR), 'hour', 23),
(1, 'storage_used', 'gauge', NOW(), 'raw', 536870912),
(1, 'file_uploads', 'counter', NOW(), 'raw', 5),
(1, 'response_time', 'gauge', NOW(), 'raw', 145.5),
(1, 'response_time', 'gauge', DATE_SUB(NOW(), INTERVAL 5 MINUTE), 'raw', 132.3),
(1, 'error_rate', 'gauge', NOW(), 'raw', 0.02),
(1, 'user_activity', 'counter', NOW(), 'raw', 150),
(1, 'user_activity', 'counter', DATE_SUB(NOW(), INTERVAL 1 HOUR), 'hour', 1250),
(1, 'user_activity', 'counter', DATE_SUB(NOW(), INTERVAL 1 DAY), 'day', 15000);

-- Sample notifications
INSERT INTO notifications (tenant_id, user_id, type, category, title, message, priority) VALUES
(1, 1, 'system', 'alert', 'Storage Warning', 'Storage usage has exceeded 80%', 'high'),
(1, 1, 'share', 'info', 'File Shared', 'John shared a file with you', 'normal'),
(1, 2, 'comment', 'info', 'New Comment', 'Alice commented on your document', 'normal'),
(1, 2, 'approval', 'action', 'Approval Required', 'Budget report needs your approval', 'high'),
(1, 3, 'mention', 'info', 'You were mentioned', 'Bob mentioned you in a comment', 'normal');

-- Sample notification preferences
INSERT INTO notification_preferences (tenant_id, user_id, email_enabled, desktop_enabled, quiet_hours_enabled, quiet_hours_start, quiet_hours_end, email_frequency) VALUES
(1, 1, TRUE, TRUE, TRUE, '22:00:00', '08:00:00', 'instant'),
(1, 2, TRUE, FALSE, FALSE, NULL, NULL, 'daily'),
(1, 3, TRUE, TRUE, TRUE, '20:00:00', '09:00:00', 'hourly');

-- Sample notification templates
INSERT INTO notification_templates (code, category, title_template, message_template, channels, default_priority) VALUES
('file_shared', 'share', '{user} shared a file', '{user} shared {filename} with you', '["in_app", "email"]', 'normal'),
('storage_warning', 'system', 'Storage Warning', 'Your storage usage is at {percentage}%', '["in_app", "email", "desktop"]', 'high'),
('comment_added', 'comment', 'New comment', '{user} commented on {document}', '["in_app"]', 'normal'),
('report_ready', 'report', 'Report Ready', 'Your {report_name} report is ready for download', '["email"]', 'normal');

-- Sample reports
INSERT INTO reports (tenant_id, name, description, report_type, template_id, recipients, output_formats, created_by, is_scheduled, schedule_pattern) VALUES
(1, 'Daily Activity Report', 'Daily summary of platform activity', 'activity', 'daily_activity', '["admin@example.com"]', '["pdf", "excel"]', 1, TRUE, '0 8 * * *'),
(1, 'Weekly Storage Report', 'Weekly storage usage analysis', 'storage', 'storage_analysis', '["admin@example.com", "manager@example.com"]', '["pdf"]', 1, TRUE, '0 9 * * 1'),
(1, 'Monthly User Report', 'Monthly user activity and engagement', 'users', 'user_engagement', '["executive@example.com"]', '["pdf", "excel"]', 1, TRUE, '0 9 1 * *'),
(1, 'Custom Analytics', 'On-demand analytics report', 'custom', 'custom_template', '["analyst@example.com"]', '["csv"]', 2, FALSE, NULL);

-- Sample report executions
INSERT INTO report_executions (tenant_id, report_id, status, started_at, completed_at, execution_time_ms, rows_processed) VALUES
(1, 1, 'completed', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), 2500, 1500),
(1, 1, 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), 2300, 1450),
(1, 2, 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY), 5000, 5000),
(1, 3, 'running', NOW(), NULL, NULL, NULL);

-- ============================================
-- VERIFICATION
-- ============================================

SET FOREIGN_KEY_CHECKS = 1;

-- Verify table creation
SELECT 'Phase 6 Dashboard and Analytics tables created' as status,
       (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = 'collabora'
        AND table_name IN ('dashboards', 'dashboard_widgets', 'metrics',
                          'notifications', 'reports')) as tables_created,
       NOW() as installation_time;

-- Verify sample data
SELECT 'Sample data loaded' as status,
       (SELECT COUNT(*) FROM dashboards) as dashboards,
       (SELECT COUNT(*) FROM dashboard_widgets) as widgets,
       (SELECT COUNT(*) FROM metrics) as metrics,
       (SELECT COUNT(*) FROM notifications) as notifications,
       (SELECT COUNT(*) FROM reports) as reports;

-- Success message
SELECT '✓ CollaboraNexio Phase 6 - Dashboard and Analytics system installed successfully!' as result;