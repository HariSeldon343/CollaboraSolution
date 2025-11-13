# File Permissions & Document Workflow - Quick Reference

**Quick Start Guide for Developers**

---

## Migration Commands

```bash
# Execute migration
mysql -u root collaboranexio < database/migrations/file_permissions_workflow_system.sql

# Verify tables created
mysql -u root collaboranexio -e "SHOW TABLES LIKE '%workflow%' OR LIKE '%assignment%';"

# Rollback if needed
mysql -u root collaboranexio < database/migrations/file_permissions_workflow_system_rollback.sql
```

---

## Tables Created

| Table | Purpose | Has deleted_at? |
|-------|---------|-----------------|
| `file_assignments` | File/folder assignments | ✅ Yes |
| `workflow_roles` | Validators/approvers config | ✅ Yes |
| `document_workflow` | Current workflow state | ✅ Yes |
| `document_workflow_history` | Immutable audit trail | ❌ No (immutable) |

---

## PHP Constants

```php
require_once __DIR__ . '/includes/workflow_constants.php';

// Workflow States
WORKFLOW_STATE_DRAFT            // 'bozza'
WORKFLOW_STATE_IN_VALIDATION    // 'in_validazione'
WORKFLOW_STATE_VALIDATED        // 'validato'
WORKFLOW_STATE_IN_APPROVAL      // 'in_approvazione'
WORKFLOW_STATE_APPROVED         // 'approvato'
WORKFLOW_STATE_REJECTED         // 'rifiutato'

// Transition Types
TRANSITION_SUBMIT               // 'submit'
TRANSITION_VALIDATE             // 'validate'
TRANSITION_REJECT               // 'reject_to_creator'
TRANSITION_APPROVE              // 'approve'
TRANSITION_RECALL               // 'recall'

// Workflow Roles
WORKFLOW_ROLE_VALIDATOR         // 'validator'
WORKFLOW_ROLE_APPROVER          // 'approver'

// Entity Types
ENTITY_TYPE_FILE                // 'file'
ENTITY_TYPE_FOLDER              // 'folder'
```

---

## Common Queries

### Check User Access
```php
$canAccess = canUserAccessFile(
    $userId,
    $userRole,
    $tenantId,
    $fileId,
    $uploadedBy
);
```

### Get Active Validators
```php
$validatorIds = getActiveValidators($tenantId);
```

### Validate State Transition
```php
$isValid = isValidWorkflowTransition(
    WORKFLOW_STATE_DRAFT,
    WORKFLOW_STATE_IN_VALIDATION
);
```

### Check Assignment Expiration
```php
$expired = isAssignmentExpired($expiresAt);
$expiringSoon = isAssignmentExpiringSoon($expiresAt, 7);
```

---

## State Machine (Visual)

```
bozza → in_validazione → validato → in_approvazione → approvato
  ↑           ↓             ↓             ↓
  └───────────┴─────────────┴─────────────┘
         (rifiutato - rejection)
```

**Valid Transitions:**
- `bozza → in_validazione` (submit)
- `in_validazione → validato` (validate)
- `in_validazione → rifiutato` (reject)
- `validato → in_approvazione` (auto)
- `in_approvazione → approvato` (approve)
- `in_approvazione → rifiutato` (reject)
- `rifiutato → bozza` (resubmit)
- `Any → bozza` (recall)

---

## Authorization Matrix

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

## Audit Logging Pattern

```php
require_once __DIR__ . '/includes/audit_helper.php';

// After creating assignment
try {
    AuditLogger::logCreate(
        $userId,
        $tenantId,
        'file_assignment',
        $newAssignmentId,
        'File assigned to user',
        ['file_id' => $fileId, 'assigned_to' => $assignedToUserId]
    );
} catch (Exception $e) {
    error_log('[FILE_ASSIGNMENT] Audit failed: ' . $e->getMessage());
}

// After workflow transition
try {
    AuditLogger::logUpdate(
        $userId,
        $tenantId,
        'document_workflow',
        $workflowId,
        'Document state changed',
        ['from' => $fromState],
        ['to' => $toState]
    );
} catch (Exception $e) {
    error_log('[WORKFLOW] Audit failed: ' . $e->getMessage());
}
```

---

## Email Notifications

### When to Send

| Event | Recipients |
|-------|-----------|
| Submit for validation | All validators |
| Validation approved | Creator + all approvers |
| Validation rejected | Creator only |
| Final approval | Creator only |
| Final rejection | Creator only |
| Assignment created | Assigned user |
| Assignment expiring | Assigned user (7 days before) |

### Example Email Trigger

