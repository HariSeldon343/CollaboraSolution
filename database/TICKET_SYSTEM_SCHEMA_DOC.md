# Support Ticket System Database Schema Documentation

**Version:** 2025-10-26
**Author:** Database Architect - CollaboraNexio
**Project:** CollaboraNexio Multi-Tenant Platform

---

## Table of Contents

1. [Overview](#overview)
2. [Entity Relationship Diagram](#entity-relationship-diagram)
3. [Table Specifications](#table-specifications)
4. [Indexes and Performance](#indexes-and-performance)
5. [Email Notification Workflow](#email-notification-workflow)
6. [Common Queries](#common-queries)
7. [Ticket Lifecycle Management](#ticket-lifecycle-management)
8. [Multi-Tenant Considerations](#multi-tenant-considerations)
9. [Migration Guide](#migration-guide)
10. [Testing Checklist](#testing-checklist)

---

## Overview

The Support Ticket System provides a complete help desk solution for CollaboraNexio with:

- **Multi-tenant isolation**: Every ticket is scoped to a specific tenant
- **Soft delete compliance**: No hard deletes, all records use `deleted_at` timestamp
- **Email notifications**: Automatic notifications to ticket owners and admins on status changes
- **Assignment tracking**: Complete history of who handled each ticket
- **Response threading**: Public responses and internal admin notes
- **Complete audit trail**: Every change tracked in `ticket_history` table
- **SLA metrics**: First response time and resolution time tracking

### Key Features

- ✅ Support for multiple categories (technical, billing, bug reports, feature requests)
- ✅ Four urgency levels (low, medium, high, critical)
- ✅ Workflow status tracking (open → in_progress → waiting_response → resolved → closed)
- ✅ File attachments support via JSON field
- ✅ Full-text search on subject and description
- ✅ Auto-generated ticket numbers (e.g., TICK-2025-0001)
- ✅ Email notification audit trail
- ✅ Internal admin notes (not visible to ticket creator)
- ✅ Assignment history tracking

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
│      TICKETS        │◄────────┤ TICKET_ASSIGNMENTS   │
│                     │  1:N    │                      │
│ - id (PK)           │         │ - id (PK)            │
│ - tenant_id (FK)    │         │ - ticket_id (FK)     │
│ - created_by (FK)   │         │ - assigned_to (FK)   │
│ - assigned_to (FK)  │         │ - assigned_by (FK)   │
│ - subject           │         │ - assigned_at        │
│ - description       │         │ - unassigned_at      │
│ - ticket_number     │         └──────────┬───────────┘
│ - category          │                    │
│ - urgency           │                    │ N:1
│ - status            │         ┌──────────▼──────────┐
│ - priority          │         │       USERS         │
│ - resolved_at       │         │  (existing table)   │
│ - closed_at         │         └─────────────────────┘
│ - deleted_at        │
└──────────┬──────────┘
           │
           │ 1:N
           │
┌──────────▼──────────┐         ┌──────────────────────┐
│ TICKET_RESPONSES    │         │ TICKET_NOTIFICATIONS │
│                     │         │                      │
│ - id (PK)           │         │ - id (PK)            │
│ - ticket_id (FK)    │◄────────┤ - ticket_id (FK)     │
│ - user_id (FK)      │   1:N   │ - user_id (FK)       │
│ - response_text     │         │ - notification_type  │
│ - is_internal_note  │         │ - email_to           │
│ - email_sent        │         │ - email_subject      │
│ - deleted_at        │         │ - email_body         │
└─────────────────────┘         │ - delivery_status    │
                                │ - sent_at            │
┌──────────────────────┐        └──────────────────────┘
│   TICKET_HISTORY     │
│                      │
│ - id (PK)            │
│ - ticket_id (FK)     │
│ - user_id (FK)       │
│ - action             │
│ - field_name         │
│ - old_value          │
│ - new_value          │
│ - change_summary     │
│ - created_at         │
└──────────────────────┘
```

### Relationship Summary

| Parent Table | Child Table | Relationship | ON DELETE | Reasoning |
|--------------|-------------|--------------|-----------|-----------|
| `tenants` | `tickets` | 1:N | CASCADE | Delete all tickets when tenant deleted |
| `tenants` | `ticket_responses` | 1:N | CASCADE | Delete responses when tenant deleted |
| `tenants` | `ticket_assignments` | 1:N | CASCADE | Delete assignments when tenant deleted |
| `tenants` | `ticket_notifications` | 1:N | CASCADE | Delete notifications when tenant deleted |
| `tenants` | `ticket_history` | 1:N | CASCADE | Delete history when tenant deleted |
| `users` | `tickets.created_by` | 1:N | RESTRICT | Prevent deletion of ticket creator |
| `users` | `tickets.assigned_to` | 1:N | SET NULL | Allow deletion, ticket becomes unassigned |
| `users` | `ticket_responses.user_id` | 1:N | CASCADE | Delete responses when user deleted |
| `users` | `ticket_assignments.assigned_to` | 1:N | CASCADE | Delete assignments when assignee deleted |
| `users` | `ticket_assignments.assigned_by` | 1:N | RESTRICT | Preserve audit trail |
| `users` | `ticket_notifications.user_id` | 1:N | CASCADE | Delete notifications when user deleted |
| `users` | `ticket_history.user_id` | 1:N | SET NULL | Preserve history even if user deleted |
| `tickets` | `ticket_responses` | 1:N | CASCADE | Delete responses when ticket deleted |
| `tickets` | `ticket_assignments` | 1:N | CASCADE | Delete assignments when ticket deleted |
| `tickets` | `ticket_notifications` | 1:N | CASCADE | Delete notifications when ticket deleted |
| `tickets` | `ticket_history` | 1:N | CASCADE | Delete history when ticket deleted |

---

## Table Specifications

### 1. TICKETS Table

**Purpose:** Core ticket entity with workflow status, urgency, and categorization.

**Columns:**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `tenant_id` | INT UNSIGNED | NO | - | Multi-tenant isolation (MANDATORY) |
| `created_by` | INT UNSIGNED | NO | - | Ticket creator (cannot be deleted) |
| `assigned_to` | INT UNSIGNED | YES | NULL | Currently assigned admin/super_admin |
| `subject` | VARCHAR(500) | NO | - | Ticket subject (searchable) |
| `description` | TEXT | NO | - | Detailed problem description |
| `ticket_number` | VARCHAR(50) | YES | NULL | Human-readable ID (e.g., TICK-2025-0001) |
| `category` | ENUM | NO | 'general' | Ticket category |
| `urgency` | ENUM | NO | 'medium' | Urgency level |
| `status` | ENUM | NO | 'open' | Workflow status |
| `priority` | INT UNSIGNED | NO | 50 | Priority score (1-100) |
| `attachments` | JSON | YES | NULL | Array of file IDs |
| `tags` | JSON | YES | NULL | Tags for categorization |
| `resolved_at` | TIMESTAMP | YES | NULL | When marked resolved |
| `resolved_by` | INT UNSIGNED | YES | NULL | Who marked resolved |
| `resolution_notes` | TEXT | YES | NULL | Resolution summary |
| `closed_at` | TIMESTAMP | YES | NULL | When closed |
| `closed_by` | INT UNSIGNED | YES | NULL | Who closed |
| `first_response_at` | TIMESTAMP | YES | NULL | First admin response time |
| `first_response_time_minutes` | INT UNSIGNED | YES | NULL | Minutes to first response |
| `resolution_time_minutes` | INT UNSIGNED | YES | NULL | Minutes to resolution |
| `deleted_at` | TIMESTAMP | YES | NULL | Soft delete (MANDATORY) |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Updated timestamp |

**Category Enum Values:**
- `technical` - Technical support issues
- `billing` - Billing and payment questions
- `feature_request` - New feature requests
- `bug_report` - Bug reports
- `general` - General inquiries
- `other` - Other categories

**Urgency Enum Values:**
- `low` - Low urgency
- `medium` - Medium urgency (default)
- `high` - High urgency
- `critical` - Critical/urgent issues

**Status Enum Values:**
- `open` - New ticket, awaiting response
- `in_progress` - Admin working on ticket
- `waiting_response` - Waiting for user response
- `resolved` - Issue resolved, awaiting closure
- `closed` - Ticket closed

**Indexes:**
- PRIMARY KEY: `id`
- UNIQUE KEY: `ticket_number`
- `idx_tickets_tenant_created`: (tenant_id, created_at) - Chronological listing
- `idx_tickets_tenant_deleted`: (tenant_id, deleted_at) - Soft delete filter
- `idx_tickets_tenant_status`: (tenant_id, status, deleted_at) - Status filtering
- `idx_tickets_tenant_urgency`: (tenant_id, urgency, deleted_at) - Urgency filtering
- `idx_tickets_tenant_category`: (tenant_id, category, deleted_at) - Category filtering
- `idx_tickets_created_by`: (created_by, status) - User's tickets
- `idx_tickets_assigned_to`: (assigned_to, status, deleted_at) - Assigned tickets
- `idx_tickets_number`: (ticket_number) - Ticket lookup
- `idx_tickets_status`: (status, created_at) - Status queries
- `idx_tickets_priority`: (priority DESC, created_at) - Priority ordering
- FULLTEXT `idx_tickets_search`: (subject, description) - Full-text search

---

### 2. TICKET_RESPONSES Table

**Purpose:** Conversation thread for tickets with support for internal admin notes.

**Columns:**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `tenant_id` | INT UNSIGNED | NO | - | Multi-tenant isolation |
| `ticket_id` | INT UNSIGNED | NO | - | Parent ticket |
| `user_id` | INT UNSIGNED | NO | - | Response author |
| `response_text` | TEXT | NO | - | Response content |
| `is_internal_note` | BOOLEAN | NO | FALSE | Internal admin note flag |
| `attachments` | JSON | YES | NULL | File attachments |
| `is_edited` | BOOLEAN | NO | FALSE | Edit flag |
| `edited_at` | TIMESTAMP | YES | NULL | Last edit timestamp |
| `email_sent` | BOOLEAN | NO | FALSE | Email sent flag |
| `email_sent_at` | TIMESTAMP | YES | NULL | Email sent timestamp |
| `deleted_at` | TIMESTAMP | YES | NULL | Soft delete |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Updated timestamp |

**Internal Notes:**
- When `is_internal_note = TRUE`, response is only visible to admin/super_admin users
- Used for internal communication about ticket without notifying ticket creator
- No email sent for internal notes

**Indexes:**
- PRIMARY KEY: `id`
- `idx_ticket_responses_tenant_created`: (tenant_id, created_at)
- `idx_ticket_responses_tenant_deleted`: (tenant_id, deleted_at)
- `idx_ticket_responses_ticket`: (ticket_id, deleted_at, created_at) - Timeline
- `idx_ticket_responses_user`: (user_id, created_at) - User activity
- `idx_ticket_responses_internal`: (ticket_id, is_internal_note, deleted_at) - Filter notes
- FULLTEXT `idx_ticket_responses_search`: (response_text) - Search responses

---

### 3. TICKET_ASSIGNMENTS Table

**Purpose:** Track assignment history and who handled each ticket.

**Columns:**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `tenant_id` | INT UNSIGNED | NO | - | Multi-tenant isolation |
| `ticket_id` | INT UNSIGNED | NO | - | Ticket being assigned |
| `assigned_to` | INT UNSIGNED | NO | - | Admin/super_admin assigned |
| `assigned_by` | INT UNSIGNED | NO | - | Who made assignment |
| `assignment_note` | TEXT | YES | NULL | Optional assignment note |
| `assigned_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Assignment timestamp |
| `unassigned_at` | TIMESTAMP | YES | NULL | When unassigned |
| `unassigned_by` | INT UNSIGNED | YES | NULL | Who removed assignment |
| `deleted_at` | TIMESTAMP | YES | NULL | Soft delete |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Updated timestamp |

**Assignment Workflow:**
1. Admin assigns ticket to themselves or another admin
2. Record created with `assigned_at` timestamp
3. When reassigned or unassigned, `unassigned_at` and `unassigned_by` are set
4. New assignment record created for new assignee
5. Full history preserved for reporting and audit

**Indexes:**
- PRIMARY KEY: `id`
- `idx_ticket_assignments_tenant_created`: (tenant_id, created_at)
- `idx_ticket_assignments_tenant_deleted`: (tenant_id, deleted_at)
- `idx_ticket_assignments_ticket`: (ticket_id, deleted_at) - Ticket assignments
- `idx_ticket_assignments_assigned_to`: (assigned_to, deleted_at, assigned_at) - Admin workload
- `idx_ticket_assignments_active`: (ticket_id, assigned_to, unassigned_at, deleted_at) - Active assignments

---

### 4. TICKET_NOTIFICATIONS Table

**Purpose:** Email notification audit trail with delivery status tracking.

**Columns:**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | Primary key (large for volume) |
| `tenant_id` | INT UNSIGNED | NO | - | Multi-tenant isolation |
| `ticket_id` | INT UNSIGNED | NO | - | Related ticket |
| `user_id` | INT UNSIGNED | NO | - | Notification recipient |
| `notification_type` | ENUM | NO | - | Notification type |
| `email_to` | VARCHAR(255) | NO | - | Recipient email |
| `email_subject` | VARCHAR(500) | NO | - | Email subject |
| `email_body` | TEXT | NO | - | Email content |
| `delivery_status` | ENUM | NO | 'pending' | Delivery status |
| `sent_at` | TIMESTAMP | YES | NULL | Sent timestamp |
| `error_message` | TEXT | YES | NULL | Error details |
| `trigger_data` | JSON | YES | NULL | Additional context |
| `deleted_at` | TIMESTAMP | YES | NULL | Soft delete |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Updated timestamp |

**Notification Type Enum:**
- `ticket_created` - New ticket created
- `ticket_assigned` - Ticket assigned to admin
- `ticket_response` - New response added
- `status_changed` - Ticket status changed
- `ticket_resolved` - Ticket marked resolved
- `ticket_closed` - Ticket closed
- `urgency_changed` - Urgency level changed

**Delivery Status Enum:**
- `pending` - Queued for sending
- `sent` - Successfully sent
- `failed` - Delivery failed
- `bounced` - Email bounced

**Indexes:**
- PRIMARY KEY: `id`
- `idx_ticket_notifications_tenant_created`: (tenant_id, created_at)
- `idx_ticket_notifications_tenant_deleted`: (tenant_id, deleted_at)
- `idx_ticket_notifications_ticket`: (ticket_id, created_at) - Ticket notifications
- `idx_ticket_notifications_user`: (user_id, created_at) - User notifications
- `idx_ticket_notifications_status`: (delivery_status, created_at) - Failed deliveries
- `idx_ticket_notifications_type`: (notification_type, created_at) - Type queries

---

### 5. TICKET_HISTORY Table

**Purpose:** Complete audit trail of all ticket changes.

**Columns:**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | Primary key (large for volume) |
| `tenant_id` | INT UNSIGNED | NO | - | Multi-tenant isolation |
| `ticket_id` | INT UNSIGNED | NO | - | Ticket being audited |
| `user_id` | INT UNSIGNED | YES | NULL | Who made change (NULL=system) |
| `action` | VARCHAR(100) | NO | - | Action type |
| `field_name` | VARCHAR(100) | YES | NULL | Field changed |
| `old_value` | TEXT | YES | NULL | Previous value |
| `new_value` | TEXT | YES | NULL | New value |
| `change_summary` | VARCHAR(500) | YES | NULL | Human-readable summary |
| `ip_address` | VARCHAR(45) | YES | NULL | User IP |
| `user_agent` | VARCHAR(500) | YES | NULL | Browser/client |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | Change timestamp |

**Common Action Values:**
- `created` - Ticket created
- `updated` - General update
- `status_changed` - Status workflow change
- `assigned` - Ticket assignment
- `response_added` - Response/comment added
- `urgency_changed` - Urgency level changed
- `category_changed` - Category changed
- `resolved` - Ticket resolved
- `closed` - Ticket closed
- `reopened` - Ticket reopened
- `deleted` - Soft delete
- `restored` - Un-deleted

**Indexes:**
- PRIMARY KEY: `id`
- `idx_ticket_history_tenant`: (tenant_id, created_at)
- `idx_ticket_history_ticket`: (ticket_id, created_at DESC) - Ticket timeline
- `idx_ticket_history_user`: (user_id, created_at DESC) - User activity
- `idx_ticket_history_action`: (action, created_at) - Action queries

**Note:** This table does NOT have `deleted_at` column - history must be preserved permanently for audit compliance.

---

## Indexes and Performance

### Multi-Tenant Query Optimization

**MANDATORY Pattern:** Every query MUST include both `tenant_id` and `deleted_at` filters:

```sql
-- ✅ CORRECT
SELECT * FROM tickets
WHERE tenant_id = ?
  AND deleted_at IS NULL
  AND status = 'open';

-- ❌ WRONG - Security vulnerability!
SELECT * FROM tickets
WHERE status = 'open';
```

### Index Strategy

1. **Composite Indexes:** All tables have `(tenant_id, deleted_at)` and `(tenant_id, created_at)` for multi-tenant queries.

2. **Status Queries:** `idx_tickets_tenant_status` enables efficient filtering by status.

3. **Assignment Queries:** Separate indexes on `created_by` and `assigned_to` for user-specific views.

4. **Full-Text Search:** FULLTEXT indexes on `tickets.subject`, `tickets.description`, and `ticket_responses.response_text`.

5. **Email Tracking:** Indexes on `delivery_status` and `notification_type` for monitoring.

---

## Email Notification Workflow

### Notification Triggers

**1. Ticket Created:**
```sql
-- Notify all super_admin users
INSERT INTO ticket_notifications (tenant_id, ticket_id, user_id, notification_type, email_to, email_subject, email_body)
SELECT
    t.tenant_id,
    t.id,
    u.id,
    'ticket_created',
    u.email,
    CONCAT('[Ticket #', t.ticket_number, '] New Support Ticket'),
    CONCAT('New ticket created: ', t.subject, '\n\nDescription: ', t.description)
FROM tickets t
CROSS JOIN users u
WHERE t.id = ?
  AND u.tenant_id = t.tenant_id
  AND u.role = 'super_admin'
  AND u.deleted_at IS NULL;
```

**2. Ticket Assigned:**
```sql
-- Notify assigned admin
INSERT INTO ticket_notifications (tenant_id, ticket_id, user_id, notification_type, email_to, email_subject, email_body)
SELECT
    t.tenant_id,
    t.id,
    t.assigned_to,
    'ticket_assigned',
    u.email,
    CONCAT('[Ticket #', t.ticket_number, '] Assigned to You'),
    CONCAT('You have been assigned ticket: ', t.subject)
FROM tickets t
INNER JOIN users u ON t.assigned_to = u.id
WHERE t.id = ?;
```

**3. Status Changed:**
```sql
-- Notify ticket creator
INSERT INTO ticket_notifications (tenant_id, ticket_id, user_id, notification_type, email_to, email_subject, email_body)
SELECT
    t.tenant_id,
    t.id,
    t.created_by,
    'status_changed',
    u.email,
    CONCAT('[Ticket #', t.ticket_number, '] Status Updated'),
    CONCAT('Your ticket status changed to: ', t.status)
FROM tickets t
INNER JOIN users u ON t.created_by = u.id
WHERE t.id = ?;
```

**4. Response Added:**
```sql
-- Notify ticket creator (if response from admin)
-- OR notify assigned admin (if response from user)
INSERT INTO ticket_notifications (tenant_id, ticket_id, user_id, notification_type, email_to, email_subject, email_body)
SELECT
    tr.tenant_id,
    tr.ticket_id,
    CASE
        WHEN u_responder.role IN ('admin', 'super_admin') THEN t.created_by
        ELSE t.assigned_to
    END,
    'ticket_response',
    CASE
        WHEN u_responder.role IN ('admin', 'super_admin') THEN u_creator.email
        ELSE u_assigned.email
    END,
    CONCAT('[Ticket #', t.ticket_number, '] New Response'),
    tr.response_text
FROM ticket_responses tr
INNER JOIN tickets t ON tr.ticket_id = t.id
INNER JOIN users u_responder ON tr.user_id = u_responder.id
LEFT JOIN users u_creator ON t.created_by = u_creator.id
LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
WHERE tr.id = ?
  AND tr.is_internal_note = FALSE;  -- No email for internal notes
```

---

## Common Queries

### 1. Open Tickets Dashboard (Admin View)

Get all open tickets with creator info:

```sql
SELECT
    t.id,
    t.ticket_number,
    t.subject,
    t.category,
    t.urgency,
    t.status,
    t.priority,
    t.created_at,
    CONCAT(u_creator.first_name, ' ', u_creator.last_name) as creator_name,
    u_creator.email as creator_email,
    CONCAT(u_assigned.first_name, ' ', u_assigned.last_name) as assigned_to_name,
    -- Check if overdue (critical tickets > 4 hours, high > 24 hours, medium > 72 hours)
    CASE
        WHEN t.urgency = 'critical' AND TIMESTAMPDIFF(HOUR, t.created_at, NOW()) > 4 THEN 1
        WHEN t.urgency = 'high' AND TIMESTAMPDIFF(HOUR, t.created_at, NOW()) > 24 THEN 1
        WHEN t.urgency = 'medium' AND TIMESTAMPDIFF(HOUR, t.created_at, NOW()) > 72 THEN 1
        ELSE 0
    END as is_overdue,
    -- Response count
    (SELECT COUNT(*)
     FROM ticket_responses tr
     WHERE tr.ticket_id = t.id
       AND tr.deleted_at IS NULL
       AND tr.is_internal_note = FALSE) as response_count
FROM tickets t
INNER JOIN users u_creator ON t.created_by = u_creator.id
LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
WHERE t.tenant_id = ?
  AND t.deleted_at IS NULL
  AND t.status IN ('open', 'in_progress', 'waiting_response')
ORDER BY
    CASE t.urgency
        WHEN 'critical' THEN 1
        WHEN 'high' THEN 2
        WHEN 'medium' THEN 3
        ELSE 4
    END,
    t.created_at ASC;
```

---

### 2. My Tickets (User View)

Get all tickets created by current user:

```sql
SELECT
    t.id,
    t.ticket_number,
    t.subject,
    t.category,
    t.urgency,
    t.status,
    t.created_at,
    t.resolved_at,
    CASE
        WHEN t.status IN ('resolved', 'closed') THEN 'completed'
        WHEN t.assigned_to IS NOT NULL THEN 'assigned'
        ELSE 'pending'
    END as display_status,
    -- Last response info
    (SELECT response_text
     FROM ticket_responses tr
     WHERE tr.ticket_id = t.id
       AND tr.deleted_at IS NULL
       AND tr.is_internal_note = FALSE
     ORDER BY tr.created_at DESC
     LIMIT 1) as last_response,
    (SELECT created_at
     FROM ticket_responses tr
     WHERE tr.ticket_id = t.id
       AND tr.deleted_at IS NULL
       AND tr.is_internal_note = FALSE
     ORDER BY tr.created_at DESC
     LIMIT 1) as last_response_at
FROM tickets t
WHERE t.tenant_id = ?
  AND t.created_by = ?
  AND t.deleted_at IS NULL
ORDER BY
    CASE
        WHEN t.status IN ('resolved', 'closed') THEN 1
        ELSE 0
    END,
    t.created_at DESC;
```

---

### 3. Ticket Detail with Full Conversation

Get complete ticket details including all responses:

```sql
-- Main ticket info
SELECT
    t.*,
    CONCAT(u_creator.first_name, ' ', u_creator.last_name) as creator_name,
    u_creator.email as creator_email,
    CONCAT(u_assigned.first_name, ' ', u_assigned.last_name) as assigned_to_name,
    CONCAT(u_resolved.first_name, ' ', u_resolved.last_name) as resolved_by_name
FROM tickets t
INNER JOIN users u_creator ON t.created_by = u_creator.id
LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
LEFT JOIN users u_resolved ON t.resolved_by = u_resolved.id
WHERE t.id = ?
  AND t.tenant_id = ?
  AND t.deleted_at IS NULL;

-- All responses (public only for regular users, all for admins)
SELECT
    tr.id,
    tr.response_text,
    tr.is_internal_note,
    tr.attachments,
    tr.is_edited,
    tr.edited_at,
    tr.created_at,
    CONCAT(u.first_name, ' ', u.last_name) as author_name,
    u.email as author_email,
    u.avatar_url as author_avatar,
    u.role as author_role
FROM ticket_responses tr
INNER JOIN users u ON tr.user_id = u.id
WHERE tr.ticket_id = ?
  AND tr.tenant_id = ?
  AND tr.deleted_at IS NULL
  AND (
      tr.is_internal_note = FALSE  -- Public responses
      OR ? IN ('admin', 'super_admin')  -- Or admin viewing
  )
ORDER BY tr.created_at ASC;

-- Assignment history
SELECT
    ta.assigned_at,
    ta.unassigned_at,
    CONCAT(u_assigned.first_name, ' ', u_assigned.last_name) as assigned_to_name,
    CONCAT(u_by.first_name, ' ', u_by.last_name) as assigned_by_name,
    ta.assignment_note
FROM ticket_assignments ta
INNER JOIN users u_assigned ON ta.assigned_to = u_assigned.id
INNER JOIN users u_by ON ta.assigned_by = u_by.id
WHERE ta.ticket_id = ?
  AND ta.tenant_id = ?
  AND ta.deleted_at IS NULL
ORDER BY ta.assigned_at DESC;
```

---

### 4. Assign Ticket to Admin

Assign ticket with assignment history:

```sql
-- Update tickets table
UPDATE tickets
SET assigned_to = ?,
    status = 'in_progress',
    updated_at = NOW()
WHERE id = ?
  AND tenant_id = ?
  AND deleted_at IS NULL;

-- Create assignment record
INSERT INTO ticket_assignments (tenant_id, ticket_id, assigned_to, assigned_by, assignment_note)
VALUES (?, ?, ?, ?, ?);

-- Create history entry
INSERT INTO ticket_history (tenant_id, ticket_id, user_id, action, field_name, old_value, new_value, change_summary)
VALUES (?, ?, ?, 'assigned', 'assigned_to', NULL, ?, CONCAT('Ticket assigned to ', ?));

-- Send email notification
INSERT INTO ticket_notifications (tenant_id, ticket_id, user_id, notification_type, email_to, email_subject, email_body)
SELECT ?, ?, ?, 'ticket_assigned', u.email,
       CONCAT('[Ticket #', t.ticket_number, '] Assigned to You'),
       CONCAT('You have been assigned ticket: ', t.subject)
FROM tickets t
INNER JOIN users u ON u.id = ?
WHERE t.id = ?;
```

---

### 5. Add Response to Ticket

Add response with email notification:

```sql
-- Insert response
INSERT INTO ticket_responses (tenant_id, ticket_id, user_id, response_text, is_internal_note, attachments)
VALUES (?, ?, ?, ?, ?, ?);

SET @response_id = LAST_INSERT_ID();

-- Update first response time if this is the first admin response
UPDATE tickets t
SET first_response_at = NOW(),
    first_response_time_minutes = TIMESTAMPDIFF(MINUTE, t.created_at, NOW())
WHERE t.id = ?
  AND t.first_response_at IS NULL
  AND EXISTS (
      SELECT 1 FROM users u
      WHERE u.id = ?
        AND u.role IN ('admin', 'super_admin')
  );

-- Create history entry
INSERT INTO ticket_history (tenant_id, ticket_id, user_id, action, change_summary)
VALUES (?, ?, ?, 'response_added', 'New response added');

-- Send email notification (to ticket creator if admin response, to assigned admin if user response)
INSERT INTO ticket_notifications (tenant_id, ticket_id, user_id, notification_type, email_to, email_subject, email_body)
SELECT
    ?,
    ?,
    CASE WHEN u_responder.role IN ('admin', 'super_admin') THEN t.created_by ELSE t.assigned_to END,
    'ticket_response',
    CASE WHEN u_responder.role IN ('admin', 'super_admin') THEN u_creator.email ELSE u_assigned.email END,
    CONCAT('[Ticket #', t.ticket_number, '] New Response'),
    ?
FROM tickets t
INNER JOIN users u_responder ON u_responder.id = ?
LEFT JOIN users u_creator ON t.created_by = u_creator.id
LEFT JOIN users u_assigned ON t.assigned_to = u_assigned.id
WHERE t.id = ?
  AND (? = FALSE);  -- Only if not internal note
```

---

### 6. Close Ticket

Mark ticket as resolved and closed:

```sql
-- Resolve ticket
UPDATE tickets
SET status = 'resolved',
    resolved_at = NOW(),
    resolved_by = ?,
    resolution_notes = ?,
    resolution_time_minutes = TIMESTAMPDIFF(MINUTE, created_at, NOW()),
    updated_at = NOW()
WHERE id = ?
  AND tenant_id = ?
  AND deleted_at IS NULL;

-- Create history entry
INSERT INTO ticket_history (tenant_id, ticket_id, user_id, action, field_name, old_value, new_value, change_summary)
VALUES (?, ?, ?, 'status_changed', 'status', 'in_progress', 'resolved', 'Ticket marked as resolved');

-- Send notification to ticket creator
INSERT INTO ticket_notifications (tenant_id, ticket_id, user_id, notification_type, email_to, email_subject, email_body)
SELECT ?, t.id, t.created_by, 'ticket_resolved', u.email,
       CONCAT('[Ticket #', t.ticket_number, '] Resolved'),
       CONCAT('Your ticket has been resolved.\n\nResolution: ', t.resolution_notes)
FROM tickets t
INNER JOIN users u ON t.created_by = u.id
WHERE t.id = ?;
```

---

### 7. Full-Text Search Tickets

Search tickets by keyword:

```sql
SELECT
    t.id,
    t.ticket_number,
    t.subject,
    t.description,
    t.category,
    t.urgency,
    t.status,
    t.created_at,
    CONCAT(u.first_name, ' ', u.last_name) as creator_name,
    MATCH(t.subject, t.description) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance
FROM tickets t
INNER JOIN users u ON t.created_by = u.id
WHERE t.tenant_id = ?
  AND t.deleted_at IS NULL
  AND MATCH(t.subject, t.description) AGAINST(? IN NATURAL LANGUAGE MODE)
ORDER BY relevance DESC, t.created_at DESC
LIMIT 50;
```

---

### 8. Ticket Statistics (Dashboard)

Get comprehensive stats for reporting:

```sql
SELECT
    -- Total counts by status
    COUNT(*) as total_tickets,
    COUNT(CASE WHEN status = 'open' THEN 1 END) as open_count,
    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_count,
    COUNT(CASE WHEN status = 'waiting_response' THEN 1 END) as waiting_response_count,
    COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_count,
    COUNT(CASE WHEN status = 'closed' THEN 1 END) as closed_count,

    -- Urgency counts
    COUNT(CASE WHEN urgency = 'critical' THEN 1 END) as critical_count,
    COUNT(CASE WHEN urgency = 'high' THEN 1 END) as high_count,

    -- Category counts
    COUNT(CASE WHEN category = 'technical' THEN 1 END) as technical_count,
    COUNT(CASE WHEN category = 'billing' THEN 1 END) as billing_count,
    COUNT(CASE WHEN category = 'bug_report' THEN 1 END) as bug_count,
    COUNT(CASE WHEN category = 'feature_request' THEN 1 END) as feature_count,

    -- SLA metrics
    AVG(first_response_time_minutes) as avg_first_response_minutes,
    AVG(resolution_time_minutes) as avg_resolution_minutes,
    MAX(first_response_time_minutes) as max_first_response_minutes,
    MAX(resolution_time_minutes) as max_resolution_minutes,

    -- Unassigned count
    COUNT(CASE WHEN assigned_to IS NULL AND status NOT IN ('resolved', 'closed') THEN 1 END) as unassigned_count
FROM tickets
WHERE tenant_id = ?
  AND deleted_at IS NULL
  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY);  -- Last 30 days
```

---

### 9. Failed Email Notifications

Get failed notifications for retry:

```sql
SELECT
    tn.id,
    tn.ticket_id,
    t.ticket_number,
    tn.notification_type,
    tn.email_to,
    tn.email_subject,
    tn.error_message,
    tn.created_at,
    TIMESTAMPDIFF(HOUR, tn.created_at, NOW()) as hours_since_failure
FROM ticket_notifications tn
INNER JOIN tickets t ON tn.ticket_id = t.id
WHERE tn.tenant_id = ?
  AND tn.deleted_at IS NULL
  AND tn.delivery_status = 'failed'
  AND tn.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)  -- Last 7 days
ORDER BY tn.created_at DESC;
```

---

### 10. Admin Workload Report

Get ticket count per admin:

```sql
SELECT
    u.id,
    CONCAT(u.first_name, ' ', u.last_name) as admin_name,
    u.email,
    COUNT(t.id) as active_tickets,
    COUNT(CASE WHEN t.urgency = 'critical' THEN 1 END) as critical_tickets,
    COUNT(CASE WHEN t.urgency = 'high' THEN 1 END) as high_tickets,
    AVG(TIMESTAMPDIFF(HOUR, t.created_at, NOW())) as avg_ticket_age_hours
FROM users u
LEFT JOIN tickets t ON t.assigned_to = u.id
    AND t.deleted_at IS NULL
    AND t.status NOT IN ('resolved', 'closed')
WHERE u.tenant_id = ?
  AND u.deleted_at IS NULL
  AND u.role IN ('admin', 'super_admin')
GROUP BY u.id
ORDER BY active_tickets DESC;
```

---

## Ticket Lifecycle Management

### Standard Workflow

```
┌────────┐  User creates  ┌──────────┐  Admin assigns  ┌──────────────┐
│  User  │────────────────>│   OPEN   │────────────────>│ IN_PROGRESS  │
└────────┘                 └──────────┘                 └──────────────┘
                                │                              │
                                │                              │ Admin responds
                                │ Admin responds               ▼
                                ▼                        ┌─────────────────┐
                          ┌──────────┐  User responds   │ WAITING_RESPONSE│
                          │ RESOLVED │<─────────────────┤                 │
                          └──────────┘                  └─────────────────┘
                                │                              │
                                │ User confirms                │ Timeout
                                ▼                              ▼
                           ┌────────┐                    ┌──────────┐
                           │ CLOSED │                    │ RESOLVED │
                           └────────┘                    └──────────┘
```

### Status Transitions

**From OPEN:**
- → IN_PROGRESS (when admin assigns)
- → RESOLVED (direct resolution)
- → CLOSED (immediate closure)

**From IN_PROGRESS:**
- → WAITING_RESPONSE (when waiting for user input)
- → RESOLVED (when issue fixed)
- → OPEN (unassign/reopen)

**From WAITING_RESPONSE:**
- → IN_PROGRESS (user responds)
- → RESOLVED (timeout/auto-resolve)
- → OPEN (escalate)

**From RESOLVED:**
- → CLOSED (final closure)
- → OPEN (reopen if issue persists)

**From CLOSED:**
- → OPEN (reopen ticket)

---

## Multi-Tenant Considerations

### MANDATORY Patterns

**Every Query Must Include:**
```sql
WHERE tenant_id = ? AND deleted_at IS NULL
```

**Exception:** `super_admin` role can view cross-tenant tickets:
```php
if ($currentUser['role'] !== 'super_admin') {
    $whereClauses[] = 'tenant_id = ?';
    $params[] = $currentUser['tenant_id'];
}
```

### Cross-Tenant Ticket Assignment

**FORBIDDEN:** Never assign ticket from tenant A to user from tenant B:

```sql
-- Validation in application code
$ticketTenantId = $db->fetchOne('SELECT tenant_id FROM tickets WHERE id = ?', [$ticketId]);
$userTenantId = $db->fetchOne('SELECT tenant_id FROM users WHERE id = ?', [$userId]);

if ($ticketTenantId !== $userTenantId) {
    throw new Exception('Cannot assign ticket to user from different tenant');
}
```

### Email Notification Isolation

**Email notifications MUST respect tenant boundaries:**

```sql
-- Only notify users within same tenant
INSERT INTO ticket_notifications (...)
SELECT ...
FROM tickets t
INNER JOIN users u ON u.tenant_id = t.tenant_id  -- MANDATORY
WHERE ...
```

---

## Migration Guide

### Pre-Migration Checklist

- [ ] Backup production database
- [ ] Verify MySQL version >= 8.0
- [ ] Check free disk space (estimate: 5-10% of existing DB size)
- [ ] Identify peak usage hours to schedule migration
- [ ] Notify users of maintenance window
- [ ] Verify all users have valid email addresses

---

### Migration Steps

**Step 1: Backup**
```bash
mysqldump -u root -p collaboranexio > backup_before_ticket_migration_$(date +%Y%m%d_%H%M%S).sql
```

**Step 2: Run Migration**
```bash
mysql -u root -p collaboranexio < database/migrations/ticket_system_schema.sql
```

**Step 3: Verify**
```sql
-- Check tables created
SHOW TABLES LIKE 'ticket%';

-- Check record counts
SELECT 'tickets' as tbl, COUNT(*) FROM tickets
UNION ALL SELECT 'ticket_responses', COUNT(*) FROM ticket_responses
UNION ALL SELECT 'ticket_assignments', COUNT(*) FROM ticket_assignments
UNION ALL SELECT 'ticket_notifications', COUNT(*) FROM ticket_notifications
UNION ALL SELECT 'ticket_history', COUNT(*) FROM ticket_history;

-- Verify indexes
SHOW INDEX FROM tickets WHERE Key_name LIKE 'idx_%';
SHOW INDEX FROM ticket_responses WHERE Key_name LIKE 'idx_%';
```

**Step 4: Test**
```sql
-- Test ticket creation
INSERT INTO tickets (tenant_id, created_by, subject, description, category, urgency)
SELECT 1, u.id, 'Test Ticket', 'Test description', 'general', 'low'
FROM users u WHERE u.tenant_id = 1 AND u.role = 'user' LIMIT 1;

-- Verify ticket number generation
SELECT ticket_number FROM tickets ORDER BY id DESC LIMIT 1;
```

---

### Rollback Procedure

**If migration fails:**
```bash
# Restore from backup
mysql -u root -p collaboranexio < backup_before_ticket_migration_YYYYMMDD_HHMMSS.sql

# Or run rollback script
mysql -u root -p collaboranexio < database/migrations/ticket_system_schema_rollback.sql
```

---

### Post-Migration Tasks

- [ ] Deploy API endpoints (`/api/tickets/`)
- [ ] Deploy frontend ticket interface (`/tickets.php`)
- [ ] Configure email notification cron job
- [ ] Update documentation
- [ ] Train support team
- [ ] Monitor performance for first 24-48 hours
- [ ] Run ANALYZE TABLE for query optimizer

---

## Testing Checklist

### Functional Tests

- [ ] **Create Ticket:** User creates ticket, super_admin receives email
- [ ] **Multi-Tenant Isolation:** Tenant A cannot see tenant B tickets
- [ ] **Soft Delete:** Delete ticket, verify `deleted_at` set, ticket hidden
- [ ] **Assign Ticket:** Admin assigns ticket to self/another admin
- [ ] **Add Response:** Admin responds, user receives email notification
- [ ] **Internal Notes:** Admin adds internal note, user does NOT receive email
- [ ] **Status Workflow:** Move ticket through statuses: open → in_progress → resolved → closed
- [ ] **Urgency Change:** Change urgency, notification sent
- [ ] **Full-Text Search:** Search tickets by keyword
- [ ] **Ticket History:** View complete audit trail

---

### Performance Tests

- [ ] **Open Tickets Query:** Load 1000+ tickets (target: < 100ms)
- [ ] **My Tickets Query:** Filter by user (target: < 50ms)
- [ ] **Full-Text Search:** Search across 10K+ tickets (target: < 300ms)
- [ ] **Email Queue:** Process 100 notifications (target: < 5s)
- [ ] **Index Usage:** Verify EXPLAIN shows index usage

---

### Security Tests

- [ ] **Tenant Isolation:** User from tenant A attempts to access tenant B ticket (should fail)
- [ ] **Soft Delete:** Deleted tickets not returned in queries
- [ ] **SQL Injection:** Test prepared statements with malicious input
- [ ] **Email Injection:** Test email fields with header injection attempts
- [ ] **Admin-Only Actions:** Regular user attempts to assign ticket (should fail)

---

### Email Notification Tests

- [ ] **Ticket Created:** Super_admin receives notification
- [ ] **Ticket Assigned:** Assigned admin receives notification
- [ ] **Status Changed:** Ticket creator receives notification
- [ ] **Response Added:** Appropriate party receives notification
- [ ] **Internal Note:** NO email sent for internal notes
- [ ] **Failed Delivery:** Error logged in `ticket_notifications` table
- [ ] **Bounced Email:** Delivery status updated to 'bounced'

---

## Performance Optimization Tips

### Query Optimization

1. **Always use tenant_id first:** `WHERE tenant_id = ? AND ...`
2. **Avoid SELECT *:** Select only needed columns
3. **Use LIMIT:** For paginated results
4. **Leverage indexes:** Filter on indexed columns
5. **Use covering indexes:** Query uses only indexed columns

---

### Table Optimization

```sql
-- Analyze tables for query optimizer
ANALYZE TABLE tickets, ticket_responses, ticket_assignments, ticket_notifications, ticket_history;

-- Optimize tables (defragment)
OPTIMIZE TABLE tickets, ticket_responses, ticket_assignments, ticket_notifications, ticket_history;
```

---

### Partitioning (For Large Datasets)

If `tickets` table exceeds 1M rows, consider partitioning:

```sql
-- Partition by date (for archival)
ALTER TABLE ticket_history
PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

---

## Appendix: Ticket Number Generation

### Auto-Generation Pattern

```php
// Generate unique ticket number
function generateTicketNumber($tenantId, $db) {
    $year = date('Y');
    $prefix = "TICK-{$year}-";

    // Get last ticket number for this year
    $lastNumber = $db->fetchOne(
        "SELECT MAX(CAST(SUBSTRING(ticket_number, 11) AS UNSIGNED)) as last_num
         FROM tickets
         WHERE tenant_id = ?
           AND ticket_number LIKE ?
           AND deleted_at IS NULL",
        [$tenantId, "{$prefix}%"]
    );

    $nextNumber = ($lastNumber ?? 0) + 1;
    return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
}

// Usage
$ticketNumber = generateTicketNumber($tenantId, $db);
// Result: TICK-2025-0001, TICK-2025-0002, etc.
```

---

## Support and Maintenance

**Documentation Version:** 2025-10-26
**Schema Version:** 1.0.0
**Compatible with:** CollaboraNexio v2.x, MySQL 8.0+

**For issues or questions:**
- Check `CLAUDE.md` for project conventions
- Review `bug.md` for known issues
- Update `progression.md` with schema changes

---

**END OF DOCUMENTATION**
