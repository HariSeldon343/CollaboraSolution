-- Module: CollaboraNexio Phase 6 - Dashboard and Analytics System
-- Version: 2025-01-23
-- Author: Database Architect
-- Description: Complete dashboard, analytics, metrics, notifications, and reporting system with multi-tenant support

USE collabora;

-- ============================================
-- SAFETY SETTINGS
-- ============================================
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- ============================================
-- CLEANUP (Development only - reverse dependency order)
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

-- Dashboards table - Support multiple custom dashboards per user
CREATE TABLE IF NOT EXISTS dashboards (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_default BOOLEAN DEFAULT FALSE,
    layout_config JSON COMMENT 'Grid system configuration',
    theme VARCHAR(20) DEFAULT 'light' COMMENT 'light, dark, auto',
    grid_columns INT DEFAULT 12,
    row_height INT DEFAULT 60,

    -- Sharing capabilities
    is_public BOOLEAN DEFAULT FALSE,
    share_token VARCHAR(64) DEFAULT NULL,
    shared_with JSON COMMENT 'Array of user IDs with access',

    -- Display preferences
    auto_refresh_interval INT DEFAULT 0 COMMENT 'Seconds, 0 = disabled',
    timezone VARCHAR(50) DEFAULT 'UTC',

    -- Status and metadata
    status ENUM('active', 'archived', 'deleted') DEFAULT 'active',
    last_accessed TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_share_token (share_token),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_dashboard_tenant_user (tenant_id, user_id, status),
    INDEX idx_dashboard_default (tenant_id, user_id, is_default),
    INDEX idx_dashboard_public (is_public, status),
    INDEX idx_dashboard_accessed (last_accessed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dashboard widgets table - Widget configuration and positioning
CREATE TABLE IF NOT EXISTS dashboard_widgets (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    dashboard_id INT UNSIGNED NOT NULL,
    widget_type VARCHAR(50) NOT NULL COMMENT 'chart, metric, table, map, calendar, etc',
    title VARCHAR(100) NOT NULL,
    description TEXT,

    -- Grid positioning (for grid layout system)
    grid_x INT DEFAULT 0,
    grid_y INT DEFAULT 0,
    grid_width INT DEFAULT 4,
    grid_height INT DEFAULT 4,

    -- Data configuration
    data_source VARCHAR(100) COMMENT 'Table or API endpoint',
    data_query TEXT COMMENT 'SQL or API query',
    data_filters JSON COMMENT 'Filter configuration',

    -- Widget settings
    config JSON COMMENT 'Widget-specific configuration',
    refresh_interval INT DEFAULT 300 COMMENT 'Seconds',
    cache_duration INT DEFAULT 60 COMMENT 'Seconds',

    -- Visual settings
    color_scheme VARCHAR(50) DEFAULT 'default',
    show_header BOOLEAN DEFAULT TRUE,
    show_border BOOLEAN DEFAULT TRUE,

    -- Status
    is_visible BOOLEAN DEFAULT TRUE,
    sort_order INT DEFAULT 0,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (dashboard_id) REFERENCES dashboards(id) ON DELETE CASCADE,
    INDEX idx_widget_tenant_dashboard (tenant_id, dashboard_id, is_visible),
    INDEX idx_widget_type (widget_type),
    INDEX idx_widget_order (dashboard_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Widget templates table - Predefined widget configurations
CREATE TABLE IF NOT EXISTS widget_templates (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50) NOT NULL COMMENT 'analytics, monitoring, reporting, custom',
    widget_type VARCHAR(50) NOT NULL,

    -- Template configuration
    default_config JSON NOT NULL COMMENT 'Default widget configuration',
    default_width INT DEFAULT 4,
    default_height INT DEFAULT 4,

    -- Permissions
    required_permission VARCHAR(100) COMMENT 'Permission needed to use this template',
    is_system BOOLEAN DEFAULT FALSE COMMENT 'System templates cannot be modified',
    is_public BOOLEAN DEFAULT TRUE COMMENT 'Available to all users',

    -- Preview
    thumbnail_url VARCHAR(255),
    preview_data JSON COMMENT 'Sample data for preview',

    -- Usage tracking
    usage_count INT DEFAULT 0,

    -- Status
    status ENUM('active', 'deprecated', 'archived') DEFAULT 'active',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_template_tenant_category (tenant_id, category, status),
    INDEX idx_template_type (widget_type, status),
    INDEX idx_template_public (is_public, status),
    INDEX idx_template_usage (usage_count DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- METRICS COLLECTION SYSTEM
-- ============================================

-- Metrics table - Time-series data storage
CREATE TABLE IF NOT EXISTS metrics (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    metric_name VARCHAR(100) NOT NULL,
    metric_type ENUM('counter', 'gauge', 'histogram', 'summary') NOT NULL,
    value DECIMAL(20, 4) NOT NULL,

    -- Dimensions (for filtering and grouping)
    dimensions JSON COMMENT 'Key-value pairs for metric dimensions',

    -- Time information
    timestamp TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    aggregation_level ENUM('raw', 'minute', 'hour', 'day', 'week', 'month') DEFAULT 'raw',

    -- Additional metadata
    tags JSON COMMENT 'Additional tags for filtering',
    source VARCHAR(100) COMMENT 'Source system or component',

    -- Data retention
    retention_days INT DEFAULT 90,
    expires_at TIMESTAMP NULL DEFAULT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_metrics_tenant_name_time (tenant_id, metric_name, timestamp DESC),
    INDEX idx_metrics_type_time (metric_type, timestamp DESC),
    INDEX idx_metrics_aggregation (aggregation_level, timestamp DESC),
    INDEX idx_metrics_expires (expires_at),
    INDEX idx_metrics_source (source, timestamp DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (UNIX_TIMESTAMP(timestamp)) (
    PARTITION p_2025_01 VALUES LESS THAN (UNIX_TIMESTAMP('2025-02-01')),
    PARTITION p_2025_02 VALUES LESS THAN (UNIX_TIMESTAMP('2025-03-01')),
    PARTITION p_2025_03 VALUES LESS THAN (UNIX_TIMESTAMP('2025-04-01')),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Metric definitions table - Available metrics catalog
CREATE TABLE IF NOT EXISTS metric_definitions (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    metric_name VARCHAR(100) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    category VARCHAR(50) NOT NULL,

    -- Metric configuration
    metric_type ENUM('counter', 'gauge', 'histogram', 'summary') NOT NULL,
    unit VARCHAR(20) COMMENT 'bytes, seconds, requests, percent, etc',
    calculation_formula TEXT COMMENT 'SQL or expression for calculation',

    -- Display configuration
    format_pattern VARCHAR(50) COMMENT 'Number format pattern',
    decimal_places INT DEFAULT 2,
    prefix VARCHAR(10) COMMENT 'Display prefix',
    suffix VARCHAR(10) COMMENT 'Display suffix',

    -- Thresholds for alerts
    warning_threshold DECIMAL(20, 4),
    critical_threshold DECIMAL(20, 4),
    threshold_direction ENUM('above', 'below') DEFAULT 'above',

    -- Collection settings
    collection_interval INT DEFAULT 60 COMMENT 'Seconds',
    retention_days INT DEFAULT 90,
    is_active BOOLEAN DEFAULT TRUE,

    -- Aggregation rules
    aggregation_method ENUM('sum', 'avg', 'min', 'max', 'count', 'last') DEFAULT 'avg',
    can_aggregate BOOLEAN DEFAULT TRUE,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_metric_definition (tenant_id, metric_name),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_metric_def_category (tenant_id, category, is_active),
    INDEX idx_metric_def_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Metric aggregations table - Pre-calculated aggregations
CREATE TABLE IF NOT EXISTS metric_aggregations (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    metric_name VARCHAR(100) NOT NULL,
    aggregation_type ENUM('sum', 'avg', 'min', 'max', 'count', 'p50', 'p95', 'p99') NOT NULL,
    aggregation_level ENUM('minute', 'hour', 'day', 'week', 'month') NOT NULL,

    -- Aggregated values
    value DECIMAL(20, 4) NOT NULL,
    sample_count INT NOT NULL,
    min_value DECIMAL(20, 4),
    max_value DECIMAL(20, 4),
    sum_value DECIMAL(20, 4),

    -- Time period
    period_start TIMESTAMP NOT NULL,
    period_end TIMESTAMP NOT NULL,

    -- Dimensions
    dimensions JSON COMMENT 'Aggregated dimensions',

    -- Trend calculation
    previous_value DECIMAL(20, 4),
    trend_direction ENUM('up', 'down', 'stable') GENERATED ALWAYS AS (
        CASE
            WHEN previous_value IS NULL THEN 'stable'
            WHEN value > previous_value THEN 'up'
            WHEN value < previous_value THEN 'down'
            ELSE 'stable'
        END
    ) STORED,
    trend_percentage DECIMAL(10, 2) GENERATED ALWAYS AS (
        CASE
            WHEN previous_value IS NULL OR previous_value = 0 THEN 0
            ELSE ((value - previous_value) / previous_value * 100)
        END
    ) STORED,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_metric_aggregation (tenant_id, metric_name, aggregation_type, aggregation_level, period_start),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_agg_tenant_metric_period (tenant_id, metric_name, period_start DESC),
    INDEX idx_agg_level_period (aggregation_level, period_start DESC),
    INDEX idx_agg_type (aggregation_type, period_start DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- NOTIFICATION SYSTEM
-- ============================================

-- Notifications table - User notifications with priority levels
CREATE TABLE IF NOT EXISTS notifications (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL COMMENT 'info, warning, error, success, task, mention, etc',
    category VARCHAR(50) NOT NULL COMMENT 'system, project, task, document, etc',

    -- Message content
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    icon VARCHAR(50),

    -- Priority and status
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL DEFAULT NULL,

    -- Action configuration
    action_url VARCHAR(500),
    action_label VARCHAR(100),
    action_data JSON COMMENT 'Additional data for the action',

    -- Batching support
    batch_id VARCHAR(64) COMMENT 'For grouping related notifications',
    is_bundled BOOLEAN DEFAULT FALSE,

    -- Expiration
    expires_at TIMESTAMP NULL DEFAULT NULL,

    -- Delivery status
    delivered_at TIMESTAMP NULL DEFAULT NULL,
    delivery_channels JSON COMMENT 'Channels where delivered (email, desktop, in-app)',

    -- Source information
    source_type VARCHAR(50) COMMENT 'Type of source entity',
    source_id INT COMMENT 'ID of source entity',
    triggered_by INT UNSIGNED COMMENT 'User who triggered the notification',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notif_tenant_user_unread (tenant_id, user_id, is_read, created_at DESC),
    INDEX idx_notif_priority (priority, is_read, created_at DESC),
    INDEX idx_notif_category (category, created_at DESC),
    INDEX idx_notif_batch (batch_id),
    INDEX idx_notif_expires (expires_at),
    INDEX idx_notif_source (source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification preferences table - Per-user preferences
CREATE TABLE IF NOT EXISTS notification_preferences (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    user_id INT UNSIGNED NOT NULL,

    -- Category settings (JSON object with category -> boolean)
    category_settings JSON NOT NULL DEFAULT '{}' COMMENT 'Per-category enabled/disabled',

    -- Channel preferences
    email_enabled BOOLEAN DEFAULT TRUE,
    desktop_enabled BOOLEAN DEFAULT TRUE,
    in_app_enabled BOOLEAN DEFAULT TRUE,
    sms_enabled BOOLEAN DEFAULT FALSE,

    -- Email digest settings
    email_digest ENUM('none', 'immediate', 'hourly', 'daily', 'weekly') DEFAULT 'immediate',
    digest_time TIME DEFAULT '09:00:00' COMMENT 'Time for daily digest',
    digest_day INT DEFAULT 1 COMMENT 'Day of week for weekly digest (1=Monday)',

    -- Quiet hours configuration
    quiet_hours_enabled BOOLEAN DEFAULT FALSE,
    quiet_hours_start TIME DEFAULT '22:00:00',
    quiet_hours_end TIME DEFAULT '08:00:00',
    quiet_hours_timezone VARCHAR(50) DEFAULT 'UTC',

    -- Priority filters
    min_priority_email ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    min_priority_desktop ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    min_priority_in_app ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'low',

    -- Bundling preferences
    bundle_similar BOOLEAN DEFAULT TRUE,
    bundle_interval INT DEFAULT 300 COMMENT 'Seconds to wait before bundling',

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_notif_pref_user (tenant_id, user_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notif_pref_digest (email_digest, digest_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification templates table - Message templates
CREATE TABLE IF NOT EXISTS notification_templates (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    code VARCHAR(50) NOT NULL COMMENT 'Unique template code',
    name VARCHAR(100) NOT NULL,
    description TEXT,
    type VARCHAR(50) NOT NULL,
    category VARCHAR(50) NOT NULL,

    -- Template content (with variable substitution)
    title_template VARCHAR(200) NOT NULL COMMENT 'Title with {{variables}}',
    message_template TEXT NOT NULL COMMENT 'Message with {{variables}}',

    -- Email specific templates
    email_subject_template VARCHAR(200),
    email_body_template TEXT,
    email_body_html_template TEXT,

    -- Multi-language support
    language VARCHAR(5) DEFAULT 'en' COMMENT 'Language code',

    -- Variables definition
    variables JSON COMMENT 'List of available variables and their descriptions',

    -- Default values
    default_priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    default_icon VARCHAR(50),
    default_action_label VARCHAR(100),

    -- Settings
    is_system BOOLEAN DEFAULT FALSE COMMENT 'System templates cannot be modified',
    is_active BOOLEAN DEFAULT TRUE,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT UNSIGNED,

    -- Constraints
    PRIMARY KEY (id),
    UNIQUE KEY uk_notif_template (tenant_id, code, language),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notif_template_type (type, category, is_active),
    INDEX idx_notif_template_lang (language, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- REPORTING SYSTEM
-- ============================================

-- Reports table - Scheduled reports configuration
CREATE TABLE IF NOT EXISTS reports (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    name VARCHAR(100) NOT NULL,
    description TEXT,
    report_type VARCHAR(50) NOT NULL COMMENT 'dashboard, custom, system, analytics',

    -- Source configuration
    dashboard_id INT UNSIGNED COMMENT 'If report_type = dashboard',
    custom_query TEXT COMMENT 'SQL query for custom reports',
    data_sources JSON COMMENT 'Multiple data sources configuration',

    -- Template and formatting
    template_id INT UNSIGNED COMMENT 'Report template to use',
    output_formats JSON NOT NULL DEFAULT '["pdf"]' COMMENT 'Array of formats: pdf, excel, csv',

    -- Schedule configuration
    is_scheduled BOOLEAN DEFAULT FALSE,
    schedule_type ENUM('once', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly') DEFAULT 'once',
    schedule_config JSON COMMENT 'Detailed schedule configuration',
    next_run_at TIMESTAMP NULL DEFAULT NULL,
    last_run_at TIMESTAMP NULL DEFAULT NULL,

    -- Recipients
    recipients JSON COMMENT 'Array of email addresses',
    recipient_groups JSON COMMENT 'Array of group IDs',

    -- Parameters
    parameters JSON COMMENT 'Report parameters and filters',

    -- Status
    status ENUM('active', 'paused', 'disabled') DEFAULT 'active',

    -- Ownership
    created_by INT UNSIGNED NOT NULL,
    owned_by INT UNSIGNED NOT NULL,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (dashboard_id) REFERENCES dashboards(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (owned_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_report_tenant_type (tenant_id, report_type, status),
    INDEX idx_report_scheduled (is_scheduled, status, next_run_at),
    INDEX idx_report_owner (owned_by, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Report executions table - Execution history
CREATE TABLE IF NOT EXISTS report_executions (
    -- Multi-tenancy support
    tenant_id INT NOT NULL,

    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Core fields
    report_id INT UNSIGNED NOT NULL,

    -- Execution details
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    execution_time_ms INT COMMENT 'Execution time in milliseconds',

    -- Status
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    error_message TEXT,

    -- Generated files
    files_generated JSON COMMENT 'Array of file paths and metadata',
    total_file_size BIGINT COMMENT 'Total size in bytes',

    -- Delivery status
    delivery_status ENUM('pending', 'sending', 'sent', 'failed', 'partial') DEFAULT 'pending',
    delivered_to JSON COMMENT 'List of successful deliveries',
    failed_deliveries JSON COMMENT 'List of failed deliveries with reasons',
    delivery_completed_at TIMESTAMP NULL DEFAULT NULL,

    -- Execution context
    triggered_by INT UNSIGNED COMMENT 'User who triggered the execution',
    trigger_type ENUM('manual', 'scheduled', 'api') DEFAULT 'manual',
    parameters_used JSON COMMENT 'Parameters used for this execution',

    -- Statistics
    rows_processed INT,
    data_points_generated INT,

    -- Audit fields
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_exec_tenant_report (tenant_id, report_id, started_at DESC),
    INDEX idx_exec_status (status, started_at DESC),
    INDEX idx_exec_delivery (delivery_status, delivery_completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- MATERIALIZED VIEWS (Using regular views with indexing strategy)
-- ============================================

-- Daily active users view
CREATE OR REPLACE VIEW v_daily_active_users AS
SELECT
    tenant_id,
    DATE(created_at) as activity_date,
    COUNT(DISTINCT user_id) as active_users,
    COUNT(*) as total_actions
FROM activity_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY tenant_id, DATE(created_at);

-- Storage usage summary view
CREATE OR REPLACE VIEW v_storage_usage_summary AS
SELECT
    f.tenant_id,
    COUNT(*) as total_files,
    SUM(f.file_size) as total_size_bytes,
    ROUND(SUM(f.file_size) / 1024 / 1024, 2) as total_size_mb,
    ROUND(SUM(f.file_size) / 1024 / 1024 / 1024, 2) as total_size_gb,
    MAX(f.created_at) as last_upload,
    COUNT(DISTINCT f.uploaded_by) as unique_uploaders
FROM files f
GROUP BY f.tenant_id;

-- Activity trends view
CREATE OR REPLACE VIEW v_activity_trends AS
SELECT
    tenant_id,
    DATE_FORMAT(created_at, '%Y-%m') as month,
    action,
    COUNT(*) as action_count,
    COUNT(DISTINCT user_id) as unique_users
FROM activity_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
GROUP BY tenant_id, DATE_FORMAT(created_at, '%Y-%m'), action;

-- Top content view
CREATE OR REPLACE VIEW v_top_content AS
SELECT
    f.tenant_id,
    f.id as file_id,
    f.filename,
    f.file_type,
    COUNT(DISTINCT al.user_id) as unique_viewers,
    COUNT(al.id) as total_views,
    MAX(al.created_at) as last_viewed
FROM files f
LEFT JOIN activity_log al ON
    al.entity_type = 'file' AND
    al.entity_id = f.id AND
    al.action IN ('view', 'download')
WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY f.tenant_id, f.id, f.filename, f.file_type
ORDER BY total_views DESC
LIMIT 100;

-- System health metrics view
CREATE OR REPLACE VIEW v_system_health_metrics AS
SELECT
    m.tenant_id,
    m.metric_name,
    md.display_name,
    md.unit,
    AVG(m.value) as avg_value,
    MIN(m.value) as min_value,
    MAX(m.value) as max_value,
    COUNT(*) as data_points,
    md.warning_threshold,
    md.critical_threshold,
    CASE
        WHEN md.threshold_direction = 'above' AND AVG(m.value) > md.critical_threshold THEN 'critical'
        WHEN md.threshold_direction = 'above' AND AVG(m.value) > md.warning_threshold THEN 'warning'
        WHEN md.threshold_direction = 'below' AND AVG(m.value) < md.critical_threshold THEN 'critical'
        WHEN md.threshold_direction = 'below' AND AVG(m.value) < md.warning_threshold THEN 'warning'
        ELSE 'normal'
    END as health_status
FROM metrics m
JOIN metric_definitions md ON m.tenant_id = md.tenant_id AND m.metric_name = md.metric_name
WHERE m.timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    AND md.category = 'system'
GROUP BY m.tenant_id, m.metric_name, md.display_name, md.unit,
         md.warning_threshold, md.critical_threshold, md.threshold_direction;

-- ============================================
-- STORED PROCEDURES
-- ============================================

DELIMITER //

-- Procedure to prune old metrics based on retention policies
CREATE PROCEDURE sp_prune_old_metrics()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_tenant_id INT;
    DECLARE v_metric_name VARCHAR(100);
    DECLARE v_retention_days INT;

    DECLARE cur CURSOR FOR
        SELECT DISTINCT tenant_id, metric_name, retention_days
        FROM metric_definitions
        WHERE is_active = TRUE;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;

    prune_loop: LOOP
        FETCH cur INTO v_tenant_id, v_metric_name, v_retention_days;
        IF done THEN
            LEAVE prune_loop;
        END IF;

        -- Delete raw metrics older than retention period
        DELETE FROM metrics
        WHERE tenant_id = v_tenant_id
            AND metric_name = v_metric_name
            AND aggregation_level = 'raw'
            AND timestamp < DATE_SUB(NOW(), INTERVAL v_retention_days DAY);

        -- Delete hourly aggregations older than 7 days
        DELETE FROM metric_aggregations
        WHERE tenant_id = v_tenant_id
            AND metric_name = v_metric_name
            AND aggregation_level = 'hour'
            AND period_start < DATE_SUB(NOW(), INTERVAL 7 DAY);

        -- Delete daily aggregations older than 3 months
        DELETE FROM metric_aggregations
        WHERE tenant_id = v_tenant_id
            AND metric_name = v_metric_name
            AND aggregation_level = 'day'
            AND period_start < DATE_SUB(NOW(), INTERVAL 3 MONTH);

    END LOOP;

    CLOSE cur;
END//

-- Procedure to aggregate metrics
CREATE PROCEDURE sp_aggregate_metrics(
    IN p_tenant_id INT,
    IN p_metric_name VARCHAR(100),
    IN p_aggregation_level ENUM('minute', 'hour', 'day', 'week', 'month')
)
BEGIN
    DECLARE v_period_start TIMESTAMP;
    DECLARE v_period_end TIMESTAMP;

    -- Determine the period based on aggregation level
    CASE p_aggregation_level
        WHEN 'minute' THEN
            SET v_period_start = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MINUTE), '%Y-%m-%d %H:%i:00');
            SET v_period_end = DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:00');
        WHEN 'hour' THEN
            SET v_period_start = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 HOUR), '%Y-%m-%d %H:00:00');
            SET v_period_end = DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00');
        WHEN 'day' THEN
            SET v_period_start = DATE(DATE_SUB(NOW(), INTERVAL 1 DAY));
            SET v_period_end = DATE(NOW());
        WHEN 'week' THEN
            SET v_period_start = DATE_SUB(DATE(NOW()), INTERVAL DAYOFWEEK(NOW()) - 2 + 7 DAY);
            SET v_period_end = DATE_SUB(DATE(NOW()), INTERVAL DAYOFWEEK(NOW()) - 2 DAY);
        WHEN 'month' THEN
            SET v_period_start = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m-01');
            SET v_period_end = DATE_FORMAT(NOW(), '%Y-%m-01');
    END CASE;

    -- Insert aggregations
    INSERT INTO metric_aggregations (
        tenant_id, metric_name, aggregation_type, aggregation_level,
        value, sample_count, min_value, max_value, sum_value,
        period_start, period_end, dimensions
    )
    SELECT
        tenant_id,
        metric_name,
        'avg' as aggregation_type,
        p_aggregation_level,
        AVG(value),
        COUNT(*),
        MIN(value),
        MAX(value),
        SUM(value),
        v_period_start,
        v_period_end,
        JSON_OBJECT('source', source)
    FROM metrics
    WHERE tenant_id = p_tenant_id
        AND metric_name = p_metric_name
        AND timestamp >= v_period_start
        AND timestamp < v_period_end
    GROUP BY tenant_id, metric_name, source
    ON DUPLICATE KEY UPDATE
        value = VALUES(value),
        sample_count = VALUES(sample_count),
        min_value = VALUES(min_value),
        max_value = VALUES(max_value),
        sum_value = VALUES(sum_value),
        updated_at = NOW();
END//

-- Procedure to generate daily reports
CREATE PROCEDURE sp_generate_daily_report(IN p_tenant_id INT)
BEGIN
    DECLARE v_report_id INT;
    DECLARE done INT DEFAULT FALSE;

    DECLARE cur CURSOR FOR
        SELECT id
        FROM reports
        WHERE tenant_id = p_tenant_id
            AND is_scheduled = TRUE
            AND schedule_type = 'daily'
            AND status = 'active'
            AND (next_run_at IS NULL OR next_run_at <= NOW());

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;

    report_loop: LOOP
        FETCH cur INTO v_report_id;
        IF done THEN
            LEAVE report_loop;
        END IF;

        -- Create execution record
        INSERT INTO report_executions (
            tenant_id, report_id, status, trigger_type
        ) VALUES (
            p_tenant_id, v_report_id, 'pending', 'scheduled'
        );

        -- Update next run time
        UPDATE reports
        SET next_run_at = DATE_ADD(NOW(), INTERVAL 1 DAY),
            last_run_at = NOW()
        WHERE id = v_report_id;

    END LOOP;

    CLOSE cur;
END//

DELIMITER ;

-- ============================================
-- PERFORMANCE INDEXES (Additional)
-- ============================================

-- Create indexes for better query performance
CREATE INDEX idx_metrics_tenant_source_time ON metrics(tenant_id, source, timestamp DESC);
CREATE INDEX idx_dashboard_share ON dashboards(share_token, status) WHERE share_token IS NOT NULL;
CREATE INDEX idx_widget_refresh ON dashboard_widgets(refresh_interval) WHERE refresh_interval > 0;
CREATE INDEX idx_notif_unread_priority ON notifications(user_id, is_read, priority DESC) WHERE is_read = FALSE;

-- ============================================
-- DEMO DATA
-- ============================================

-- Sample tenants (if not exists)
INSERT INTO tenants (id, name, domain, status) VALUES
    (1, 'Demo Company A', 'demo-a.collabora.com', 'active'),
    (2, 'Demo Company B', 'demo-b.collabora.com', 'active'),
    (3, 'Tech Startup Inc', 'techstartup.collabora.com', 'active')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Sample users (if not exists)
INSERT INTO users (id, tenant_id, username, email, full_name, status) VALUES
    (1, 1, 'john.doe', 'john.doe@demo-a.com', 'John Doe', 'active'),
    (2, 1, 'jane.smith', 'jane.smith@demo-a.com', 'Jane Smith', 'active'),
    (3, 2, 'bob.wilson', 'bob.wilson@demo-b.com', 'Bob Wilson', 'active'),
    (4, 3, 'alice.johnson', 'alice.johnson@techstartup.com', 'Alice Johnson', 'active')
ON DUPLICATE KEY UPDATE email=VALUES(email);

-- Sample dashboards
INSERT INTO dashboards (tenant_id, user_id, name, description, is_default, theme, layout_config, created_by) VALUES
    (1, 1, 'Executive Overview', 'High-level metrics and KPIs for executives', TRUE, 'light',
     '{"columns": 12, "rowHeight": 60, "layout": "grid"}', 1),
    (1, 1, 'Sales Dashboard', 'Sales performance and pipeline analytics', FALSE, 'dark',
     '{"columns": 12, "rowHeight": 50, "layout": "grid"}', 1),
    (1, 2, 'Project Status', 'Project tracking and team performance', TRUE, 'light',
     '{"columns": 12, "rowHeight": 60, "layout": "grid"}', 2),
    (2, 3, 'Operations Dashboard', 'Operational metrics and efficiency', TRUE, 'auto',
     '{"columns": 12, "rowHeight": 60, "layout": "grid"}', 3),
    (3, 4, 'Development Metrics', 'Code quality and deployment statistics', TRUE, 'dark',
     '{"columns": 12, "rowHeight": 55, "layout": "grid"}', 4);

-- Sample widget templates
INSERT INTO widget_templates (tenant_id, name, description, category, widget_type, default_config, created_by) VALUES
    (1, 'Revenue Chart', 'Line chart showing revenue over time', 'analytics', 'chart',
     '{"chartType": "line", "dataKey": "revenue", "xAxis": "date", "yAxis": "amount"}', 1),
    (1, 'User Count', 'Display total active users', 'analytics', 'metric',
     '{"metric": "active_users", "format": "number", "icon": "users"}', 1),
    (1, 'Task Progress', 'Show task completion percentage', 'monitoring', 'gauge',
     '{"min": 0, "max": 100, "unit": "%", "thresholds": {"warning": 60, "success": 80}}', 1),
    (2, 'Activity Feed', 'Recent activity timeline', 'monitoring', 'table',
     '{"columns": ["timestamp", "user", "action", "entity"], "limit": 10}', 3),
    (3, 'Server Health', 'Server status and health metrics', 'monitoring', 'metric',
     '{"metrics": ["cpu", "memory", "disk"], "format": "percentage"}', 4);

-- Sample dashboard widgets
INSERT INTO dashboard_widgets (tenant_id, dashboard_id, widget_type, title, grid_x, grid_y, grid_width, grid_height, data_source, config) VALUES
    (1, 1, 'metric', 'Active Users', 0, 0, 3, 2, 'users',
     '{"metric": "count", "filter": "status=active", "icon": "users", "color": "blue"}'),
    (1, 1, 'chart', 'Revenue Trend', 3, 0, 6, 4, 'transactions',
     '{"chartType": "line", "period": "30days", "groupBy": "day"}'),
    (1, 1, 'gauge', 'Storage Usage', 9, 0, 3, 2, 'files',
     '{"max": 100, "unit": "GB", "thresholds": {"warning": 70, "critical": 90}}'),
    (1, 1, 'table', 'Recent Activities', 0, 4, 12, 4, 'activity_log',
     '{"columns": ["timestamp", "user", "action"], "limit": 20}'),
    (1, 2, 'chart', 'Sales Pipeline', 0, 0, 8, 4, 'sales',
     '{"chartType": "funnel", "stages": ["lead", "qualified", "proposal", "closed"]}'),
    (1, 3, 'metric', 'Open Tasks', 0, 0, 3, 2, 'tasks',
     '{"metric": "count", "filter": "status=open", "icon": "tasks"}');

-- Sample metric definitions
INSERT INTO metric_definitions (tenant_id, metric_name, display_name, description, category, metric_type, unit, warning_threshold, critical_threshold) VALUES
    (1, 'active_users', 'Active Users', 'Number of active users in the system', 'usage', 'gauge', 'users', NULL, NULL),
    (1, 'storage_used', 'Storage Used', 'Total storage space used', 'resource', 'gauge', 'GB', 80, 95),
    (1, 'api_requests', 'API Requests', 'Number of API requests', 'performance', 'counter', 'requests', 10000, 50000),
    (1, 'response_time', 'Response Time', 'Average API response time', 'performance', 'gauge', 'ms', 500, 1000),
    (2, 'cpu_usage', 'CPU Usage', 'Server CPU utilization', 'system', 'gauge', 'percent', 70, 90),
    (3, 'deployment_success', 'Deployment Success Rate', 'Percentage of successful deployments', 'deployment', 'gauge', 'percent', NULL, NULL);

-- Sample metrics data
INSERT INTO metrics (tenant_id, metric_name, metric_type, value, timestamp, source) VALUES
    (1, 'active_users', 'gauge', 125, NOW() - INTERVAL 1 HOUR, 'system'),
    (1, 'active_users', 'gauge', 132, NOW() - INTERVAL 30 MINUTE, 'system'),
    (1, 'active_users', 'gauge', 145, NOW(), 'system'),
    (1, 'storage_used', 'gauge', 45.7, NOW() - INTERVAL 1 HOUR, 'filesystem'),
    (1, 'storage_used', 'gauge', 46.2, NOW() - INTERVAL 30 MINUTE, 'filesystem'),
    (1, 'storage_used', 'gauge', 46.8, NOW(), 'filesystem'),
    (1, 'api_requests', 'counter', 2543, NOW() - INTERVAL 1 HOUR, 'api-gateway'),
    (1, 'api_requests', 'counter', 3128, NOW() - INTERVAL 30 MINUTE, 'api-gateway'),
    (1, 'response_time', 'gauge', 125.5, NOW() - INTERVAL 1 HOUR, 'api-gateway'),
    (1, 'response_time', 'gauge', 132.7, NOW(), 'api-gateway');

-- Sample notifications
INSERT INTO notifications (tenant_id, user_id, type, category, title, message, priority, action_url) VALUES
    (1, 1, 'info', 'system', 'System Update Available', 'A new system update is available for installation.', 'normal', '/settings/updates'),
    (1, 1, 'warning', 'project', 'Project Deadline Approaching', 'Project Alpha deadline is in 3 days.', 'high', '/projects/alpha'),
    (1, 2, 'success', 'task', 'Task Completed', 'Your task "Design Review" has been marked as complete.', 'normal', '/tasks/123'),
    (1, 2, 'error', 'system', 'Storage Limit Warning', 'You have used 90% of your allocated storage.', 'high', '/settings/storage'),
    (2, 3, 'info', 'document', 'Document Shared', 'Jane shared "Q4 Report" with you.', 'normal', '/documents/q4-report');

-- Sample notification preferences
INSERT INTO notification_preferences (tenant_id, user_id, email_enabled, desktop_enabled, email_digest, category_settings) VALUES
    (1, 1, TRUE, TRUE, 'immediate', '{"system": true, "project": true, "task": true}'),
    (1, 2, TRUE, FALSE, 'daily', '{"system": true, "project": false, "task": true}'),
    (2, 3, FALSE, TRUE, 'none', '{"system": true, "project": true, "task": false}');

-- Sample notification templates
INSERT INTO notification_templates (tenant_id, code, name, type, category, title_template, message_template, variables) VALUES
    (1, 'task_assigned', 'Task Assignment', 'info', 'task', 'New Task: {{task_name}}',
     'You have been assigned to task "{{task_name}}" by {{assignor_name}}.',
     '["task_name", "assignor_name", "due_date"]'),
    (1, 'storage_warning', 'Storage Warning', 'warning', 'system', 'Storage Limit Warning',
     'You have used {{usage_percent}}% of your allocated {{total_storage}}GB storage.',
     '["usage_percent", "total_storage", "used_storage"]'),
    (1, 'report_ready', 'Report Ready', 'success', 'reporting', 'Report Generated',
     'Your {{report_name}} report is ready for download.',
     '["report_name", "report_date", "download_link"]');

-- Sample reports
INSERT INTO reports (tenant_id, name, description, report_type, output_formats, is_scheduled, schedule_type, recipients, created_by, owned_by) VALUES
    (1, 'Weekly Activity Report', 'Summary of weekly user activities', 'custom', '["pdf", "excel"]',
     TRUE, 'weekly', '["admin@demo-a.com", "manager@demo-a.com"]', 1, 1),
    (1, 'Monthly Dashboard Export', 'Export of executive dashboard', 'dashboard', '["pdf"]',
     TRUE, 'monthly', '["executives@demo-a.com"]', 1, 1),
    (2, 'Daily Operations Summary', 'Daily operational metrics', 'system', '["csv", "excel"]',
     TRUE, 'daily', '["ops@demo-b.com"]', 3, 3),
    (3, 'Deployment Statistics', 'Monthly deployment success metrics', 'analytics', '["pdf", "csv"]',
     TRUE, 'monthly', '["devops@techstartup.com"]', 4, 4);

-- Sample report executions
INSERT INTO report_executions (tenant_id, report_id, status, started_at, completed_at, execution_time_ms, rows_processed, trigger_type, triggered_by) VALUES
    (1, 1, 'completed', NOW() - INTERVAL 7 DAY, NOW() - INTERVAL 7 DAY + INTERVAL 30 SECOND, 30000, 1523, 'scheduled', NULL),
    (1, 1, 'completed', NOW(), NOW() + INTERVAL 45 SECOND, 45000, 1876, 'scheduled', NULL),
    (1, 2, 'failed', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY + INTERVAL 10 SECOND, 10000, 0, 'manual', 1),
    (2, 3, 'completed', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY + INTERVAL 15 SECOND, 15000, 523, 'scheduled', NULL);

-- ============================================
-- RESTORE FOREIGN KEY CHECKS
-- ============================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- VERIFICATION
-- ============================================
SELECT 'Phase 6 - Dashboard and Analytics System installation completed successfully' as status,
       (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = 'collabora'
        AND table_name IN ('dashboards', 'dashboard_widgets', 'widget_templates',
                          'metrics', 'metric_definitions', 'metric_aggregations',
                          'notifications', 'notification_preferences', 'notification_templates',
                          'reports', 'report_executions')) as tables_created,
       (SELECT COUNT(*) FROM information_schema.views
        WHERE table_schema = 'collabora'
        AND table_name LIKE 'v_%') as views_created,
       (SELECT COUNT(*) FROM information_schema.routines
        WHERE routine_schema = 'collabora'
        AND routine_type = 'PROCEDURE') as procedures_created,
       NOW() as execution_time;

-- Display sample data counts
SELECT 'Sample Data Summary' as category, '' as details
UNION ALL
SELECT 'Dashboards', CONCAT(COUNT(*), ' created') FROM dashboards
UNION ALL
SELECT 'Dashboard Widgets', CONCAT(COUNT(*), ' configured') FROM dashboard_widgets
UNION ALL
SELECT 'Widget Templates', CONCAT(COUNT(*), ' available') FROM widget_templates
UNION ALL
SELECT 'Metric Definitions', CONCAT(COUNT(*), ' defined') FROM metric_definitions
UNION ALL
SELECT 'Metrics Data Points', CONCAT(COUNT(*), ' recorded') FROM metrics
UNION ALL
SELECT 'Notifications', CONCAT(COUNT(*), ' created') FROM notifications
UNION ALL
SELECT 'Reports', CONCAT(COUNT(*), ' configured') FROM reports
UNION ALL
SELECT 'Report Executions', CONCAT(COUNT(*), ' logged') FROM report_executions;