# Audit Log Schema Issues - Complete Report

## Summary

Multiple API files across the codebase are using incorrect column names when inserting into the `audit_logs` table. This causes 500 errors when those operations are triggered.

## Root Cause

The `audit_logs` table schema uses:
- `entity_type` (NOT `resource_type`)
- `entity_id` (NOT `resource_id`)

But many API files were written using the old/incorrect column names.

## Files Status

### ✅ FIXED - Tenant Management APIs

1. **`/api/tenants/delete.php`** (Line 138-139)
   - Status: FIXED
   - Change: `resource_type` → `entity_type`, `resource_id` → `entity_id`
   - Action: `tenant_deleted` → `delete`

2. **`/api/tenants/create.php`** (Line 318-319)
   - Status: FIXED
   - Change: `resource_type` → `entity_type`, `resource_id` → `entity_id`
   - Action: `tenant_created` → `create`

3. **`/api/tenants/update.php`** (Line 268-269)
   - Status: FIXED
   - Change: `resource_type` → `entity_type`, `resource_id` → `entity_id`
   - Action: `tenant_updated` → `update`

### ❌ NEEDS FIXING - Other APIs

4. **`/api/projects_complete.php`** (Lines 347-348, 658-673)
   - Status: NEEDS FIX
   - Issue: Function `logActivity()` uses `resource_type` and `resource_id`
   - Impact: Project creation, updates, task operations will fail to log
   - Lines affected:
     - Line 663: `tenant_id, user_id, action, resource_type,`
     - Line 664: `resource_id, ip_address, user_agent, metadata`
     - Line 672-673: Parameter binding

5. **`/api/files_complete.php`** (Lines 686-701)
   - Status: NEEDS FIX
   - Issue: Function `logActivity()` uses `resource_type` and `resource_id`
   - Impact: File uploads, downloads, updates will fail to log
   - Lines affected:
     - Line 691: `tenant_id, user_id, action, resource_type,`
     - Line 692: `resource_id, ip_address, user_agent, metadata`
     - Line 700-701: Parameter binding

6. **`/api/channels.php`** (Lines 855-856)
   - Status: NEEDS FIX
   - Issue: Uses `resource_type` and `resource_id` in queries
   - Impact: Channel/chat operations logging

## Correct Schema Reference

From `/database/06_audit_logs.sql`:

```sql
CREATE TABLE audit_logs (
    tenant_id INT(10) UNSIGNED NOT NULL,
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT(10) UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,    -- ✅ CORRECT
    entity_id INT UNSIGNED NULL,         -- ✅ CORRECT
    old_values JSON NULL,
    new_values JSON NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Action Items

### High Priority (Blocking Functionality)

1. ✅ Fix `/api/tenants/delete.php` - **DONE**
2. ✅ Fix `/api/tenants/create.php` - **DONE**
3. ✅ Fix `/api/tenants/update.php` - **DONE**

### Medium Priority (Non-Blocking but Should Fix)

4. ⏳ Fix `/api/projects_complete.php` - **TODO**
   - Update `logActivity()` function signature and implementation
   - Search for all calls to `logActivity()` and update

5. ⏳ Fix `/api/files_complete.php` - **TODO**
   - Update `logActivity()` function signature and implementation
   - Search for all calls to `logActivity()` and update

6. ⏳ Fix `/api/channels.php` - **TODO**
   - Update audit log queries

### Recommended Fix Pattern

**BEFORE:**
```php
function logActivity(string $action, string $resource_type, int $resource_id, array $metadata = []): void {
    // ...
    INSERT INTO audit_logs (
        tenant_id, user_id, action, resource_type,
        resource_id, ip_address, user_agent, metadata
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    // ...
}
```

**AFTER:**
```php
function logActivity(string $action, string $entity_type, int $entity_id, array $metadata = []): void {
    // ...
    INSERT INTO audit_logs (
        tenant_id, user_id, action, entity_type,
        entity_id, ip_address, user_agent, metadata
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    // ...
}
```

## Action Name Standardization

The `audit_logs.action` column should use standard CRUD verbs:
- `create` (not `tenant_created`, `file_uploaded`, etc.)
- `update` (not `tenant_updated`, `file_modified`, etc.)
- `delete` (not `tenant_deleted`, `file_deleted`, etc.)
- `view`, `download`, `approve`, `reject`, etc. (as needed)

This provides consistency and easier filtering of audit logs.

## Testing Recommendation

After fixing each file, test the following operations:

### Projects API (`projects_complete.php`)
- Create a new project
- Update a project
- Create a task
- Update a task
- Verify audit logs are created without errors

### Files API (`files_complete.php`)
- Upload a file
- Update file metadata
- Download a file
- Delete a file
- Verify audit logs are created without errors

### Channels API (`channels.php`)
- Create a channel
- Post a message
- Update a message
- Delete a message
- Verify audit logs are created without errors

## Prevention Strategy

1. **Add database constraint check** - Consider adding a CHECK constraint or trigger to validate column names
2. **Use ORM/Active Record pattern** - Abstract database operations to avoid column name typos
3. **Code review checklist** - Add audit log column verification to review process
4. **Automated tests** - Add integration tests that verify audit log insertion
5. **Schema documentation** - Keep `/database/` folder as single source of truth

## Current Impact

### Fixed (No Longer Causing Errors)
- ✅ Tenant deletion
- ✅ Tenant creation
- ✅ Tenant updates

### Potentially Broken (Needs Verification)
- ⚠️ Project operations logging
- ⚠️ File operations logging
- ⚠️ Chat/Channel operations logging

**Note:** These operations may still work, but audit logging will fail silently or cause 500 errors if logging is critical to the operation flow.

## Database Migration

**No migration required** - The database schema is correct. Only code changes are needed.

## Deployment Notes

- Can deploy tenant API fixes immediately (already done)
- Should batch the remaining fixes and deploy together
- No downtime required
- Backward compatible (won't break existing functionality)

---

**Status:** Partially Fixed (3 of 6 files)
**Priority:** High (tenant APIs) / Medium (other APIs)
**Estimated Effort:** 1-2 hours for remaining fixes + testing
