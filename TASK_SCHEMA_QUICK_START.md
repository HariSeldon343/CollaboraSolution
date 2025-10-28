# Task Management Schema - Quick Start Guide

**Version:** 2025-10-24
**Status:** Production-Ready
**Author:** Database Architect

---

## TL;DR

Complete task management database schema for CollaboraNexio multi-tenant platform:
- **4 tables:** tasks, task_assignments, task_comments, task_history
- **3 views:** orphaned tasks, summary by status, my tasks
- **2 functions:** assign task, count orphans
- **Full compliance:** Multi-tenancy, soft delete, audit trail, performance indexes

---

## Installation

### Quick Install
```bash
# Navigate to project root
cd /mnt/c/xampp/htdocs/CollaboraNexio

# Backup database (REQUIRED!)
mysqldump -u root -p collaboranexio > backup_$(date +%Y%m%d_%H%M%S).sql

# Run migration
mysql -u root -p collaboranexio < database/migrations/task_management_schema.sql
```

### Verify Installation
```sql
-- Check tables created
SHOW TABLES LIKE 'task%';
-- Expected: tasks, task_assignments, task_comments, task_history

-- Check views
SHOW FULL TABLES WHERE Table_type = 'VIEW';
-- Expected: view_orphaned_tasks, view_task_summary_by_status, view_my_tasks

-- Check functions
SELECT ROUTINE_NAME FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'collaboranexio' AND ROUTINE_NAME LIKE '%task%';
-- Expected: assign_task_to_user, get_orphaned_tasks_count
```

---

## Core Tables Overview

### 1. TASKS (Main Entity)
```
Primary Fields:
- id, tenant_id, title, description
- status (todo|in_progress|review|done|cancelled)
- priority (low|medium|high|critical)
- due_date, progress_percentage
- created_by, assigned_to
- deleted_at (soft delete)

Key Features:
- Hierarchical (parent_id for subtasks)
- Time tracking (estimated/actual hours)
- Full-text search on title/description
- 15+ performance indexes
```

### 2. TASK_ASSIGNMENTS (Multi-User N:N)
```
Purpose: Assign multiple users to one task

Fields:
- task_id, user_id, assigned_by
- role (owner|contributor|reviewer)
- assigned_at, accepted_at

Benefits:
- Multiple assignees per task
- Role-based access
- Assignment audit trail
```

### 3. TASK_COMMENTS (Threaded Discussion)
```
Purpose: Task comments with threading

Fields:
- task_id, user_id, content
- parent_comment_id (for replies)
- is_edited, edited_at
- attachments (JSON)

Features:
- Nested comments (replies)
- Edit tracking
- Full-text search
```

### 4. TASK_HISTORY (Audit Trail)
```
Purpose: Complete change history

Fields:
- task_id, user_id, action
- field_name, old_value, new_value
- ip_address, user_agent

Note: NO soft delete (preserve history)
```

---

## Essential Queries

### Get All Tasks (Kanban Style)
```sql
SELECT
    t.id,
    t.title,
    t.status,
    t.priority,
    t.due_date,
    CONCAT(u.first_name, ' ', u.last_name) as assignee
FROM tasks t
LEFT JOIN users u ON t.assigned_to = u.id AND u.deleted_at IS NULL
WHERE t.tenant_id = ?
  AND t.deleted_at IS NULL
  AND t.parent_id IS NULL
ORDER BY t.order_index ASC;
```

### Get My Tasks
```sql
SELECT *
FROM tasks
WHERE tenant_id = ?
  AND deleted_at IS NULL
  AND (assigned_to = ? OR created_by = ?)
  AND status NOT IN ('done', 'cancelled')
ORDER BY
    CASE WHEN due_date < NOW() THEN 0 ELSE 1 END,
    priority DESC,
    due_date ASC;
```

### Get Orphaned Tasks
```sql
SELECT * FROM view_orphaned_tasks
WHERE tenant_id = ?;
```

### Task Detail with Comments
```sql
-- Main task
SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) as creator
FROM tasks t
LEFT JOIN users u ON t.created_by = u.id
WHERE t.id = ? AND t.tenant_id = ? AND t.deleted_at IS NULL;

-- Comments
SELECT
    tc.id,
    tc.content,
    tc.created_at,
    CONCAT(u.first_name, ' ', u.last_name) as author
FROM task_comments tc
INNER JOIN users u ON tc.user_id = u.id
WHERE tc.task_id = ? AND tc.tenant_id = ? AND tc.deleted_at IS NULL
ORDER BY tc.created_at DESC;
```

