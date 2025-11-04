# File Permissions and Document Workflow System - Schema Documentation

**Version:** 1.0.0
**Date:** 2025-10-29
**Author:** Database Architect
**Project:** CollaboraNexio

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture Diagram](#architecture-diagram)
3. [Table Specifications](#table-specifications)
4. [Business Rules](#business-rules)
5. [State Machine](#state-machine)
6. [Email Notification Triggers](#email-notification-triggers)
7. [Security Model](#security-model)
8. [Query Examples](#query-examples)
9. [API Integration Guide](#api-integration-guide)
10. [Migration Instructions](#migration-instructions)

---

## Overview

This schema implements two critical features for CollaboraNexio:

### 1. File/Folder Assignment System
- **Purpose:** Restrict file/folder access to specific users within a tenant
- **Authorization:** Only managers and super_admins can assign
- **Access Rule:** Only assigned users + creators + managers + super_admins can access
- **Audit Trail:** Complete history of all assignments with reasons

### 2. Document Workflow System
- **Purpose:** Multi-stage approval process for documents
- **States:** bozza → in_validazione → validato → in_approvazione → approvato (or rifiutato at any stage)
- **Roles:** Creator, Validator, Approver
- **Notifications:** Email at every state transition
- **History:** Immutable audit trail of all transitions

---

## Architecture Diagram

```
┌──────────────────────────────────────────────────────────────┐
│                   COLLABONEXIO CORE TABLES                   │
│  ┌─────────┐   ┌─────────┐   ┌─────────┐   ┌─────────┐    │
│  │ tenants │   │  users  │   │  files  │   │ folders │    │
│  └────┬────┘   └────┬────┘   └────┬────┘   └────┬────┘    │
└───────┼─────────────┼─────────────┼─────────────┼──────────┘
        │             │             │             │
        │             │             │             │
┌───────┴─────────────┴─────────────┴─────────────┴──────────┐
│            FILE PERMISSIONS AND WORKFLOW SYSTEM              │
│                                                              │
│  ┌──────────────────────┐         ┌──────────────────────┐ │
│  │  file_assignments    │         │   workflow_roles     │ │
│  ├──────────────────────┤         ├──────────────────────┤ │
│  │ id (PK)              │         │ id (PK)              │ │
│  │ tenant_id (FK)       │         │ tenant_id (FK)       │ │
│  │ file_id (FK)         │         │ user_id (FK)         │ │
│  │ entity_type          │         │ workflow_role        │ │
│  │ assigned_to_user_id  │         │ assigned_by_user_id  │ │
│  │ assigned_by_user_id  │         │ is_active            │ │
│  │ assignment_reason    │         │ deleted_at           │ │
│  │ expires_at           │         │ created_at           │ │
│  │ deleted_at           │         │ updated_at           │ │
│  │ created_at           │         └──────────────────────┘ │
│  │ updated_at           │                                   │
│  └──────────────────────┘                                   │
│                                                              │
│  ┌──────────────────────┐         ┌──────────────────────┐ │
│  │  document_workflow   │         │ document_workflow_   │ │
│  ├──────────────────────┤         │      history         │ │
│  │ id (PK)              │         ├──────────────────────┤ │
│  │ tenant_id (FK)       │◄────────┤ workflow_id (FK)     │ │
│  │ file_id (FK)         │         │ tenant_id (FK)       │ │
│  │ current_state        │         │ file_id (FK)         │ │
│  │ created_by_user_id   │         │ from_state           │ │
│  │ current_handler_id   │         │ to_state             │ │
│  │ submitted_at         │         │ transition_type      │ │
│  │ validated_at         │         │ performed_by_user_id │ │
│  │ approved_at          │         │ user_role_at_time    │ │
│  │ rejected_at          │         │ comment              │ │
│  │ rejection_reason     │         │ metadata (JSON)      │ │
│  │ rejection_count      │         │ ip_address           │ │
│  │ deleted_at           │         │ user_agent           │ │
│  │ created_at           │         │ created_at           │ │
│  │ updated_at           │         └──────────────────────┘ │
│  └──────────────────────┘              (IMMUTABLE)          │
│                                                              │
└──────────────────────────────────────────────────────────────┘

Legend:
PK = Primary Key
FK = Foreign Key
◄──── = One-to-Many Relationship
```

---

## Table Specifications

### 1. file_assignments

**Purpose:** Track file/folder assignments to specific users with audit trail

**Columns:**

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | Unique assignment ID |
| `tenant_id` | INT UNSIGNED | NOT NULL, FK(tenants.id) CASCADE | Tenant isolation |
| `file_id` | INT UNSIGNED | NOT NULL, FK(files.id) CASCADE | File or folder being assigned |
| `entity_type` | ENUM('file', 'folder') | NOT NULL, DEFAULT 'file' | Type of entity |
| `assigned_to_user_id` | INT UNSIGNED | NOT NULL, FK(users.id) CASCADE | User receiving access |
| `assigned_by_user_id` | INT UNSIGNED | NOT NULL, FK(users.id) CASCADE | Manager who made assignment |
| `assignment_reason` | TEXT | NULL | Why assignment was made |
| `expires_at` | TIMESTAMP | NULL | Optional expiration date |
| `deleted_at` | TIMESTAMP | NULL | Soft delete (revoked) |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP ON UPDATE | Last update |

**Unique Constraints:**
- `uk_file_assignments_unique`: (file_id, assigned_to_user_id, deleted_at) - Prevent duplicate active assignments

**Indexes:**
- `idx_file_assignments_tenant_created`: (tenant_id, created_at)
- `idx_file_assignments_tenant_deleted`: (tenant_id, deleted_at)
- `idx_file_assignments_file`: (file_id, deleted_at)
- `idx_file_assignments_user`: (assigned_to_user_id, deleted_at)
- `idx_file_assignments_assigner`: (assigned_by_user_id)
- `idx_file_assignments_expires`: (expires_at)
- `idx_file_assignments_entity`: (entity_type, file_id)

---

### 2. workflow_roles

**Purpose:** Define validators and approvers per tenant

**Columns:**

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | Unique role ID |
| `tenant_id` | INT UNSIGNED | NOT NULL, FK(tenants.id) CASCADE | Tenant isolation |
| `user_id` | INT UNSIGNED | NOT NULL, FK(users.id) CASCADE | User with workflow role |
| `workflow_role` | ENUM('validator', 'approver') | NOT NULL | Role type |
| `assigned_by_user_id` | INT UNSIGNED | NOT NULL, FK(users.id) CASCADE | Manager who configured |
| `is_active` | TINYINT(1) | NOT NULL, DEFAULT 1 | Can be temporarily disabled |
| `deleted_at` | TIMESTAMP | NULL | Soft delete (revoked) |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP ON UPDATE | Last update |

**Unique Constraints:**
- `uk_workflow_roles_unique`: (tenant_id, user_id, workflow_role, deleted_at) - Prevent duplicate active roles

**Indexes:**
- `idx_workflow_roles_tenant_created`: (tenant_id, created_at)
- `idx_workflow_roles_tenant_deleted`: (tenant_id, deleted_at)
- `idx_workflow_roles_user`: (user_id, deleted_at)
- `idx_workflow_roles_role`: (workflow_role, is_active, deleted_at)
- `idx_workflow_roles_active`: (tenant_id, is_active, deleted_at)

---

### 3. document_workflow

**Purpose:** Track current workflow state for each document

**Columns:**

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | Unique workflow ID |
| `tenant_id` | INT UNSIGNED | NOT NULL, FK(tenants.id) CASCADE | Tenant isolation |
| `file_id` | INT UNSIGNED | NOT NULL, FK(files.id) CASCADE | Document reference |
| `current_state` | ENUM(...) | NOT NULL, DEFAULT 'bozza' | Current workflow state |
| `created_by_user_id` | INT UNSIGNED | NOT NULL, FK(users.id) CASCADE | Document creator |
| `current_handler_user_id` | INT UNSIGNED | NULL, FK(users.id) SET NULL | Current validator/approver |
| `submitted_at` | TIMESTAMP | NULL | When submitted for validation |
| `validated_at` | TIMESTAMP | NULL | When validator approved |
| `approved_at` | TIMESTAMP | NULL | When approver approved |
| `rejected_at` | TIMESTAMP | NULL | When rejected |
| `rejection_reason` | TEXT | NULL | Why document was rejected |
| `rejection_count` | INT UNSIGNED | NOT NULL, DEFAULT 0 | Number of rejections |
| `deleted_at` | TIMESTAMP | NULL | Soft delete (cancelled) |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP ON UPDATE | Last update |

**States:**
- `bozza` - Draft (initial state)
- `in_validazione` - Submitted for validation
- `validato` - Validated by validator
- `in_approvazione` - Submitted for approval
- `approvato` - Final approval
- `rifiutato` - Rejected (can return to bozza)

**Unique Constraints:**
- `uk_document_workflow_file`: (file_id, deleted_at) - One active workflow per document

**Indexes:**
- `idx_document_workflow_tenant_created`: (tenant_id, created_at)
- `idx_document_workflow_tenant_deleted`: (tenant_id, deleted_at)
- `idx_document_workflow_state`: (tenant_id, current_state, deleted_at)
- `idx_document_workflow_creator`: (created_by_user_id, deleted_at)
- `idx_document_workflow_handler`: (current_handler_user_id, deleted_at)
- `idx_document_workflow_dates`: (submitted_at, validated_at, approved_at)

---

### 4. document_workflow_history

**Purpose:** Complete immutable audit trail of ALL workflow transitions

**Columns:**

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | INT UNSIGNED | PK, AUTO_INCREMENT | Unique history ID |
| `tenant_id` | INT UNSIGNED | NOT NULL, FK(tenants.id) CASCADE | Tenant isolation |
| `workflow_id` | INT UNSIGNED | NOT NULL, FK(document_workflow.id) CASCADE | Workflow reference |
| `file_id` | INT UNSIGNED | NOT NULL, FK(files.id) CASCADE | Document reference (denormalized) |
| `from_state` | ENUM(...) | NULL | Previous state (NULL for creation) |
| `to_state` | ENUM(...) | NOT NULL | New state |
| `transition_type` | ENUM(...) | NOT NULL | Type of transition |
| `performed_by_user_id` | INT UNSIGNED | NOT NULL, FK(users.id) SET NULL | User who made transition |
| `user_role_at_time` | ENUM(...) | NOT NULL | Role of user at transition time |
| `comment` | TEXT | NULL | Comment or rejection reason |
| `metadata` | JSON | NULL | Additional transition metadata |
| `ip_address` | VARCHAR(45) | NULL | IP address of user |
| `user_agent` | VARCHAR(255) | NULL | Browser user agent |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | Transition timestamp |

**Transition Types:**
- `submit` - Creator submits for validation
- `validate` - Validator approves for next stage
- `reject_to_creator` - Validator/Approver rejects
- `approve` - Approver final approval
- `recall` - Creator recalls from validation
- `cancel` - Workflow cancelled

**User Roles:**
- `creator` - Document creator
- `validator` - User with validator role
- `approver` - User with approver role
- `admin` - Admin role
- `super_admin` - Super admin role

**IMPORTANT:** This table has **NO deleted_at** column - all history is immutable.

**Indexes:**
- `idx_workflow_history_tenant_created`: (tenant_id, created_at)
- `idx_workflow_history_workflow`: (workflow_id, created_at)
- `idx_workflow_history_file`: (file_id, created_at)
- `idx_workflow_history_user`: (performed_by_user_id, created_at)
- `idx_workflow_history_state`: (to_state, created_at)
- `idx_workflow_history_transition`: (transition_type, created_at)

---

## Business Rules

### File/Folder Assignment Rules

1. **Authorization:**
   - Only `manager` and `super_admin` roles can create assignments
   - Assignments are tenant-isolated (can't assign across tenants)

2. **Access Control:**
   ```
   User can access file/folder IF:
   - User role is super_admin (bypasses all restrictions) OR
   - User role is manager (bypasses tenant restrictions) OR
   - User is the file creator (uploaded_by) OR
   - User has active assignment (deleted_at IS NULL AND expires_at > NOW OR expires_at IS NULL)
   ```

3. **Assignment Expiration:**
   - `expires_at IS NULL` = permanent assignment
   - `expires_at > NOW()` = temporary assignment (still active)
   - `expires_at < NOW()` = expired assignment (access revoked)

4. **Assignment Revocation:**
   - Soft delete: `UPDATE file_assignments SET deleted_at = NOW() WHERE id = ?`
   - Must log to audit_logs: `AuditLogger::logDelete('file_assignment', ...)`

5. **Folder Assignment Inheritance:**
   - Assigning a folder gives access to ALL files within that folder
   - API must recursively check parent folder assignments

---

### Document Workflow Rules

1. **Workflow Initialization:**
   - Only documents (not folders) can enter workflow
   - Creator decides: keep as bozza OR submit for validation
   - Initial state: `bozza` (draft)

2. **State Transitions (Valid):**
   ```
   bozza → in_validazione (creator submits)
   in_validazione → validato (validator approves)
   in_validazione → rifiutato (validator rejects)
   validato → in_approvazione (auto-transition)
   in_approvazione → approvato (approver approves)
   in_approvazione → rifiutato (approver rejects)
   rifiutato → bozza (creator edits and resubmits)
   Any state → bozza (creator recalls)
   ```

3. **Role Permissions:**
   - **Creator:**
     - Submit for validation (bozza → in_validazione)
     - Recall from validation (any state → bozza)
     - Edit after rejection (rifiutato → bozza)
   - **Validator:**
     - Approve validation (in_validazione → validato)
     - Reject (in_validazione → rifiutato)
   - **Approver:**
     - Final approval (in_approvazione → approvato)
     - Reject (in_approvazione → rifiutato)

4. **Rejection Handling:**
   - `rejection_count` increments on each rejection
   - `rejection_reason` must be provided (TEXT, min 10 chars)
   - Document returns to `bozza` state for creator to edit
   - Creator can resubmit unlimited times

5. **Audit Trail:**
   - Every state transition MUST create entry in `document_workflow_history`
   - Every state transition MUST log to `audit_logs` via AuditLogger
   - History records are IMMUTABLE (no deleted_at)

---

## State Machine

```
                                ┌─────────────┐
                                │    bozza    │ (Draft - Initial)
                                └──────┬──────┘
                                       │ submit (creator)
                                       ▼
                              ┌─────────────────┐
                              │ in_validazione  │ (Pending validation)
                              └────┬────────┬───┘
                      validate    │        │   reject (validator)
                    (validator)   │        │
                                 ▼        ▼
                        ┌──────────┐   ┌──────────┐
                        │ validato │   │ rifiutato│◄──┐
                        └────┬─────┘   └────┬─────┘   │
                             │               │         │ reject
                   auto      │               │         │ (approver)
                transition   │               │         │
                             ▼               │         │
                    ┌──────────────────┐    │         │
                    │ in_approvazione  │    │         │
                    └────┬─────────────┘    │         │
                         │ approve          │         │
                    (approver)              │         │
                         ▼                  │         │
                    ┌──────────┐            │         │
                    │ approvato│            │         │
                    └──────────┘            │         │
                         │                  │         │
                         └──────────────────┴─────────┘
                                    resubmit (creator)

Special transitions:
- Any state → bozza (creator recall)
- Any state → cancelled (admin/super_admin via deleted_at)
```

---

## Email Notification Triggers

### When to Send Email Notifications

| Event | Trigger | Recipients | Template |
|-------|---------|-----------|----------|
| **Submit for Validation** | State: bozza → in_validazione | All active validators | `workflow_submitted_to_validation.html` |
| **Validation Approved** | State: in_validazione → validato | Creator + all active approvers | `workflow_validation_approved.html` |
| **Validation Rejected** | State: in_validazione → rifiutato | Creator only | `workflow_validation_rejected.html` |
| **Final Approval** | State: in_approvazione → approvato | Creator only | `workflow_final_approved.html` |
| **Final Rejection** | State: in_approvazione → rifiutato | Creator only | `workflow_final_rejected.html` |
| **Assignment Created** | file_assignments INSERT | Assigned user | `file_assigned.html` |
| **Assignment Expiring** | expires_at in 3 days | Assigned user | `file_assignment_expiring.html` |
| **Assignment Expired** | expires_at < NOW() | Assigned user | `file_assignment_expired.html` |

### Email Template Variables

```php
// Example email data array
$emailData = [
    'document_name' => $fileName,
    'document_url' => BASE_URL . '/files.php?id=' . $fileId,
    'creator_name' => $creatorName,
    'validator_name' => $validatorName,  // For validation events
    'approver_name' => $approverName,    // For approval events
    'rejection_reason' => $rejectionReason,
    'rejection_count' => $rejectionCount,
    'current_state' => $currentState,
    'previous_state' => $previousState,
    'transition_date' => date('d/m/Y H:i', strtotime($createdAt)),
    'tenant_name' => $tenantName,
];
```

---

## Security Model

### 1. Multi-Tenant Isolation (CRITICAL)

**Rule:** EVERY query MUST filter by tenant_id

```php
// ✅ CORRECT
WHERE tenant_id = ? AND deleted_at IS NULL

// ❌ WRONG - Security vulnerability!
WHERE deleted_at IS NULL
```

**Exception:** `super_admin` role can bypass tenant filtering for administrative tasks.

---

### 2. Role-Based Authorization

| Operation | Allowed Roles |
|-----------|---------------|
| **Create Assignment** | manager, super_admin |
| **View Assignment** | assigned_user, creator, manager, super_admin |
| **Revoke Assignment** | assigner, manager, super_admin |
| **Configure Workflow Roles** | manager, super_admin |
| **Submit for Validation** | creator (file owner) |
| **Validate Document** | validator role |
| **Approve Document** | approver role |
| **Recall Document** | creator (at any state) |
| **Cancel Workflow** | admin, super_admin |

---

### 3. Access Check Example

```php
function canUserAccessFile($userId, $userRole, $tenantId, $fileId, $uploadedBy) {
    // Super admin bypasses all restrictions
    if ($userRole === 'super_admin') {
        return true;
    }

    // Manager bypasses tenant restrictions
    if ($userRole === 'manager') {
        return true;
    }

    // Creator always has access
    if ($uploadedBy === $userId) {
        return true;
    }

    // Check active assignment
    $db = Database::getInstance();
    $assignment = $db->fetchOne(
        "SELECT id FROM file_assignments
         WHERE file_id = ?
           AND assigned_to_user_id = ?
           AND tenant_id = ?
           AND deleted_at IS NULL
           AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1",
        [$fileId, $userId, $tenantId]
    );

    return $assignment !== false;
}
```

---

## Query Examples

### 1. Get All Active Assignments for a File

```sql
SELECT
    fa.id,
    fa.file_id,
    fa.entity_type,
    fa.assigned_to_user_id,
    u_assigned.name as assigned_to_name,
    u_assigned.email as assigned_to_email,
    fa.assigned_by_user_id,
    u_assigner.name as assigned_by_name,
    fa.assignment_reason,
    fa.expires_at,
    fa.created_at
FROM file_assignments fa
INNER JOIN users u_assigned ON fa.assigned_to_user_id = u_assigned.id
INNER JOIN users u_assigner ON fa.assigned_by_user_id = u_assigner.id
WHERE fa.tenant_id = ?
  AND fa.file_id = ?
  AND fa.deleted_at IS NULL
ORDER BY fa.created_at DESC;
```

---

### 2. Get All Files Assigned to a User

```sql
SELECT
    f.id,
    f.name,
    f.file_path,
    f.file_type,
    f.file_size,
    fa.entity_type,
    fa.expires_at,
    fa.assignment_reason,
    fa.created_at as assigned_at
FROM file_assignments fa
INNER JOIN files f ON fa.file_id = f.id
WHERE fa.tenant_id = ?
  AND fa.assigned_to_user_id = ?
  AND fa.deleted_at IS NULL
  AND f.deleted_at IS NULL
  AND (fa.expires_at IS NULL OR fa.expires_at > NOW())
ORDER BY fa.created_at DESC;
```

---

### 3. Get All Validators for a Tenant

```sql
SELECT
    wr.id,
    wr.user_id,
    u.name,
    u.email,
    wr.is_active,
    wr.created_at
FROM workflow_roles wr
INNER JOIN users u ON wr.user_id = u.id
WHERE wr.tenant_id = ?
  AND wr.workflow_role = 'validator'
  AND wr.deleted_at IS NULL
  AND wr.is_active = 1
  AND u.deleted_at IS NULL
ORDER BY u.name;
```

---

### 4. Get Documents Pending Validation

```sql
SELECT
    dw.id,
    dw.file_id,
    f.name as document_name,
    f.file_path,
    dw.current_state,
    dw.submitted_at,
    dw.created_by_user_id,
    u.name as creator_name,
    u.email as creator_email,
    dw.rejection_count
FROM document_workflow dw
INNER JOIN files f ON dw.file_id = f.id
INNER JOIN users u ON dw.created_by_user_id = u.id
WHERE dw.tenant_id = ?
  AND dw.current_state = 'in_validazione'
  AND dw.deleted_at IS NULL
  AND f.deleted_at IS NULL
ORDER BY dw.submitted_at ASC;
```

---

### 5. Get Complete Workflow History for Document

```sql
SELECT
    dwh.id,
    dwh.from_state,
    dwh.to_state,
    dwh.transition_type,
    dwh.performed_by_user_id,
    u.name as performed_by_name,
    u.email as performed_by_email,
    dwh.user_role_at_time,
    dwh.comment,
    dwh.metadata,
    dwh.ip_address,
    dwh.created_at as transition_date
FROM document_workflow_history dwh
LEFT JOIN users u ON dwh.performed_by_user_id = u.id
WHERE dwh.tenant_id = ?
  AND dwh.file_id = ?
ORDER BY dwh.created_at DESC;
```

---

### 6. Get Workflow Statistics for Tenant

```sql
SELECT
    current_state,
    COUNT(*) as count,
    AVG(TIMESTAMPDIFF(DAY, created_at, COALESCE(approved_at, rejected_at, NOW()))) as avg_days
FROM document_workflow
WHERE tenant_id = ?
  AND deleted_at IS NULL
GROUP BY current_state
ORDER BY
    CASE current_state
        WHEN 'bozza' THEN 1
        WHEN 'in_validazione' THEN 2
        WHEN 'validato' THEN 3
        WHEN 'in_approvazione' THEN 4
        WHEN 'approvato' THEN 5
        WHEN 'rifiutato' THEN 6
    END;
```

---

## API Integration Guide

### Required API Endpoints

#### 1. File Assignment Endpoints

**POST /api/files/assign.php**
- Create new assignment
- Authorization: manager, super_admin
- Audit: `AuditLogger::logCreate('file_assignment', ...)`

**GET /api/files/assignments.php**
- List all assignments for a file
- Authorization: creator, manager, super_admin

**DELETE /api/files/assign.php?id={assignment_id}**
- Revoke assignment (soft delete)
- Authorization: assigner, manager, super_admin
- Audit: `AuditLogger::logDelete('file_assignment', ...)`

---

#### 2. Workflow Role Endpoints

**POST /api/workflow/roles/create.php**
- Assign validator/approver role
- Authorization: manager, super_admin
- Audit: `AuditLogger::logCreate('workflow_role', ...)`

**GET /api/workflow/roles/list.php**
- List validators/approvers
- Authorization: manager, super_admin

**PUT /api/workflow/roles/toggle.php**
- Enable/disable role (is_active)
- Authorization: manager, super_admin
- Audit: `AuditLogger::logUpdate('workflow_role', ...)`

---

#### 3. Document Workflow Endpoints

**POST /api/documents/workflow/submit.php**
- Submit document for validation
- Authorization: creator
- State: bozza → in_validazione
- Audit: `AuditLogger::logUpdate('document_workflow', ...)`
- Email: Notify validators

**POST /api/documents/workflow/validate.php**
- Validate document
- Authorization: validator role
- State: in_validazione → validato OR rifiutato
- Audit: `AuditLogger::logUpdate('document_workflow', ...)`
- Email: Notify creator (+ approvers if validated)

**POST /api/documents/workflow/approve.php**
- Final approval
- Authorization: approver role
- State: in_approvazione → approvato OR rifiutato
- Audit: `AuditLogger::logUpdate('document_workflow', ...)`
- Email: Notify creator

**POST /api/documents/workflow/recall.php**
- Creator recalls document
- Authorization: creator
- State: any → bozza
- Audit: `AuditLogger::logUpdate('document_workflow', ...)`

**GET /api/documents/workflow/history.php?file_id={file_id}**
- Get complete workflow history
- Authorization: creator, validators, approvers, manager, super_admin

---

### Audit Logging Pattern

```php
require_once __DIR__ . '/../../includes/audit_helper.php';

// Log BEFORE operation for captures state
try {
    // Create assignment example
    $newAssignmentId = $db->insert('file_assignments', [
        'tenant_id' => $tenantId,
        'file_id' => $fileId,
        'entity_type' => $entityType,
        'assigned_to_user_id' => $assignedToUserId,
        'assigned_by_user_id' => $userId,
        'assignment_reason' => $reason
    ]);

    // AFTER successful operation
    AuditLogger::logCreate(
        $userId,
        $tenantId,
        'file_assignment',
        $newAssignmentId,
        'File assigned to user',
        ['file_id' => $fileId, 'assigned_to' => $assignedToUserId]
    );

} catch (Exception $auditEx) {
    error_log('[FILE_ASSIGNMENT] Audit log failed: ' . $auditEx->getMessage());
    // DO NOT throw - operation succeeded
}
```

---

## Migration Instructions

### Step 1: Backup Database

```bash
# Create backup before migration
mysqldump -u root collaboranexio > collaboranexio_backup_$(date +%Y%m%d_%H%M%S).sql
```

---

### Step 2: Run Migration

```bash
# Execute migration
mysql -u root collaboranexio < database/migrations/file_permissions_workflow_system.sql
```

**Expected Output:**
```
status: Migration completed successfully
file_assignments_count: 0
workflow_roles_count: 2  (if demo data inserted)
document_workflow_count: 0
workflow_history_count: 0
executed_at: 2025-10-29 14:30:00
```

---

### Step 3: Verify Tables

```bash
# Verify table structure
mysql -u root collaboranexio -e "DESCRIBE file_assignments;"
mysql -u root collaboranexio -e "DESCRIBE workflow_roles;"
mysql -u root collaboranexio -e "DESCRIBE document_workflow;"
mysql -u root collaboranexio -e "DESCRIBE document_workflow_history;"
```

---

### Step 4: Verify Indexes

```bash
# Check indexes created
mysql -u root collaboranexio -e "SHOW INDEX FROM file_assignments WHERE Key_name LIKE 'idx_%';"
```

**Expected:** 7 indexes (tenant_created, tenant_deleted, file, user, assigner, expires, entity)

---

### Step 5: Test Data Insertion

```sql
-- Test assignment creation
INSERT INTO file_assignments
(tenant_id, file_id, entity_type, assigned_to_user_id, assigned_by_user_id, assignment_reason)
VALUES (1, 1, 'file', 2, 1, 'Test assignment');

-- Verify insertion
SELECT * FROM file_assignments WHERE id = LAST_INSERT_ID();
```

---

### Step 6: Rollback (if needed)

```bash
# Rollback migration
mysql -u root collaboranexio < database/migrations/file_permissions_workflow_system_rollback.sql
```

---

## Performance Considerations

### 1. Index Usage

All queries should use indexes. Verify with EXPLAIN:

```sql
EXPLAIN SELECT * FROM file_assignments
WHERE tenant_id = 1 AND deleted_at IS NULL;

-- Expected: Using idx_file_assignments_tenant_deleted
```

---

### 2. Query Optimization

- **ALWAYS filter by tenant_id first** (uses idx_tenant_created or idx_tenant_deleted)
- **ALWAYS check deleted_at IS NULL** (uses idx_tenant_deleted)
- **Use composite indexes** for multi-column filters

---

### 3. Large Dataset Handling

For tenants with > 10,000 assignments:

- Add pagination to list queries (LIMIT + OFFSET)
- Consider partitioning by tenant_id
- Archive old workflow history (> 1 year) to separate table

---

## Enum Value Reference

### current_state (document_workflow)
```sql
ENUM('bozza', 'in_validazione', 'validato', 'in_approvazione', 'approvato', 'rifiutato')
```

### transition_type (document_workflow_history)
```sql
ENUM('submit', 'validate', 'reject_to_creator', 'approve', 'recall', 'cancel')
```

### user_role_at_time (document_workflow_history)
```sql
ENUM('creator', 'validator', 'approver', 'admin', 'super_admin')
```

### workflow_role (workflow_roles)
```sql
ENUM('validator', 'approver')
```

### entity_type (file_assignments)
```sql
ENUM('file', 'folder')
```

---

## Troubleshooting

### Issue: Assignment not visible to user

**Cause:** Assignment expired or soft-deleted

**Fix:**
```sql
-- Check assignment status
SELECT *, deleted_at, expires_at FROM file_assignments WHERE file_id = ? AND assigned_to_user_id = ?;

-- If expired, extend expiration
UPDATE file_assignments SET expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?;

-- If soft-deleted, restore
UPDATE file_assignments SET deleted_at = NULL WHERE id = ?;
```

---

### Issue: Workflow stuck in state

**Cause:** No validators/approvers configured

**Fix:**
```sql
-- Check active validators
SELECT * FROM workflow_roles WHERE tenant_id = ? AND workflow_role = 'validator' AND is_active = 1 AND deleted_at IS NULL;

-- If none, assign validator role
INSERT INTO workflow_roles (tenant_id, user_id, workflow_role, assigned_by_user_id) VALUES (?, ?, 'validator', ?);
```

---

### Issue: Duplicate assignment error

**Cause:** Trying to create duplicate active assignment

**Fix:**
```sql
-- Revoke existing assignment first
UPDATE file_assignments SET deleted_at = NOW() WHERE file_id = ? AND assigned_to_user_id = ? AND deleted_at IS NULL;

-- Then create new assignment
INSERT INTO file_assignments (...) VALUES (...);
```

---

## Future Enhancements

### 1. Assignment Templates
- Pre-defined assignment groups (e.g., "Accounting Team", "Management")
- Bulk assign to multiple users

### 2. Workflow Customization
- Per-tenant workflow configuration (optional states)
- Conditional approval (e.g., documents > $10k need CEO approval)

### 3. Advanced Notifications
- Slack/Teams integration
- SMS notifications for urgent approvals
- Configurable notification preferences per user

### 4. Workflow Analytics
- Average approval time by tenant
- Bottleneck identification (which stage takes longest)
- Rejection rate by validator/approver

---

## Contact & Support

For schema issues or questions:
- **Internal:** Database Architect Team
- **Documentation:** `/database/FILE_PERMISSIONS_WORKFLOW_SCHEMA_DOC.md`
- **Migration Files:** `/database/migrations/file_permissions_workflow_system*.sql`

---

**Last Updated:** 2025-10-29
**Schema Version:** 1.0.0
**Migration Status:** Ready for Production
