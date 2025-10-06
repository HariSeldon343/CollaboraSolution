# User Cleanup System - Documentation

## Overview

The user cleanup system permanently deletes soft-deleted users and all their related data while properly handling foreign key constraints. This guide explains how the system works and how to use it safely.

## How It Works

### Soft Delete vs Hard Delete

1. **Soft Delete** (`DELETE /api/users/delete.php`)
   - Sets `deleted_at` timestamp on the user record
   - User becomes inactive but data is preserved
   - User ID: 2 example: deleted on 2025-10-04 18:56:18

2. **Hard Delete/Cleanup** (`POST /api/users/cleanup_deleted.php`)
   - Permanently removes users soft-deleted for more than 7 days
   - Cascades deletion to all related records
   - Cannot be undone

### Foreign Key Constraints

The `users` table has 28 foreign key constraints from other tables. The cleanup process handles them in 4 phases:

#### Phase 1: RESTRICT Constraints (Must Delete First)

These prevent user deletion until child records are removed:

- `chat_channels.owner_id` - Chat channels owned by user
- `file_versions.uploaded_by` - File version history
- `folders.owner_id` - Folders owned by user
- `projects.owner_id` - Projects owned by user
- `project_members.added_by` - Set to NULL (who added member)
- `tasks.created_by` - Set to NULL (who created task)
- `task_assignments.assigned_by` - Set to NULL (who assigned task)

#### Phase 2: CASCADE Constraints (Auto-Delete)

These are explicitly deleted for clarity:

- `project_members.user_id` - Project memberships
- `task_assignments.user_id` - Task assignments
- `task_comments.user_id` - Task comments
- `chat_channel_members.user_id` - Chat memberships
- `chat_messages.user_id` - Chat messages
- `chat_message_reads.user_id` - Read receipts
- `file_shares` (both `shared_by` and `shared_with`)
- `calendar_events.organizer_id` - Calendar events
- `calendar_shares.user_id` - Calendar shares
- `document_approvals.requested_by` - Approval requests
- `approval_notifications.user_id` - Approval notifications
- `password_expiry_notifications.user_id` - Password notifications
- `user_permissions.user_id` - User permissions
- `user_tenant_access.user_id` - Tenant access

#### Phase 3: SET NULL Constraints (Preserve Data)

These fields are set to NULL to preserve historical data:

- `audit_logs.user_id` - Keep audit trail, anonymize user
- `document_approvals.reviewed_by` - Keep approval history
- `files.uploaded_by` - Keep files, anonymize uploader
- `tasks.assigned_to` - Unassign tasks
- `user_permissions.granted_by` - Anonymize who granted permission
- `user_tenant_access.granted_by` - Anonymize who granted access

#### Phase 4: Delete User Record

Finally, the user record is deleted from the `users` table.

## Usage

### Check Soft-Deleted Users

```bash
php /mnt/c/xampp/htdocs/CollaboraNexio/test_user_cleanup.php
```

Output shows:
- All soft-deleted users
- Related records count
- Eligibility for cleanup (> 7 days)

### Dry Run (Simulation)

```bash
php /mnt/c/xampp/htdocs/CollaboraNexio/dry_run_cleanup.php
```

Shows exactly what would be deleted without actually deleting anything.

### Execute Cleanup (API)

**Endpoint:** `POST /api/users/cleanup_deleted.php`

**Authentication:** Requires `admin` or `super_admin` role

**Request:**
```javascript
fetch('/api/users/cleanup_deleted.php', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
    }
})
```

**Response (Success):**
```json
{
    "success": true,
    "data": {
        "deleted_count": 1,
        "deleted_users": [
            {
                "id": 2,
                "email": "manager@demo.local",
                "name": "Manager User",
                "tenant_id": 1,
                "deleted_at": "2025-10-04 18:56:18"
            }
        ],
        "cleanup_date": "2025-10-11 10:30:00",
        "cleanup_summary": {
            "chat_channels_deleted": 1,
            "file_versions_deleted": 0,
            "folders_deleted": 0,
            "projects_deleted": 3
        }
    },
    "message": "Eliminati permanentemente 1 utenti soft-deleted da più di 7 giorni e tutti i dati correlati"
}
```

## Safety Features

### Transaction Atomicity

The entire cleanup process runs in a single database transaction:

```php
$conn->beginTransaction();

// All deletion operations...

// If successful
$conn->commit();

// If any error occurs
$conn->rollBack();
```

If ANY step fails, ALL changes are rolled back - no partial deletions.

### Role-Based Access

- **Admin**: Can cleanup users from their own tenant only
- **Super Admin**: Can cleanup users from all tenants

### 7-Day Grace Period

Users must be soft-deleted for at least 7 days before permanent deletion. This prevents accidental permanent deletion.

### Audit Logging

Every cleanup operation is logged in `audit_logs` with:
- Who performed the cleanup
- Which users were deleted
- Complete list of deleted user details
- Timestamp and summary

## Troubleshooting

### Foreign Key Constraint Error

If you see: `Cannot delete or update a parent row: a foreign key constraint fails`

This means the cleanup process missed a constraint. Check:

1. Run the constraint check script:
```bash
php /mnt/c/xampp/htdocs/CollaboraNexio/check_user_foreign_keys.php
```

2. Review the deletion order in the code

3. Ensure all RESTRICT constraints are handled in Phase 1

### User Not Eligible

If cleanup returns 0 users deleted:

- Check if user has been soft-deleted for more than 7 days
- Use `test_user_cleanup.php` to see eligibility status

### Permission Denied

Ensure the authenticated user has `admin` or `super_admin` role.

## Related Files

### API Endpoint
- `/api/users/cleanup_deleted.php` - Main cleanup endpoint

### Utility Scripts
- `/test_user_cleanup.php` - Check soft-deleted users status
- `/dry_run_cleanup.php` - Simulate cleanup without changes
- `/check_user_foreign_keys.php` - List all foreign key constraints

### Documentation
- `/database/user_deletion_cascade_strategy.sql` - Complete SQL strategy
- `/USER_CLEANUP_GUIDE.md` - This guide

## Best Practices

1. **Always test first** - Use dry run before actual cleanup
2. **Backup database** - Create backup before cleanup
3. **Review audit logs** - After cleanup, verify audit trail
4. **Monitor orphaned records** - Use verification queries
5. **Communicate** - Inform users before permanent deletion
6. **Document** - Keep record of cleanup operations

## Example Workflow

```bash
# Step 1: Check who will be deleted
php test_user_cleanup.php

# Step 2: Simulate the cleanup (dry run)
php dry_run_cleanup.php

# Step 3: Review the output carefully

# Step 4: Execute cleanup via API (from browser/frontend)
# POST /api/users/cleanup_deleted.php with proper authentication

# Step 5: Verify audit logs
# Check audit_logs table for cleanup record
```

## Database Schema Impact

After cleanup, the following data is:

**Deleted:**
- User record
- Chat channels owned by user
- Projects owned by user
- All user memberships and assignments
- All user messages and comments

**Preserved (with NULL user references):**
- Audit logs (for compliance)
- Files uploaded by user
- Tasks created by user
- Approval history

This balance ensures data integrity while allowing user removal.