---

## API Integration Examples (PHP)

### Create Task
```php
<?php
require_once __DIR__ . '/includes/db.php';

$db = Database::getInstance();

// Insert task
$taskId = $db->insert('tasks', [
    'tenant_id' => $currentUser['tenant_id'],
    'title' => 'New Task',
    'description' => 'Task description',
    'status' => 'todo',
    'priority' => 'medium',
    'created_by' => $currentUser['id'],
    'due_date' => '2025-11-01 23:59:59'
]);

// Assign multiple users
$db->insert('task_assignments', [
    'tenant_id' => $currentUser['tenant_id'],
    'task_id' => $taskId,
    'user_id' => 10,
    'assigned_by' => $currentUser['id'],
    'role' => 'owner'
]);

// Log to history
$db->insert('task_history', [
    'tenant_id' => $currentUser['tenant_id'],
    'task_id' => $taskId,
    'user_id' => $currentUser['id'],
    'action' => 'created',
    'new_value' => json_encode(['title' => 'New Task'])
]);

echo json_encode(['success' => true, 'task_id' => $taskId]);
```

### Update Task Status
```php
<?php
// Get old value for audit
$oldTask = $db->fetchOne('SELECT * FROM tasks WHERE id = ?', [$taskId]);

// Update status
$db->update('tasks', [
    'status' => 'in_progress',
    'updated_at' => date('Y-m-d H:i:s')
], ['id' => $taskId]);

// Log change
$db->insert('task_history', [
    'tenant_id' => $currentUser['tenant_id'],
    'task_id' => $taskId,
    'user_id' => $currentUser['id'],
    'action' => 'status_changed',
    'field_name' => 'status',
    'old_value' => $oldTask['status'],
    'new_value' => 'in_progress'
]);
```

### Add Comment
```php
<?php
$commentId = $db->insert('task_comments', [
    'tenant_id' => $currentUser['tenant_id'],
    'task_id' => $taskId,
    'user_id' => $currentUser['id'],
    'content' => 'This is a comment',
    'parent_comment_id' => null  // Or parent ID for reply
]);

echo json_encode(['success' => true, 'comment_id' => $commentId]);
```

### Assign Task (Using Function)
```php
<?php
$result = $db->fetchOne(
    'SELECT assign_task_to_user(?, ?, ?) as result',
    [$taskId, $userId, $currentUser['id']]
);

if (strpos($result['result'], 'SUCCESS') !== false) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $result['result']]);
}
```

---

## Frontend Integration (JavaScript)

### Load Tasks for Kanban
```javascript
async function loadTasks(tenantId) {
    const response = await fetch(`/api/tasks/list.php?tenant_id=${tenantId}`);
    const data = await response.json();

    // Group by status for Kanban columns
    const tasksByStatus = {
        todo: [],
        in_progress: [],
        review: [],
        done: []
    };

    data.tasks.forEach(task => {
        if (tasksByStatus[task.status]) {
            tasksByStatus[task.status].push(task);
        }
    });

    renderKanbanBoard(tasksByStatus);
}
```

### Update Task Status (Drag & Drop)
```javascript
async function updateTaskStatus(taskId, newStatus, csrfToken) {
    const response = await fetch('/api/tasks/update.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            task_id: taskId,
            status: newStatus
        })
    });

    return await response.json();
}
```

### Add Comment
```javascript
async function addComment(taskId, content, csrfToken) {
    const response = await fetch('/api/tasks/comment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            task_id: taskId,
            content: content
        })
    });

    return await response.json();
}
```

---

## Common Patterns

### Multi-Tenant Query Pattern (MANDATORY)
```sql
-- ✅ ALWAYS include both filters
WHERE tenant_id = ? AND deleted_at IS NULL

-- ❌ NEVER query without tenant_id (except super_admin)
WHERE status = 'todo'
```

### Soft Delete Pattern
```sql
-- ✅ Soft delete (CORRECT)
UPDATE tasks SET deleted_at = NOW() WHERE id = ?;

-- ❌ Hard delete (FORBIDDEN!)
DELETE FROM tasks WHERE id = ?;
```

