-- ============================================
-- Module: Task Management System
-- Version: 2025-10-24
-- Author: Database Architect
-- Description: Complete task management with multi-tenancy,
--              soft delete, hierarchical tasks, multi-user
--              assignments, comments, and audit trail
-- ============================================

USE collaboranexio;

-- ============================================
-- PRE-FLIGHT CHECKS
-- ============================================

-- Verify tenants table exists
SELECT 'Checking tenants table...' as Status;
SELECT COUNT(*) as tenant_count FROM tenants WHERE deleted_at IS NULL;

-- Verify users table exists
SELECT 'Checking users table...' as Status;
SELECT COUNT(*) as user_count FROM users WHERE deleted_at IS NULL;

-- ============================================
-- TABLE 1: TASKS (Enhanced Multi-Tenant)
-- ============================================

-- Drop existing table if recreating (Development only)
-- DROP TABLE IF EXISTS task_history;
-- DROP TABLE IF EXISTS task_comments;
-- DROP TABLE IF EXISTS task_assignments;
-- DROP TABLE IF EXISTS tasks;

CREATE TABLE IF NOT EXISTS tasks (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL COMMENT 'Tenant isolation - MANDATORY filter in all queries',

    -- Task identification
    title VARCHAR(500) NOT NULL COMMENT 'Task title - searchable',
    description TEXT NULL COMMENT 'Detailed task description',

    -- Task hierarchy (self-referencing for subtasks)
    parent_id INT UNSIGNED NULL COMMENT 'Parent task ID for subtasks/checklist items',
    order_index INT UNSIGNED DEFAULT 0 COMMENT 'Sort order within parent or status column',

    -- Task ownership and assignment
    created_by INT UNSIGNED NOT NULL COMMENT 'User who created the task',
    assigned_to INT UNSIGNED NULL COMMENT 'Primary assignee (legacy single-user, use task_assignments for multi-user)',

    -- Project relationship (optional)
    project_id INT UNSIGNED NULL COMMENT 'Optional link to projects table',

    -- Status workflow
    status ENUM('todo', 'in_progress', 'review', 'done', 'cancelled') DEFAULT 'todo'
        COMMENT 'Task lifecycle status - indexed for kanban queries',

    -- Priority levels
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium'
        COMMENT 'Task priority - indexed for filtering',

    -- Scheduling
    due_date DATETIME NULL COMMENT 'Task deadline - indexed for overdue queries',
    start_date DATETIME NULL COMMENT 'Planned start date',

    -- Time tracking
    estimated_hours DECIMAL(8,2) NULL COMMENT 'Estimated effort in hours',
    actual_hours DECIMAL(8,2) NULL COMMENT 'Actual time spent in hours',

    -- Progress tracking
    progress_percentage TINYINT UNSIGNED DEFAULT 0 COMMENT 'Completion percentage (0-100)',

    -- Metadata
    tags JSON NULL COMMENT 'Array of tags for categorization - searchable',
    attachments JSON NULL COMMENT 'Array of file references {file_id, name, url}',

    -- Completion tracking
    completed_at TIMESTAMP NULL COMMENT 'When task was marked as done',
    completed_by INT UNSIGNED NULL COMMENT 'User who completed the task',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL COMMENT 'Soft delete timestamp - MANDATORY in WHERE clauses',

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),

    -- Foreign keys with appropriate CASCADE rules
    CONSTRAINT fk_tasks_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,

    CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE RESTRICT COMMENT 'Prevent deletion of task creator',

    CONSTRAINT fk_tasks_assigned_to FOREIGN KEY (assigned_to)
        REFERENCES users(id) ON DELETE SET NULL COMMENT 'Allow user deletion, task becomes orphaned',

    CONSTRAINT fk_tasks_completed_by FOREIGN KEY (completed_by)
        REFERENCES users(id) ON DELETE SET NULL,

    CONSTRAINT fk_tasks_parent FOREIGN KEY (parent_id)
        REFERENCES tasks(id) ON DELETE CASCADE COMMENT 'Delete subtasks when parent is deleted',

    CONSTRAINT fk_tasks_project FOREIGN KEY (project_id)
        REFERENCES projects(id) ON DELETE CASCADE COMMENT 'Delete tasks when project is deleted',

    -- Performance indexes (MANDATORY multi-tenant patterns)
    INDEX idx_tasks_tenant_created (tenant_id, created_at) COMMENT 'Multi-tenant chronological list',
    INDEX idx_tasks_tenant_deleted (tenant_id, deleted_at) COMMENT 'Multi-tenant soft delete filter',
    INDEX idx_tasks_tenant_status (tenant_id, status, deleted_at) COMMENT 'Kanban board queries',
    INDEX idx_tasks_tenant_priority (tenant_id, priority, deleted_at) COMMENT 'Priority filtering',
    INDEX idx_tasks_tenant_due (tenant_id, due_date, deleted_at) COMMENT 'Deadline queries',

    -- User-centric indexes
    INDEX idx_tasks_assigned_to (assigned_to, status) COMMENT 'My tasks queries',
    INDEX idx_tasks_created_by (created_by) COMMENT 'Tasks I created',
    INDEX idx_tasks_completed_by (completed_by) COMMENT 'Completion audit',

    -- Hierarchy indexes
    INDEX idx_tasks_parent (parent_id, order_index) COMMENT 'Subtask hierarchy',
    INDEX idx_tasks_project (project_id, status) COMMENT 'Project tasks',

    -- Status/date indexes
    INDEX idx_tasks_status (status) COMMENT 'Global status queries',
    INDEX idx_tasks_due_date (due_date) COMMENT 'Overdue task queries',

    -- Search indexes
    FULLTEXT INDEX idx_tasks_search (title, description) COMMENT 'Full-text search on title and description',

    -- Check constraints
    CHECK (progress_percentage >= 0 AND progress_percentage <= 100),
    CHECK (estimated_hours >= 0),
    CHECK (actual_hours >= 0),
    CHECK (start_date IS NULL OR due_date IS NULL OR start_date <= due_date)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Multi-tenant task management with hierarchy, soft delete, and full audit trail';