```php
require_once __DIR__ . '/includes/mailer.php';

// Get validators to notify
$validatorIds = getActiveValidators($tenantId);

foreach ($validatorIds as $validatorId) {
    $validator = $db->fetchOne(
        "SELECT email, name FROM users WHERE id = ? AND deleted_at IS NULL",
        [$validatorId]
    );

    if ($validator) {
        $emailData = [
            'document_name' => $fileName,
            'document_url' => BASE_URL . '/files.php?id=' . $fileId,
            'creator_name' => $creatorName,
            'tenant_name' => $tenantName
        ];

        sendEmail(
            $validator['email'],
            'Nuovo documento da validare: ' . $fileName,
            'workflow_submitted_to_validation',
            $emailData
        );
    }
}
```

---

## Error Handling

### Common Errors

1. **Duplicate Assignment**
   ```
   Error: Duplicate entry for key 'uk_file_assignments_unique'
   Fix: Revoke existing assignment first (soft delete)
   ```

2. **Invalid State Transition**
   ```
   Error: Cannot transition from X to Y
   Fix: Use isValidWorkflowTransition() to check first
   ```

3. **Missing Validators**
   ```
   Error: No active validators for tenant
   Fix: Configure validators via workflow_roles table
   ```

4. **Assignment Expired**
   ```
   Error: User cannot access file
   Fix: Check expires_at, extend if needed
   ```

---

## Database Indexes (Performance)

All tables have composite indexes for multi-tenant queries:

```sql
-- Pattern for ALL queries
WHERE tenant_id = ? AND deleted_at IS NULL

-- Uses: idx_{table}_tenant_deleted
```

**ALWAYS filter by tenant_id first for optimal performance.**

---

## Testing Checklist

- [ ] Migration executes without errors
- [ ] All 4 tables created
- [ ] Indexes created (7 per table)
- [ ] Foreign keys verified
- [ ] Demo data inserted (2 workflow_roles)
- [ ] Test assignment creation
- [ ] Test workflow state transitions
- [ ] Test soft delete (deleted_at)
- [ ] Test access control (canUserAccessFile)
- [ ] Test email notifications
- [ ] Verify audit logging
- [ ] Check multi-tenant isolation

---

## Rollback Instructions

```bash
# 1. Backup data (optional)
mysql -u root collaboranexio -e "
CREATE TABLE file_assignments_backup AS SELECT * FROM file_assignments;
CREATE TABLE workflow_roles_backup AS SELECT * FROM workflow_roles;
CREATE TABLE document_workflow_backup AS SELECT * FROM document_workflow;
CREATE TABLE document_workflow_history_backup AS SELECT * FROM document_workflow_history;
"

# 2. Execute rollback
mysql -u root collaboranexio < database/migrations/file_permissions_workflow_system_rollback.sql

# 3. Verify tables dropped
mysql -u root collaboranexio -e "SHOW TABLES LIKE '%workflow%';"
```

---

## Performance Tips

1. **Always filter by tenant_id first**
   ```sql
   WHERE tenant_id = ? AND deleted_at IS NULL AND current_state = ?
   ```

2. **Use composite indexes**
   - Queries with (tenant_id, deleted_at) → idx_*_tenant_deleted
   - Queries with (tenant_id, created_at) → idx_*_tenant_created

3. **Avoid N+1 queries**
   - Join users table for creator/validator/approver names
   - Join files table for document details

4. **Paginate large result sets**
   ```sql
   LIMIT 20 OFFSET 0
   ```

---

## Security Checklist

- [ ] ALWAYS filter by tenant_id (except super_admin)
- [ ] ALWAYS check deleted_at IS NULL
- [ ] Validate user role before transitions
- [ ] Check workflow role (validator/approver)
- [ ] Verify file access via canUserAccessFile()
- [ ] Use prepared statements (NEVER concat SQL)
- [ ] Sanitize rejection reasons (htmlspecialchars)
- [ ] Verify CSRF token on ALL POST requests
- [ ] Log ALL operations to audit_logs

---

## Files Reference

| File | Purpose |
|------|---------|
| `database/migrations/file_permissions_workflow_system.sql` | Migration script |
| `database/migrations/file_permissions_workflow_system_rollback.sql` | Rollback script |
| `database/FILE_PERMISSIONS_WORKFLOW_SCHEMA_DOC.md` | Complete documentation |
| `database/WORKFLOW_QUICK_REFERENCE.md` | This file |
| `includes/workflow_constants.php` | PHP constants and helpers |

---

## Support

For issues or questions:
- **Documentation:** `/database/FILE_PERMISSIONS_WORKFLOW_SCHEMA_DOC.md`
- **Schema:** `/database/migrations/file_permissions_workflow_system.sql`
- **Constants:** `/includes/workflow_constants.php`

---

**Last Updated:** 2025-10-29
**Schema Version:** 1.0.0
**Status:** Production Ready ✅