### Orphaned Task Handling
```sql
-- Detect orphaned tasks
SELECT * FROM view_orphaned_tasks WHERE tenant_id = ?;

-- Reassign to manager
UPDATE tasks
SET assigned_to = ?, updated_at = NOW()
WHERE id IN (SELECT id FROM view_orphaned_tasks WHERE tenant_id = ?);

-- Or mark as unassigned
UPDATE tasks
SET assigned_to = NULL, updated_at = NOW()
WHERE id IN (SELECT id FROM view_orphaned_tasks WHERE tenant_id = ?);
```

---

## Performance Tips

1. **Always use indexes:** Filter on indexed columns (tenant_id, status, assigned_to)
2. **Avoid SELECT *:** Select only needed columns
3. **Use LIMIT:** For pagination
4. **Leverage views:** Use pre-built views for common queries
5. **Batch operations:** Use transactions for bulk updates

### Example: Efficient Kanban Query
```sql
-- Good: Uses idx_tasks_tenant_status composite index
SELECT id, title, status, priority
FROM tasks
WHERE tenant_id = ? AND deleted_at IS NULL AND status = 'todo'
ORDER BY order_index ASC
LIMIT 20;

-- Bad: Slow full table scan
SELECT * FROM tasks WHERE status = 'todo';
```

---

## Security Checklist

- [ ] Every query includes `tenant_id` filter
- [ ] Every query includes `deleted_at IS NULL` filter
- [ ] Use prepared statements (NO string concatenation)
- [ ] Validate user belongs to tenant before operations
- [ ] CSRF token validation on POST/PUT/DELETE
- [ ] Audit trail logged for sensitive operations

---

## Rollback

**If something goes wrong:**
```bash
# Restore from backup
mysql -u root -p collaboranexio < backup_YYYYMMDD_HHMMSS.sql

# Or run rollback script
mysql -u root -p collaboranexio < database/migrations/task_management_schema_rollback.sql
```

---

## Testing

### Quick Smoke Test
```sql
-- 1. Create test task
INSERT INTO tasks (tenant_id, title, status, priority, created_by)
VALUES (1, 'Test Task', 'todo', 'medium', 1);

SET @task_id = LAST_INSERT_ID();

-- 2. Assign user
SELECT assign_task_to_user(@task_id, 1, 1);

-- 3. Add comment
INSERT INTO task_comments (tenant_id, task_id, user_id, content)
VALUES (1, @task_id, 1, 'Test comment');

-- 4. Update status
UPDATE tasks SET status = 'done', completed_at = NOW() WHERE id = @task_id;

-- 5. Verify history
SELECT * FROM task_history WHERE task_id = @task_id;

-- 6. Check orphans
SELECT * FROM view_orphaned_tasks WHERE tenant_id = 1;

-- 7. Cleanup
UPDATE tasks SET deleted_at = NOW() WHERE id = @task_id;
```

---

## Documentation

**Full Documentation:**
- `/database/TASK_MANAGEMENT_SCHEMA_DOC.md` - Complete 1100+ line reference

**Schema Files:**
- `/database/migrations/task_management_schema.sql` - Migration script
- `/database/migrations/task_management_schema_rollback.sql` - Rollback script

**Project Documentation:**
- `/CLAUDE.md` - Project conventions and patterns
- `/progression.md` - Development history

---

## Support

**Common Issues:**

1. **"Cannot add foreign key constraint"**
   - Ensure `tenants` and `users` tables exist
   - Verify referenced IDs exist before inserting

2. **"Unknown column 'deleted_at'"**
   - Run migration script to add soft delete columns

3. **"Tasks from other tenants visible"**
   - Add `tenant_id` filter to query
   - Check `super_admin` bypass logic

4. **"Query slow on large dataset"**
   - Verify indexes: `SHOW INDEX FROM tasks;`
   - Use EXPLAIN to check query plan

---

## Next Steps

1. **Update Application:**
   - Modify `/tasks.php` to use new schema
   - Create API endpoints in `/api/tasks/`
   - Update frontend JavaScript

2. **Add Features:**
   - Task notifications
   - Email reminders for overdue tasks
   - Dashboard widgets with statistics
   - Bulk operations (bulk assign, bulk status update)

3. **Monitoring:**
   - Set up alerts for orphaned tasks
   - Monitor query performance
   - Track task completion metrics

---

**Ready to use!** Schema is production-ready and follows all CollaboraNexio patterns. Start by updating the frontend to call the database, then add API endpoints as needed.

**Questions?** Check full documentation in `TASK_MANAGEMENT_SCHEMA_DOC.md`.