-- ============================================
-- TABLE 2: TASK_ASSIGNMENTS (N:N User Assignment)
-- ============================================

CREATE TABLE IF NOT EXISTS task_assignments (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL,

    -- Assignment relationship
    task_id INT UNSIGNED NOT NULL COMMENT 'Task being assigned',
    user_id INT UNSIGNED NOT NULL COMMENT 'User assigned to task',

    -- Assignment metadata
    assigned_by INT UNSIGNED NOT NULL COMMENT 'User who made the assignment',
    role ENUM('owner', 'contributor', 'reviewer') DEFAULT 'contributor'
        COMMENT 'Assignment role - owner has full control',

    -- Assignment tracking
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    accepted_at TIMESTAMP NULL COMMENT 'When user accepted assignment',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL,

    -- Constraints
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_task_assignments_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,

    CONSTRAINT fk_task_assignments_task FOREIGN KEY (task_id)
        REFERENCES tasks(id) ON DELETE CASCADE COMMENT 'Remove assignment when task deleted',

    CONSTRAINT fk_task_assignments_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE COMMENT 'Remove assignment when user deleted',

    CONSTRAINT fk_task_assignments_assigned_by FOREIGN KEY (assigned_by)
        REFERENCES users(id) ON DELETE RESTRICT COMMENT 'Preserve audit trail',

    -- Unique constraint: prevent duplicate assignments
    UNIQUE KEY uk_task_assignment (task_id, user_id, deleted_at) COMMENT 'One active assignment per user/task',

    -- Performance indexes
    INDEX idx_task_assignments_tenant (tenant_id, deleted_at),
    INDEX idx_task_assignments_task (task_id, deleted_at) COMMENT 'List assignees for task',
    INDEX idx_task_assignments_user (user_id, deleted_at) COMMENT 'List tasks for user',
    INDEX idx_task_assignments_assigned_by (assigned_by) COMMENT 'Who assigned this'

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Multi-user task assignments with role-based access';

-- ============================================
-- TABLE 3: TASK_COMMENTS (Threaded Comments)
-- ============================================

CREATE TABLE IF NOT EXISTS task_comments (
    -- Primary key
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL,

    -- Comment relationship
    task_id INT UNSIGNED NOT NULL COMMENT 'Task being commented on',
    user_id INT UNSIGNED NOT NULL COMMENT 'Comment author',

    -- Threaded comments support
    parent_comment_id INT UNSIGNED NULL COMMENT 'Parent comment for replies',

    -- Comment content
    content TEXT NOT NULL COMMENT 'Comment text - required',
    attachments JSON NULL COMMENT 'Optional file attachments {file_id, name, url}',

    -- Edit tracking
    is_edited BOOLEAN DEFAULT FALSE COMMENT 'Flag if comment was edited',
    edited_at TIMESTAMP NULL COMMENT 'When comment was last edited',

    -- Soft delete (MANDATORY)
    deleted_at TIMESTAMP NULL,

    -- Audit fields (MANDATORY)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_task_comments_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,

    CONSTRAINT fk_task_comments_task FOREIGN KEY (task_id)
        REFERENCES tasks(id) ON DELETE CASCADE COMMENT 'Delete comments with task',

    CONSTRAINT fk_task_comments_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE COMMENT 'Delete comments when user deleted',

    CONSTRAINT fk_task_comments_parent FOREIGN KEY (parent_comment_id)
        REFERENCES task_comments(id) ON DELETE CASCADE COMMENT 'Delete replies with parent',

    -- Performance indexes
    INDEX idx_task_comments_tenant (tenant_id, deleted_at),
    INDEX idx_task_comments_task (task_id, deleted_at, created_at) COMMENT 'List comments for task',
    INDEX idx_task_comments_user (user_id) COMMENT 'List comments by user',
    INDEX idx_task_comments_parent (parent_comment_id) COMMENT 'Threaded comments',

    -- Search index
    FULLTEXT INDEX idx_task_comments_search (content) COMMENT 'Search in comments'

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Task comments with threading, attachments, and edit tracking';

-- ============================================
-- TABLE 4: TASK_HISTORY (Audit Trail)
-- ============================================

CREATE TABLE IF NOT EXISTS task_history (
    -- Primary key
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Large ID for high-volume audit data',

    -- Multi-tenancy (MANDATORY)
    tenant_id INT UNSIGNED NOT NULL,

    -- History relationship
    task_id INT UNSIGNED NOT NULL COMMENT 'Task being audited',
    user_id INT UNSIGNED NULL COMMENT 'User who made the change (NULL for system changes)',

    -- Change tracking
    action VARCHAR(100) NOT NULL COMMENT 'Action performed: created, updated, status_changed, assigned, etc.',
    field_name VARCHAR(100) NULL COMMENT 'Field that was changed (NULL for creation)',
    old_value TEXT NULL COMMENT 'Previous value (JSON for complex data)',
    new_value TEXT NULL COMMENT 'New value (JSON for complex data)',

    -- Metadata
    ip_address VARCHAR(45) NULL COMMENT 'User IP address',
    user_agent VARCHAR(500) NULL COMMENT 'Browser/client info',

    -- Timestamp (no soft delete on audit logs)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    PRIMARY KEY (id),

    -- Foreign keys
    CONSTRAINT fk_task_history_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,

    CONSTRAINT fk_task_history_task FOREIGN KEY (task_id)
        REFERENCES tasks(id) ON DELETE CASCADE COMMENT 'Delete history with task',

    CONSTRAINT fk_task_history_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE SET NULL COMMENT 'Preserve history even if user deleted',

    -- Performance indexes
    INDEX idx_task_history_tenant (tenant_id, created_at),
    INDEX idx_task_history_task (task_id, created_at DESC) COMMENT 'Task timeline',
    INDEX idx_task_history_user (user_id, created_at DESC) COMMENT 'User activity',
    INDEX idx_task_history_action (action, created_at) COMMENT 'Action-based queries'

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Complete audit trail for all task changes';

-- ============================================
-- HELPER VIEWS
-- ============================================

-- View: Orphaned tasks (assigned_to user deleted or no longer in tenant)
CREATE OR REPLACE VIEW view_orphaned_tasks AS
SELECT
    t.id,
    t.tenant_id,
    t.title,
    t.status,
    t.priority,
    t.due_date,
    t.assigned_to,
    t.created_by,
    t.created_at,
    CASE
        WHEN t.assigned_to IS NULL THEN 'No assignee'
        WHEN u.id IS NULL THEN 'Assigned user deleted'
        WHEN u.deleted_at IS NOT NULL THEN 'Assigned user soft-deleted'
        WHEN u.tenant_id != t.tenant_id THEN 'Assigned user in different tenant'
        ELSE 'Unknown issue'
    END as orphan_reason
FROM tasks t
LEFT JOIN users u ON t.assigned_to = u.id AND u.deleted_at IS NULL
WHERE t.deleted_at IS NULL
  AND t.status NOT IN ('done', 'cancelled')
  AND (
      t.assigned_to IS NULL
      OR u.id IS NULL
      OR u.deleted_at IS NOT NULL
      OR u.tenant_id != t.tenant_id
  );

-- View: Task summary by status (for dashboards)
CREATE OR REPLACE VIEW view_task_summary_by_status AS
SELECT
    t.tenant_id,
    t.status,
    COUNT(*) as task_count,
    COUNT(CASE WHEN t.priority = 'critical' THEN 1 END) as critical_count,
    COUNT(CASE WHEN t.priority = 'high' THEN 1 END) as high_count,
    COUNT(CASE WHEN t.due_date < NOW() AND t.status NOT IN ('done', 'cancelled') THEN 1 END) as overdue_count
FROM tasks t
WHERE t.deleted_at IS NULL
GROUP BY t.tenant_id, t.status;

-- View: My tasks (requires tenant_id and user_id parameters)
CREATE OR REPLACE VIEW view_my_tasks AS
SELECT
    t.id,
    t.tenant_id,
    t.title,
    t.description,
    t.status,
    t.priority,
    t.due_date,
    t.progress_percentage,
    t.created_at,
    t.updated_at,
    u_creator.email as created_by_email,
    CONCAT(u_creator.first_name, ' ', u_creator.last_name) as created_by_name,
    CASE
        WHEN t.due_date < NOW() AND t.status NOT IN ('done', 'cancelled') THEN TRUE
        ELSE FALSE
    END as is_overdue,
    DATEDIFF(t.due_date, NOW()) as days_until_due
FROM tasks t
LEFT JOIN users u_creator ON t.created_by = u_creator.id
WHERE t.deleted_at IS NULL;

-- ============================================
-- STORED PROCEDURES/FUNCTIONS
-- ============================================

-- Function: Assign task to user
DELIMITER $$

DROP FUNCTION IF EXISTS assign_task_to_user$$
CREATE FUNCTION assign_task_to_user(
    p_task_id INT UNSIGNED,
    p_user_id INT UNSIGNED,
    p_assigned_by INT UNSIGNED
) RETURNS VARCHAR(255)
DETERMINISTIC
BEGIN
    DECLARE v_tenant_id INT UNSIGNED;
    DECLARE v_user_tenant_id INT UNSIGNED;
    DECLARE v_task_exists INT;
    DECLARE v_user_exists INT;
    DECLARE v_result VARCHAR(255);

    -- Check if task exists and get tenant_id
    SELECT COUNT(*), tenant_id INTO v_task_exists, v_tenant_id
    FROM tasks
    WHERE id = p_task_id AND deleted_at IS NULL
    GROUP BY tenant_id;

    IF v_task_exists = 0 THEN
        RETURN 'ERROR: Task not found';
    END IF;

    -- Check if user exists and belongs to same tenant
    SELECT COUNT(*), tenant_id INTO v_user_exists, v_user_tenant_id
    FROM users
    WHERE id = p_user_id AND deleted_at IS NULL
    GROUP BY tenant_id;

    IF v_user_exists = 0 THEN
        RETURN 'ERROR: User not found';
    END IF;

    IF v_tenant_id != v_user_tenant_id THEN
        RETURN 'ERROR: User belongs to different tenant';
    END IF;

    -- Insert assignment (will fail if duplicate due to unique constraint)
    INSERT INTO task_assignments (tenant_id, task_id, user_id, assigned_by)
    VALUES (v_tenant_id, p_task_id, p_user_id, p_assigned_by);

    -- Update primary assignee if NULL
    UPDATE tasks
    SET assigned_to = p_user_id, updated_at = NOW()
    WHERE id = p_task_id AND assigned_to IS NULL;

    -- Log to history
    INSERT INTO task_history (tenant_id, task_id, user_id, action, field_name, new_value)
    VALUES (v_tenant_id, p_task_id, p_assigned_by, 'assigned', 'assigned_to', p_user_id);

    RETURN 'SUCCESS: Task assigned';
END$$

DELIMITER ;

-- Function: Get orphaned tasks count for tenant
DELIMITER $$

DROP FUNCTION IF EXISTS get_orphaned_tasks_count$$
CREATE FUNCTION get_orphaned_tasks_count(
    p_tenant_id INT UNSIGNED
) RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    DECLARE v_count INT;

    SELECT COUNT(*) INTO v_count
    FROM view_orphaned_tasks
    WHERE tenant_id = p_tenant_id;

    RETURN v_count;
END$$

DELIMITER ;

-- ============================================
-- DEMO DATA (Optional - only if tables are empty)
-- ============================================

-- Only insert if tasks table is empty
INSERT INTO tasks (tenant_id, title, description, status, priority, created_by, assigned_to, due_date, created_at)
SELECT
    1 as tenant_id,
    'Setup Task Management System' as title,
    'Configure and test the new task management database schema' as description,
    'done' as status,
    'high' as priority,
    1 as created_by,
    1 as assigned_to,
    NOW() + INTERVAL 7 DAY as due_date,
    NOW() as created_at
WHERE NOT EXISTS (SELECT 1 FROM tasks LIMIT 1);

-- Sample subtask
INSERT INTO tasks (tenant_id, parent_id, title, description, status, priority, created_by, order_index, created_at)
SELECT
    1 as tenant_id,
    (SELECT id FROM tasks WHERE title = 'Setup Task Management System' LIMIT 1) as parent_id,
    'Test orphaned task detection' as title,
    'Verify that orphaned task view works correctly' as description,
    'todo' as status,
    'medium' as priority,
    1 as created_by,
    1 as order_index,
    NOW() as created_at
WHERE EXISTS (SELECT 1 FROM tasks WHERE title = 'Setup Task Management System')
  AND NOT EXISTS (SELECT 1 FROM tasks WHERE title = 'Test orphaned task detection');

-- Sample task assignment
INSERT INTO task_assignments (tenant_id, task_id, user_id, assigned_by, assigned_at)
SELECT
    1 as tenant_id,
    (SELECT id FROM tasks WHERE title = 'Setup Task Management System' LIMIT 1) as task_id,
    1 as user_id,
    1 as assigned_by,
    NOW() as assigned_at
WHERE EXISTS (SELECT 1 FROM tasks WHERE title = 'Setup Task Management System')
  AND NOT EXISTS (SELECT 1 FROM task_assignments WHERE task_id = (SELECT id FROM tasks WHERE title = 'Setup Task Management System' LIMIT 1));

-- Sample comment
INSERT INTO task_comments (tenant_id, task_id, user_id, content, created_at)
SELECT
    1 as tenant_id,
    (SELECT id FROM tasks WHERE title = 'Setup Task Management System' LIMIT 1) as task_id,
    1 as user_id,
    'Schema implementation completed successfully. All indexes and foreign keys verified.' as content,
    NOW() as created_at
WHERE EXISTS (SELECT 1 FROM tasks WHERE title = 'Setup Task Management System')
  AND NOT EXISTS (SELECT 1 FROM task_comments WHERE content LIKE '%Schema implementation completed%');

-- ============================================
-- VERIFICATION QUERIES
-- ============================================

SELECT 'Migration completed successfully!' as Status;

-- Table counts
SELECT 'tasks' as table_name, COUNT(*) as record_count FROM tasks
UNION ALL
SELECT 'task_assignments', COUNT(*) FROM task_assignments
UNION ALL
SELECT 'task_comments', COUNT(*) FROM task_comments
UNION ALL
SELECT 'task_history', COUNT(*) FROM task_history;

-- Verify indexes
SELECT
    TABLE_NAME,
    INDEX_NAME,
    SEQ_IN_INDEX,
    COLUMN_NAME,
    INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'collaboranexio'
  AND TABLE_NAME IN ('tasks', 'task_assignments', 'task_comments', 'task_history')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Test orphaned tasks view
SELECT 'Orphaned tasks check:' as Status;
SELECT * FROM view_orphaned_tasks LIMIT 5;

-- Test task summary view
SELECT 'Task summary by status:' as Status;
SELECT * FROM view_task_summary_by_status;

-- Test function
SELECT 'Orphaned task count:' as Status, get_orphaned_tasks_count(1) as count;

SELECT NOW() as executed_at;
