# Task Management Database Schema Documentation

**Version:** 2025-10-24
**Author:** Database Architect
**Project:** CollaboraNexio Multi-Tenant Platform

---

## Table of Contents

1. [Overview](#overview)
2. [Entity Relationship Diagram](#entity-relationship-diagram)
3. [Table Specifications](#table-specifications)
4. [Indexes and Performance](#indexes-and-performance)
5. [Views and Helper Functions](#views-and-helper-functions)
6. [Common Queries](#common-queries)
7. [Orphaned Task Handling](#orphaned-task-handling)
8. [Multi-Tenant Considerations](#multi-tenant-considerations)
9. [Migration Guide](#migration-guide)
10. [Testing Checklist](#testing-checklist)

---

## Overview

The Task Management system provides a complete solution for tracking work items across the CollaboraNexio platform with:

- **Multi-tenant isolation**: Every task is scoped to a specific tenant
- **Soft delete compliance**: No hard deletes, all records use `deleted_at` timestamp
- **Hierarchical tasks**: Support for subtasks and checklists via self-referencing `parent_id`
- **Multi-user assignments**: N:N relationship between tasks and users
- **Complete audit trail**: Every change tracked in `task_history` table
- **Orphaned task detection**: Built-in views to identify tasks without valid assignees

### Key Features

- ✅ Kanban board support (status-based columns)
- ✅ Priority levels (low, medium, high, critical)
- ✅ Time tracking (estimated vs actual hours)
- ✅ Progress percentage (0-100%)
- ✅ Threaded comments with attachments
- ✅ Full-text search on titles and descriptions
- ✅ Project linking (optional)
- ✅ Tags and metadata (JSON)

---

## Entity Relationship Diagram

### Textual ER Diagram

```
┌─────────────────────┐
│      TENANTS        │
│  (existing table)   │
└──────────┬──────────┘
           │
           │ 1:N (CASCADE)
           │
┌──────────▼──────────┐         ┌──────────────────────┐
│       TASKS         │◄────────┤ TASK_ASSIGNMENTS     │
│                     │  N:N    │                      │
│ - id (PK)           │         │ - id (PK)            │
│ - tenant_id (FK)    │         │ - task_id (FK)       │
│ - title             │         │ - user_id (FK)       │
│ - description       │         │ - assigned_by (FK)   │
│ - parent_id (FK)    │         │ - role               │
│ - status            │         │ - assigned_at        │
│ - priority          │         │ - deleted_at         │
│ - due_date          │         └──────────┬───────────┘
│ - created_by (FK)   │                    │
│ - assigned_to (FK)  │                    │ N:1
│ - completed_by (FK) │                    │
│ - deleted_at        │         ┌──────────▼──────────┐
└──────────┬──────────┘         │       USERS         │
           │                    │  (existing table)   │
           │ 1:N                └─────────────────────┘
           │
┌──────────▼──────────┐
│  TASK_COMMENTS      │
│                     │
│ - id (PK)           │         ┌──────────────────────┐
│ - task_id (FK)      │         │   TASK_HISTORY       │
│ - user_id (FK)      │         │                      │
│ - parent_comment_id │◄────────┤ - id (PK)            │
│ - content           │   1:N   │ - task_id (FK)       │
│ - is_edited         │         │ - user_id (FK)       │
│ - deleted_at        │         │ - action             │
└─────────────────────┘         │ - field_name         │
                                │ - old_value          │
                                │ - new_value          │
                                │ - created_at         │
                                └──────────────────────┘

LEGEND:
─────► 1:N relationship
◄────► N:N relationship
(FK)    Foreign Key
(PK)    Primary Key
```

### Relationship Summary

| Parent Table | Child Table | Relationship | ON DELETE | Reasoning |
|--------------|-------------|--------------|-----------|-----------|
| `tenants` | `tasks` | 1:N | CASCADE | Delete all tasks when tenant deleted |
| `tenants` | `task_assignments` | 1:N | CASCADE | Delete assignments when tenant deleted |
| `tenants` | `task_comments` | 1:N | CASCADE | Delete comments when tenant deleted |
| `tenants` | `task_history` | 1:N | CASCADE | Delete history when tenant deleted |
| `users` | `tasks.created_by` | 1:N | RESTRICT | Prevent deletion of task creator |
| `users` | `tasks.assigned_to` | 1:N | SET NULL | Allow deletion, task becomes orphaned |
| `users` | `tasks.completed_by` | 1:N | SET NULL | Preserve completion record |
| `users` | `task_assignments.user_id` | 1:N | CASCADE | Remove assignment when user deleted |
| `users` | `task_assignments.assigned_by` | 1:N | RESTRICT | Preserve audit trail |
| `users` | `task_comments.user_id` | 1:N | CASCADE | Delete comments when user deleted |
| `users` | `task_history.user_id` | 1:N | SET NULL | Preserve history even if user deleted |
| `tasks` | `tasks.parent_id` | 1:N (self) | CASCADE | Delete subtasks with parent |
| `tasks` | `task_assignments` | 1:N | CASCADE | Delete assignments when task deleted |
| `tasks` | `task_comments` | 1:N | CASCADE | Delete comments when task deleted |
| `tasks` | `task_history` | 1:N | CASCADE | Delete history when task deleted |
| `projects` | `tasks.project_id` | 1:N | CASCADE | Delete tasks when project deleted |
| `task_comments` | `task_comments.parent_comment_id` | 1:N (self) | CASCADE | Delete replies with parent |

---

## Table Specifications

### 1. TASKS Table

**Purpose:** Core task entity with status workflow, priority, and scheduling.

**Columns:**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `tenant_id` | INT UNSIGNED | NO | - | Multi-tenant isolation (MANDATORY) |
| `title` | VARCHAR(500) | NO | - | Task title (searchable) |
| `description` | TEXT | YES | NULL | Detailed description |
| `parent_id` | INT UNSIGNED | YES | NULL | Parent task for subtasks |
| `order_index` | INT UNSIGNED | YES | 0 | Sort order within parent/status |
| `created_by` | INT UNSIGNED | NO | - | Task creator (cannot be deleted) |
| `assigned_to` | INT UNSIGNED | YES | NULL | Primary assignee (legacy) |
| `project_id` | INT UNSIGNED | YES | NULL | Optional project link |
| `status` | ENUM | NO | 'todo' | Workflow status |
| `priority` | ENUM | NO | 'medium' | Task priority |
| `due_date` | DATETIME | YES | NULL | Deadline |
| `start_date` | DATETIME | YES | NULL | Planned start |
| `estimated_hours` | DECIMAL(8,2) | YES | NULL | Estimated effort |
| `actual_hours` | DECIMAL(8,2) | YES | NULL | Actual time spent |
| `progress_percentage` | TINYINT UNSIGNED | YES | 0 | Completion (0-100) |
| `tags` | JSON | YES | NULL | Tags array |
| `attachments` | JSON | YES | NULL | File references |
| `completed_at` | TIMESTAMP | YES | NULL | Completion timestamp |
| `completed_by` | INT UNSIGNED | YES | NULL | Who completed |
| `deleted_at` | TIMESTAMP | YES | NULL | Soft delete (MANDATORY) |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Updated timestamp |

**Status Enum Values:**
- `todo` - Not started
- `in_progress` - Work in progress
- `review` - Ready for review
- `done` - Completed
- `cancelled` - Cancelled/aborted

**Priority Enum Values:**
- `low` - Low priority
- `medium` - Normal priority
- `high` - High priority
- `critical` - Critical/urgent

**Indexes:**
- PRIMARY KEY: `id`
- `idx_tasks_tenant_created`: (tenant_id, created_at) - Chronological listing
- `idx_tasks_tenant_deleted`: (tenant_id, deleted_at) - Soft delete filter
- `idx_tasks_tenant_status`: (tenant_id, status, deleted_at) - Kanban queries
- `idx_tasks_tenant_priority`: (tenant_id, priority, deleted_at) - Priority filter
- `idx_tasks_tenant_due`: (tenant_id, due_date, deleted_at) - Deadline queries
- `idx_tasks_assigned_to`: (assigned_to, status) - My tasks
- `idx_tasks_created_by`: (created_by) - Created by me
- `idx_tasks_parent`: (parent_id, order_index) - Subtask hierarchy
- `idx_tasks_project`: (project_id, status) - Project tasks
- FULLTEXT `idx_tasks_search`: (title, description) - Full-text search

---

### 2. TASK_ASSIGNMENTS Table

**Purpose:** N:N relationship for multi-user task assignments with roles.

**Columns:**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `tenant_id` | INT UNSIGNED | NO | - | Multi-tenant isolation |
| `task_id` | INT UNSIGNED | NO | - | Task being assigned |
| `user_id` | INT UNSIGNED | NO | - | User assigned |
| `assigned_by` | INT UNSIGNED | NO | - | Who made assignment |
| `role` | ENUM | NO | 'contributor' | Assignment role |
| `assigned_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Assignment timestamp |
| `accepted_at` | TIMESTAMP | YES | NULL | When user accepted |
| `deleted_at` | TIMESTAMP | YES | NULL | Soft delete |

**Role Enum Values:**
- `owner` - Task owner (full control)
- `contributor` - Can work on task
- `reviewer` - Can review/approve

**Unique Constraint:**
- `uk_task_assignment`: (task_id, user_id, deleted_at) - Prevent duplicate active assignments

**Indexes:**
- PRIMARY KEY: `id`
- `idx_task_assignments_tenant`: (tenant_id, deleted_at)
- `idx_task_assignments_task`: (task_id, deleted_at) - List assignees
- `idx_task_assignments_user`: (user_id, deleted_at) - User's tasks

---

### 3. TASK_COMMENTS Table

**Purpose:** Threaded comments on tasks with attachments and edit tracking.

**Columns:**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `tenant_id` | INT UNSIGNED | NO | - | Multi-tenant isolation |
| `task_id` | INT UNSIGNED | NO | - | Task being commented on |
| `user_id` | INT UNSIGNED | NO | - | Comment author |
| `parent_comment_id` | INT UNSIGNED | YES | NULL | Parent comment (threading) |
| `content` | TEXT | NO | - | Comment text |
| `attachments` | JSON | YES | NULL | File attachments |
| `is_edited` | BOOLEAN | NO | FALSE | Edit flag |
| `edited_at` | TIMESTAMP | YES | NULL | Last edit timestamp |
| `deleted_at` | TIMESTAMP | YES | NULL | Soft delete |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Updated timestamp |

**Indexes:**
- PRIMARY KEY: `id`
- `idx_task_comments_tenant`: (tenant_id, deleted_at)
- `idx_task_comments_task`: (task_id, deleted_at, created_at) - Task timeline
- `idx_task_comments_user`: (user_id) - User comments
- `idx_task_comments_parent`: (parent_comment_id) - Threading
- FULLTEXT `idx_task_comments_search`: (content) - Search comments

---

### 4. TASK_HISTORY Table

**Purpose:** Complete audit trail of all task changes.

**Columns:**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | Primary key (large for high volume) |
| `tenant_id` | INT UNSIGNED | NO | - | Multi-tenant isolation |
| `task_id` | INT UNSIGNED | NO | - | Task being audited |
| `user_id` | INT UNSIGNED | YES | NULL | Who made change (NULL=system) |
| `action` | VARCHAR(100) | NO | - | Action type |
| `field_name` | VARCHAR(100) | YES | NULL | Field changed |
| `old_value` | TEXT | YES | NULL | Previous value |
| `new_value` | TEXT | YES | NULL | New value |
| `ip_address` | VARCHAR(45) | YES | NULL | User IP |
| `user_agent` | VARCHAR(500) | YES | NULL | Browser/client |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Change timestamp |

**Common Action Values:**
- `created` - Task created
- `updated` - General update
- `status_changed` - Status workflow change
- `assigned` - User assignment
- `completed` - Task completion
- `deleted` - Soft delete
- `restored` - Un-deleted

**Indexes:**
- PRIMARY KEY: `id`
- `idx_task_history_tenant`: (tenant_id, created_at)
- `idx_task_history_task`: (task_id, created_at DESC) - Task timeline
- `idx_task_history_user`: (user_id, created_at DESC) - User activity
- `idx_task_history_action`: (action, created_at) - Action queries

---

## Indexes and Performance

### Multi-Tenant Query Optimization

**MANDATORY Pattern:** Every query MUST include both `tenant_id` and `deleted_at` filters:

```sql
-- ✅ CORRECT
SELECT * FROM tasks
WHERE tenant_id = ?
  AND deleted_at IS NULL
  AND status = 'todo';

-- ❌ WRONG - Security vulnerability!
SELECT * FROM tasks
WHERE status = 'todo';
```

### Index Strategy

1. **Composite Indexes:** All tables have `(tenant_id, deleted_at)` composite indexes for multi-tenant soft delete queries.

2. **Status Queries:** `idx_tasks_tenant_status` (tenant_id, status, deleted_at) enables efficient Kanban board queries.

3. **User-Centric Queries:** Separate indexes on `assigned_to` and `created_by` for "My Tasks" views.

4. **Full-Text Search:** FULLTEXT indexes on `tasks.title`, `tasks.description`, and `task_comments.content` for search functionality.

5. **Hierarchical Queries:** `idx_tasks_parent` (parent_id, order_index) for subtask ordering.

### Query Performance Tips

- **Use covering indexes:** Select only needed columns to leverage index-only scans
- **Avoid SELECT *:** Specify columns to reduce I/O
- **Use LIMIT:** For paginated results, always use LIMIT with appropriate OFFSET
- **Leverage views:** Pre-built views like `view_my_tasks` use optimized queries
- **Batch operations:** Use transactions for bulk inserts/updates

---

## Views and Helper Functions

### View: view_orphaned_tasks

**Purpose:** Identify tasks assigned to deleted or invalid users.

**Usage:**
```sql
-- Get all orphaned tasks for tenant
SELECT * FROM view_orphaned_tasks
WHERE tenant_id = 1;

-- Count orphaned tasks
SELECT COUNT(*) as orphan_count
FROM view_orphaned_tasks
WHERE tenant_id = 1;
```

**Columns:**
- `id`, `tenant_id`, `title`, `status`, `priority`, `due_date`
- `assigned_to`, `created_by`, `created_at`
- `orphan_reason` - Why task is orphaned

**Orphan Reasons:**
- "No assignee" - `assigned_to` is NULL
- "Assigned user deleted" - User hard deleted (shouldn't happen)
- "Assigned user soft-deleted" - User has `deleted_at` set
- "Assigned user in different tenant" - Cross-tenant assignment (data integrity issue)

---

### View: view_task_summary_by_status

**Purpose:** Dashboard summary statistics grouped by status.

**Usage:**
```sql
-- Get summary for tenant
SELECT * FROM view_task_summary_by_status
WHERE tenant_id = 1;
```

**Columns:**
- `tenant_id` - Tenant ID
- `status` - Task status
- `task_count` - Total tasks in status
- `critical_count` - Critical priority count
- `high_count` - High priority count
- `overdue_count` - Overdue tasks in status

---

### View: view_my_tasks

**Purpose:** User-friendly view of tasks with computed fields.

**Usage:**
```sql
-- Get my tasks (requires application-level filtering by user_id)
SELECT * FROM view_my_tasks
WHERE tenant_id = 1
  AND (assigned_to = ? OR created_by = ?)
  AND deleted_at IS NULL
ORDER BY is_overdue DESC, due_date ASC;
```

**Computed Columns:**
- `is_overdue` - Boolean flag if past due date
- `days_until_due` - Days remaining (negative if overdue)
- `created_by_email` - Creator email address
- `created_by_name` - Creator full name

---

### Function: assign_task_to_user()

**Purpose:** Safely assign task to user with validation and audit logging.

**Signature:**
```sql
assign_task_to_user(
    p_task_id INT UNSIGNED,
    p_user_id INT UNSIGNED,
    p_assigned_by INT UNSIGNED
) RETURNS VARCHAR(255)
```

**Usage:**
```sql
-- Assign task 100 to user 5, assigned by user 1
SELECT assign_task_to_user(100, 5, 1) as result;
-- Returns: 'SUCCESS: Task assigned' or 'ERROR: ...'
```

**Validation:**
- Task exists and not soft-deleted
- User exists and not soft-deleted
- User belongs to same tenant as task
- Creates audit trail entry

**Side Effects:**
- Inserts into `task_assignments`
- Updates `tasks.assigned_to` if NULL
- Creates `task_history` entry

---

### Function: get_orphaned_tasks_count()

**Purpose:** Get count of orphaned tasks for a tenant.

**Signature:**
```sql
get_orphaned_tasks_count(p_tenant_id INT UNSIGNED) RETURNS INT
```

**Usage:**
```sql
-- Get orphan count for tenant 1
SELECT get_orphaned_tasks_count(1) as orphan_count;
```

**Use Case:** Dashboard warning badges, health checks, scheduled notifications.

---

## Common Queries

### 1. Kanban Board Query

Get tasks grouped by status for drag-and-drop board:

```sql
SELECT
    t.id,
    t.title,
    t.description,
    t.status,
    t.priority,
    t.due_date,
    t.progress_percentage,
    CONCAT(u.first_name, ' ', u.last_name) as assignee_name,
    u.avatar_url as assignee_avatar,
    -- Check if overdue
    CASE
        WHEN t.due_date < NOW() AND t.status NOT IN ('done', 'cancelled') THEN 1
        ELSE 0
    END as is_overdue
FROM tasks t
LEFT JOIN users u ON t.assigned_to = u.id AND u.deleted_at IS NULL
WHERE t.tenant_id = ?
  AND t.deleted_at IS NULL
  AND t.parent_id IS NULL  -- Top-level tasks only
ORDER BY t.order_index ASC, t.created_at DESC;
```

---

### 2. My Tasks Query

Get tasks assigned to or created by current user:

```sql
SELECT
    t.id,
    t.title,
    t.status,
    t.priority,
    t.due_date,
    t.progress_percentage,
    p.name as project_name,
    CASE
        WHEN t.assigned_to = ? THEN 'assigned'
        WHEN t.created_by = ? THEN 'created'
        ELSE 'other'
    END as relation_type
FROM tasks t
LEFT JOIN projects p ON t.project_id = p.id AND p.deleted_at IS NULL
WHERE t.tenant_id = ?
  AND t.deleted_at IS NULL
  AND (t.assigned_to = ? OR t.created_by = ?)
  AND t.status NOT IN ('done', 'cancelled')
ORDER BY
    CASE WHEN t.due_date < NOW() THEN 0 ELSE 1 END,  -- Overdue first
    t.priority DESC,
    t.due_date ASC;
```

---

### 3. Overdue Tasks Query

Get all overdue tasks with assignee info:

```sql
SELECT
    t.id,
    t.title,
    t.status,
    t.priority,
    t.due_date,
    DATEDIFF(NOW(), t.due_date) as days_overdue,
    CONCAT(u.first_name, ' ', u.last_name) as assignee_name,
    u.email as assignee_email
FROM tasks t
LEFT JOIN users u ON t.assigned_to = u.id AND u.deleted_at IS NULL
WHERE t.tenant_id = ?
  AND t.deleted_at IS NULL
  AND t.due_date < NOW()
  AND t.status NOT IN ('done', 'cancelled')
ORDER BY t.priority DESC, t.due_date ASC;
```

---

### 4. Task Detail with All Relationships

Get complete task details including assignees, comments, history:

```sql
-- Main task info
SELECT
    t.*,
    CONCAT(u_creator.first_name, ' ', u_creator.last_name) as creator_name,
    CONCAT(u_assignee.first_name, ' ', u_assignee.last_name) as assignee_name,
    p.name as project_name,
    parent.title as parent_task_title
FROM tasks t
LEFT JOIN users u_creator ON t.created_by = u_creator.id
LEFT JOIN users u_assignee ON t.assigned_to = u_assignee.id
LEFT JOIN projects p ON t.project_id = p.id AND p.deleted_at IS NULL
LEFT JOIN tasks parent ON t.parent_id = parent.id AND parent.deleted_at IS NULL
WHERE t.id = ?
  AND t.tenant_id = ?
  AND t.deleted_at IS NULL;

-- All assignees
SELECT
    u.id,
    CONCAT(u.first_name, ' ', u.last_name) as name,
    u.email,
    u.avatar_url,
    ta.role,
    ta.assigned_at
FROM task_assignments ta
INNER JOIN users u ON ta.user_id = u.id AND u.deleted_at IS NULL
WHERE ta.task_id = ?
  AND ta.tenant_id = ?
  AND ta.deleted_at IS NULL
ORDER BY ta.assigned_at DESC;

-- Recent comments
SELECT
    tc.id,
    tc.content,
    tc.created_at,
    tc.is_edited,
    CONCAT(u.first_name, ' ', u.last_name) as author_name,
    u.avatar_url as author_avatar
FROM task_comments tc
INNER JOIN users u ON tc.user_id = u.id AND u.deleted_at IS NULL
WHERE tc.task_id = ?
  AND tc.tenant_id = ?
  AND tc.deleted_at IS NULL
ORDER BY tc.created_at DESC
LIMIT 10;

-- Recent history
SELECT
    th.action,
    th.field_name,
    th.old_value,
    th.new_value,
    th.created_at,
    CONCAT(u.first_name, ' ', u.last_name) as user_name
FROM task_history th
LEFT JOIN users u ON th.user_id = u.id
WHERE th.task_id = ?
  AND th.tenant_id = ?
ORDER BY th.created_at DESC
LIMIT 20;
```

---

### 5. Subtask Hierarchy Query

Get task with all subtasks:

```sql
-- Get parent task
SELECT * FROM tasks
WHERE id = ?
  AND tenant_id = ?
  AND deleted_at IS NULL;

-- Get all subtasks (flat list)
SELECT
    t.id,
    t.title,
    t.status,
    t.priority,
    t.progress_percentage,
    t.order_index,
    CONCAT(u.first_name, ' ', u.last_name) as assignee_name
FROM tasks t
LEFT JOIN users u ON t.assigned_to = u.id AND u.deleted_at IS NULL
WHERE t.parent_id = ?
  AND t.tenant_id = ?
  AND t.deleted_at IS NULL
ORDER BY t.order_index ASC, t.created_at ASC;
```

---

### 6. Full-Text Search Query

Search tasks by title and description:

```sql
SELECT
    t.id,
    t.title,
    t.description,
    t.status,
    t.priority,
    t.due_date,
    CONCAT(u.first_name, ' ', u.last_name) as assignee_name,
    MATCH(t.title, t.description) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance
FROM tasks t
LEFT JOIN users u ON t.assigned_to = u.id AND u.deleted_at IS NULL
WHERE t.tenant_id = ?
  AND t.deleted_at IS NULL
  AND MATCH(t.title, t.description) AGAINST(? IN NATURAL LANGUAGE MODE)
ORDER BY relevance DESC, t.created_at DESC
LIMIT 50;
```

---

### 7. Task Statistics Query

Get comprehensive stats for dashboard:

```sql
SELECT
    -- Total counts by status
    COUNT(*) as total_tasks,
    COUNT(CASE WHEN status = 'todo' THEN 1 END) as todo_count,
    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,
    COUNT(CASE WHEN status = 'review' THEN 1 END) as review_count,
    COUNT(CASE WHEN status = 'done' THEN 1 END) as done_count,

    -- Priority counts
    COUNT(CASE WHEN priority = 'critical' THEN 1 END) as critical_count,
    COUNT(CASE WHEN priority = 'high' THEN 1 END) as high_count,

    -- Overdue
    COUNT(CASE WHEN due_date < NOW() AND status NOT IN ('done', 'cancelled') THEN 1 END) as overdue_count,

    -- Completion metrics
    AVG(CASE WHEN status = 'done' THEN progress_percentage ELSE NULL END) as avg_completion,

    -- Time tracking
    SUM(estimated_hours) as total_estimated_hours,
    SUM(actual_hours) as total_actual_hours
FROM tasks
WHERE tenant_id = ?
  AND deleted_at IS NULL;
```

---

## Orphaned Task Handling

### What is an Orphaned Task?

A task is considered "orphaned" when:
1. `assigned_to` is NULL (no assignee)
2. Assigned user has been soft-deleted (`users.deleted_at IS NOT NULL`)
3. Assigned user has been hard-deleted (shouldn't happen with RESTRICT constraint)
4. Assigned user belongs to different tenant (data integrity issue)

### Detection

**Automated Detection:** Use `view_orphaned_tasks` view:

```sql
-- Get all orphaned tasks
SELECT * FROM view_orphaned_tasks
WHERE tenant_id = 1;

-- Count orphans
SELECT get_orphaned_tasks_count(1) as count;
```

**Manual Detection:**
```sql
SELECT
    t.id,
    t.title,
    t.status,
    t.assigned_to,
    u.id as user_exists,
    u.deleted_at as user_deleted_at
FROM tasks t
LEFT JOIN users u ON t.assigned_to = u.id
WHERE t.tenant_id = ?
  AND t.deleted_at IS NULL
  AND t.status NOT IN ('done', 'cancelled')
  AND (
      t.assigned_to IS NULL
      OR u.id IS NULL
      OR u.deleted_at IS NOT NULL
  );
```

---

### Handling Orphaned Tasks

**Option 1: Reassign to Manager**
```sql
-- Reassign all orphaned tasks to default manager
UPDATE tasks t
SET assigned_to = ?, updated_at = NOW()
WHERE t.id IN (
    SELECT id FROM view_orphaned_tasks
    WHERE tenant_id = ?
);
```

**Option 2: Mark as Unassigned**
```sql
-- Set assigned_to to NULL for orphaned tasks
UPDATE tasks
SET assigned_to = NULL, updated_at = NOW()
WHERE id IN (
    SELECT id FROM view_orphaned_tasks
    WHERE tenant_id = ?
);
```

**Option 3: Notify and Warn**
```sql
-- Get orphans for notification email
SELECT
    t.id,
    t.title,
    t.priority,
    t.due_date,
    v.orphan_reason
FROM view_orphaned_tasks v
INNER JOIN tasks t ON v.id = t.id
WHERE v.tenant_id = ?
ORDER BY
    CASE t.priority
        WHEN 'critical' THEN 1
        WHEN 'high' THEN 2
        WHEN 'medium' THEN 3
        ELSE 4
    END,
    t.due_date ASC;
```

---

### Prevention

**Use `task_assignments` table instead of `tasks.assigned_to`:**
- Multiple assignees provide redundancy
- When one user deleted, others remain
- Track assignment history

**Implement CASCADE appropriately:**
- `task_assignments.user_id` uses CASCADE so assignments auto-removed
- `tasks.created_by` uses RESTRICT to prevent creator deletion
- `tasks.assigned_to` uses SET NULL to preserve task

**Application-level checks:**
```php
// Before deleting user, check for assigned tasks
$orphanCount = $db->fetchOne(
    'SELECT COUNT(*) FROM tasks WHERE assigned_to = ? AND deleted_at IS NULL',
    [$userId]
);

if ($orphanCount > 0) {
    // Reassign or warn before deletion
    throw new Exception("User has {$orphanCount} assigned tasks. Reassign before deletion.");
}
```

---

## Multi-Tenant Considerations

### MANDATORY Patterns

**Every Query Must Include:**
```sql
WHERE tenant_id = ? AND deleted_at IS NULL
```

**Exception:** `super_admin` role can bypass tenant isolation:
```php
if ($currentUser['role'] !== 'super_admin') {
    $whereClauses[] = 'tenant_id = ?';
    $params[] = $currentUser['tenant_id'];
}
```

---

### Cross-Tenant Task Assignment

**FORBIDDEN:** Never assign task from tenant A to user from tenant B:

```sql
-- This should fail due to validation in assign_task_to_user() function
SELECT assign_task_to_user(100, 999, 1);
-- Returns: 'ERROR: User belongs to different tenant'
```

**Validation in Application:**
```php
// Verify user belongs to task's tenant
$taskTenantId = $db->fetchOne('SELECT tenant_id FROM tasks WHERE id = ?', [$taskId]);
$userTenantId = $db->fetchOne('SELECT tenant_id FROM users WHERE id = ?', [$userId]);

if ($taskTenantId !== $userTenantId) {
    throw new Exception('Cannot assign task to user from different tenant');
}
```

---

### Multi-Tenant Users

**Special Case:** Admin/super_admin users may have access to multiple tenants via `user_tenant_access` table.

**Query Pattern:**
```sql
-- Get tenants accessible by user
SELECT tenant_id
FROM user_tenant_access
WHERE user_id = ? AND deleted_at IS NULL;

-- Get tasks from accessible tenants
SELECT t.*
FROM tasks t
WHERE t.deleted_at IS NULL
  AND t.tenant_id IN (
      SELECT tenant_id FROM user_tenant_access
      WHERE user_id = ? AND deleted_at IS NULL
  );
```

---

## Migration Guide

### Pre-Migration Checklist

- [ ] Backup production database
- [ ] Verify MySQL version >= 8.0
- [ ] Check free disk space (estimate: 10-20% of existing DB size)
- [ ] Review existing `tasks` table (if exists) for data migration needs
- [ ] Identify peak usage hours to schedule migration
- [ ] Notify users of maintenance window

---

### Migration Steps

**Step 1: Backup**
```bash
mysqldump -u root -p collaboranexio > backup_before_task_migration_$(date +%Y%m%d_%H%M%S).sql
```

**Step 2: Run Migration**
```bash
mysql -u root -p collaboranexio < database/migrations/task_management_schema.sql
```

**Step 3: Verify**
```sql
-- Check tables created
SHOW TABLES LIKE 'task%';

-- Check record counts
SELECT 'tasks' as tbl, COUNT(*) FROM tasks
UNION ALL SELECT 'task_assignments', COUNT(*) FROM task_assignments
UNION ALL SELECT 'task_comments', COUNT(*) FROM task_comments
UNION ALL SELECT 'task_history', COUNT(*) FROM task_history;

-- Check views exist
SHOW FULL TABLES WHERE Table_type = 'VIEW';

-- Check functions exist
SELECT ROUTINE_NAME FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio'
  AND ROUTINE_NAME LIKE '%task%';
```

**Step 4: Test**
```sql
-- Test orphan detection
SELECT COUNT(*) FROM view_orphaned_tasks;

-- Test function
SELECT assign_task_to_user(1, 1, 1);
```

---

### Rollback Procedure

**If migration fails:**
```bash
# Restore from backup
mysql -u root -p collaboranexio < backup_before_task_migration_YYYYMMDD_HHMMSS.sql

# Or run rollback script
mysql -u root -p collaboranexio < database/migrations/task_management_schema_rollback.sql
```

---

### Post-Migration Tasks

- [ ] Update application code to use new schema
- [ ] Rebuild full-text search indexes if needed
- [ ] Run ANALYZE TABLE for query optimizer
- [ ] Update documentation
- [ ] Train users on new task features
- [ ] Monitor performance for first 24-48 hours

---

## Testing Checklist

### Functional Tests

- [ ] **Create Task:** Insert new task with all required fields
- [ ] **Multi-Tenant Isolation:** Verify tenant A cannot see tenant B tasks
- [ ] **Soft Delete:** Delete task, verify `deleted_at` set, task hidden from queries
- [ ] **Hierarchical Tasks:** Create parent task, add subtasks, verify cascade delete
- [ ] **Multi-User Assignment:** Assign task to multiple users via `task_assignments`
- [ ] **Orphan Detection:** Delete assigned user, verify task appears in `view_orphaned_tasks`
- [ ] **Comments:** Add comment, reply to comment (threading), edit comment
- [ ] **Audit Trail:** Update task, verify `task_history` entry created
- [ ] **Full-Text Search:** Search tasks by title/description
- [ ] **Status Workflow:** Move task through statuses: todo → in_progress → review → done

---

### Performance Tests

- [ ] **Kanban Query:** Load 1000+ tasks, measure query time (target: < 100ms)
- [ ] **My Tasks Query:** Filter by assigned user, measure time (target: < 50ms)
- [ ] **Full-Text Search:** Search across 10K+ tasks (target: < 200ms)
- [ ] **Bulk Insert:** Insert 100 tasks in transaction (target: < 1s)
- [ ] **Index Usage:** Verify EXPLAIN shows index usage for common queries

---

### Security Tests

- [ ] **Tenant Isolation:** User from tenant A attempts to access tenant B task (should fail)
- [ ] **Soft Delete:** Verify deleted tasks not returned in queries
- [ ] **SQL Injection:** Test prepared statements with malicious input
- [ ] **Cross-Tenant Assignment:** Attempt to assign task to user from different tenant (should fail via function)

---

### Edge Cases

- [ ] **Orphan Tasks:** Delete assigned user, verify task handling
- [ ] **Circular References:** Attempt to set task as its own parent (should fail)
- [ ] **Invalid Progress:** Attempt to set progress > 100% (should fail via constraint)
- [ ] **Date Validation:** Set start_date > due_date (should fail via constraint)
- [ ] **Duplicate Assignment:** Assign same user twice to task (should fail via unique constraint)

---

## Performance Optimization Tips

### Query Optimization

1. **Use Indexes:** Always filter on indexed columns (tenant_id, status, assigned_to)
2. **Avoid SELECT *:** Select only needed columns
3. **Use LIMIT:** For paginated results
4. **Covering Indexes:** Query uses only indexed columns (no table access)
5. **Query Cache:** Enable for read-heavy workloads

---

### Table Optimization

```sql
-- Analyze tables for query optimizer
ANALYZE TABLE tasks, task_assignments, task_comments, task_history;

-- Optimize tables (defragment)
OPTIMIZE TABLE tasks, task_assignments, task_comments, task_history;

-- Rebuild indexes if fragmented
ALTER TABLE tasks ENGINE=InnoDB;
```

---

### Partitioning (For Large Datasets)

If `tasks` table exceeds 1M rows, consider partitioning by tenant_id or created_at:

```sql
-- Partition by tenant_id (range)
ALTER TABLE tasks
PARTITION BY RANGE (tenant_id) (
    PARTITION p0 VALUES LESS THAN (10),
    PARTITION p1 VALUES LESS THAN (100),
    PARTITION p2 VALUES LESS THAN (1000),
    PARTITION p3 VALUES LESS THAN MAXVALUE
);

-- Or partition by date (for archival)
ALTER TABLE task_history
PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

---

## Appendix: Example Data Flow

### Creating a Task with Assignments

**Step 1:** Create task
```sql
INSERT INTO tasks (tenant_id, title, description, status, priority, created_by, due_date)
VALUES (1, 'Build new feature', 'Implement user dashboard', 'todo', 'high', 10, '2025-11-01 23:59:59');

SET @task_id = LAST_INSERT_ID();
```

**Step 2:** Assign multiple users
```sql
-- Using function (recommended)
SELECT assign_task_to_user(@task_id, 11, 10);  -- Assign to user 11
SELECT assign_task_to_user(@task_id, 12, 10);  -- Assign to user 12

-- Or direct insert
INSERT INTO task_assignments (tenant_id, task_id, user_id, assigned_by, role)
VALUES
    (1, @task_id, 11, 10, 'owner'),
    (1, @task_id, 12, 10, 'contributor');
```

**Step 3:** Add comment
```sql
INSERT INTO task_comments (tenant_id, task_id, user_id, content)
VALUES (1, @task_id, 10, 'Please review the requirements document before starting');
```

**Step 4:** Update status
```sql
UPDATE tasks
SET status = 'in_progress', updated_at = NOW()
WHERE id = @task_id;

-- Audit trail auto-created by application trigger or manual insert
INSERT INTO task_history (tenant_id, task_id, user_id, action, field_name, old_value, new_value)
VALUES (1, @task_id, 11, 'status_changed', 'status', 'todo', 'in_progress');
```

---

## Support and Maintenance

**Documentation Version:** 2025-10-24
**Schema Version:** 1.0.0
**Compatible with:** CollaboraNexio v2.x, MySQL 8.0+

**For issues or questions:**
- Check `CLAUDE.md` for project conventions
- Review `bug.md` for known issues
- Update `progression.md` with schema changes

---

**END OF DOCUMENTATION**
