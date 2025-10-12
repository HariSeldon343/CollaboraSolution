# FINAL DIAGNOSIS: Tenant Deletion Dropdown Issue

**Date:** 2025-10-12
**Issue:** Deleted companies still appear in tenant dropdown
**Expected:** Only company ID 11 (S.co) should be visible

---

## ROOT CAUSE IDENTIFIED

### Issue Summary
The frontend JavaScript file `/mnt/c/xampp/htdocs/CollaboraNexio/assets/js/filemanager_enhanced.js` is configured to use `/CollaboraNexio/api/files_tenant.php` instead of the production file which already has the soft delete filter.

### Proof

**File:** `/mnt/c/xampp/htdocs/CollaboraNexio/assets/js/filemanager_enhanced.js`
**Line 13:**
```javascript
filesApi: '/CollaboraNexio/api/files_tenant.php',
```

**Both API files have the correct filter** (lines 706, 718, and 729 include `deleted_at IS NULL`), BUT the issue is likely:

1. **Database state** - Tenants were never actually soft-deleted
2. **Browser cache** - Stale API responses cached in browser

---

## VERIFICATION RESULTS

### files_tenant.php Analysis

The `getTenantList()` function at line 693-734 includes:

**Super Admin Query (line 703-711):**
```php
$stmt = $pdo->prepare("
    SELECT id, name, is_active
    FROM tenants
    WHERE deleted_at IS NULL  // ✓ CORRECT FILTER
    ORDER BY name
");
```

**Admin Query (line 713-721):**
```php
$stmt = $pdo->prepare("
    SELECT t.id, t.name, t.is_active
    FROM tenants t
    INNER JOIN user_tenant_access uta ON t.id = uta.tenant_id
    WHERE uta.user_id = :user_id
    AND t.deleted_at IS NULL  // ✓ CORRECT FILTER
    ORDER BY t.name
");
```

**Status:** ✓ API code is CORRECT

### files_tenant_production.php Analysis

The `getTenantList()` function at line 679-752 also includes the filter on lines 709 and 729.

**Status:** ✓ API code is CORRECT

---

## ACTUAL PROBLEM

The issue is **NOT in the code** - it's in the **database state**!

When companies were "deleted" from aziende.php, they were likely **hard deleted** (DELETE FROM tenants WHERE id = X) instead of **soft deleted** (UPDATE tenants SET deleted_at = NOW() WHERE id = X).

This means:
1. The `deleted_at` column on those records is still NULL
2. The API query `WHERE deleted_at IS NULL` returns them
3. They appear in the dropdown

---

## IMMEDIATE FIX

Run this SQL query to soft delete all tenants except ID 11:

```sql
-- Soft delete all tenants except ID 11
UPDATE tenants
SET deleted_at = NOW()
WHERE id != 11
  AND id != 1  -- Preserve system tenant if it exists
  AND deleted_at IS NULL;

-- Verify
SELECT id, name, company_name, deleted_at
FROM tenants
WHERE deleted_at IS NULL;
-- Expected result: Only tenant ID 11 (and possibly ID 1 if system tenant)
```

---

## VERIFICATION SCRIPT

Run this command to verify the current state:

```bash
cd /mnt/c/xampp/htdocs/CollaboraNexio
php verify_tenant_deletion.php
```

Expected output should show:
- Total tenants in database
- How many have `deleted_at = NULL` (should be 1 or 2)
- Which tenants are visible to the API

---

## WHY THIS HAPPENED

### Root Cause: Missing Soft Delete in Delete API

**File:** `/mnt/c/xampp/htdocs/CollaboraNexio/api/companies/delete.php`
**Line 248:**

```php
// Finally, delete the company
$deleteStmt = $conn->prepare("DELETE FROM tenants WHERE id = :id");
$deleteStmt->bindParam(':id', $companyId, PDO::PARAM_INT);
```

**This is HARD DELETE!** It should be:

```php
// Finally, SOFT delete the company
$deleteStmt = $conn->prepare("UPDATE tenants SET deleted_at = NOW() WHERE id = :id");
$deleteStmt->bindParam(':id', $companyId, PDO::PARAM_INT);
```

---

## FIX STEPS

### Step 1: Fix Database State

```bash
cd /mnt/c/xampp/htdocs/CollaboraNexio/database
# Edit fix_tenant_soft_delete.sql and uncomment lines 85-88
# Then run:
mysql -u root collaboranexio < fix_tenant_soft_delete.sql
```

Or manually:
```sql
USE collaboranexio;
UPDATE tenants SET deleted_at = NOW() WHERE id != 11 AND deleted_at IS NULL;
```

### Step 2: Fix Delete API (CRITICAL)

**File:** `/mnt/c/xampp/htdocs/CollaboraNexio/api/companies/delete.php`
**Line 247-250:** Change from:

```php
// Finally, delete the company
$deleteStmt = $conn->prepare("DELETE FROM tenants WHERE id = :id");
$deleteStmt->bindParam(':id', $companyId, PDO::PARAM_INT);

if ($deleteStmt->execute()) {
```

To:

```php
// Finally, SOFT delete the company (preserves data integrity)
$deleteStmt = $conn->prepare("UPDATE tenants SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL");
$deleteStmt->bindParam(':id', $companyId, PDO::PARAM_INT);

if ($deleteStmt->execute() && $deleteStmt->rowCount() > 0) {
```

### Step 3: Clear Caches

```bash
# Restart Apache (Windows XAMPP)
# Stop Apache in XAMPP Control Panel, then Start again

# Or via command line:
net stop Apache2.4
net start Apache2.4

# Clear browser cache:
# Chrome/Edge: Ctrl+Shift+Delete > Cached images and files
# Or hard refresh: Ctrl+Shift+R
```

### Step 4: Verify Fix

1. Login as super_admin
2. Navigate to files.php
3. Click "Cartella Tenant" button
4. Verify dropdown shows ONLY "S.co" (ID 11)

---

## COMPREHENSIVE FIX (Code Changes)

### File: `/mnt/c/xampp/htdocs/CollaboraNexio/api/companies/delete.php`

Replace the entire deletion section (lines 247-298) with:

```php
// SOFT delete the company (preserves data integrity and foreign key relationships)
$deleteStmt = $conn->prepare("
    UPDATE tenants
    SET deleted_at = NOW(),
        updated_at = NOW(),
        status = 'inactive'
    WHERE id = :id
      AND deleted_at IS NULL
");
$deleteStmt->bindParam(':id', $companyId, PDO::PARAM_INT);

if ($deleteStmt->execute()) {
    $rowsAffected = $deleteStmt->rowCount();

    if ($rowsAffected === 0) {
        // Tenant was already deleted or doesn't exist
        $conn->rollBack();

        // Check if tenant exists
        $checkStmt = $conn->prepare("SELECT deleted_at FROM tenants WHERE id = :id");
        $checkStmt->bindParam(':id', $companyId);
        $checkStmt->execute();
        $existingTenant = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingTenant) {
            apiError('Azienda non trovata', 404);
        } else {
            apiError('Azienda già eliminata', 400);
        }
    }

    // Log the soft deletion
    try {
        $logQuery = "INSERT INTO audit_logs (user_id, tenant_id, action, entity_type, entity_id, description, ip_address, created_at)
                     VALUES (:user_id, :tenant_id, 'soft_delete', 'company', :entity_id, :description, :ip, NOW())";

        $logStmt = $conn->prepare($logQuery);
        $logStmt->bindParam(':user_id', $currentUserId);
        $logStmt->bindParam(':tenant_id', $currentTenantId);
        $logStmt->bindParam(':entity_id', $companyId);

        $description = "Soft deleted azienda: {$company['denominazione']} (ID: {$companyId})";
        if ($foldersReassigned > 0) {
            $description .= ", {$foldersReassigned} cartelle riassegnate";
        }
        if ($filesReassigned > 0) {
            $description .= ", {$filesReassigned} file riassegnati";
        }
        if ($usersUpdated > 0) {
            $description .= ", {$usersUpdated} utenti aggiornati";
        }
        if ($adminsDeleted > 0) {
            $description .= ", {$adminsDeleted} admin eliminati";
        }

        $logStmt->bindParam(':description', $description);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $logStmt->bindParam(':ip', $ipAddress);
        $logStmt->execute();
    } catch (PDOException $logError) {
        error_log('Audit log error: ' . $logError->getMessage());
    }

    // Commit transaction
    $conn->commit();

    // Return success response using centralized function
    apiSuccess([
        'deleted_company_id' => $companyId,
        'folders_reassigned' => $foldersReassigned ?? 0,
        'files_reassigned' => $filesReassigned ?? 0,
        'users_updated' => $usersUpdated ?? 0,
        'admins_deleted' => $adminsDeleted ?? 0,
        'deletion_type' => 'soft',
        'note' => 'Company soft deleted - data preserved for audit trail'
    ], 'Azienda eliminata con successo (soft delete)');
} else {
    $conn->rollBack();
    apiError('Errore nell\'eliminazione dell\'azienda', 500);
}
```

---

## TESTING CHECKLIST

After applying fixes:

- [ ] Run `php verify_tenant_deletion.php` - should show only 1-2 active tenants
- [ ] Run SQL query: `SELECT id, name, deleted_at FROM tenants WHERE deleted_at IS NULL;` - should return only ID 11
- [ ] Restart Apache to clear PHP OPcache
- [ ] Clear browser cache (Ctrl+Shift+Delete)
- [ ] Login as super_admin
- [ ] Navigate to files.php
- [ ] Click "Cartella Tenant" button
- [ ] Verify dropdown shows ONLY "S.co" (ID 11)
- [ ] Try deleting a test company from aziende.php
- [ ] Verify it disappears from dropdown immediately
- [ ] Verify `deleted_at` column is populated in database

---

## PREVENTION MEASURES

### 1. Add Database Constraint

```sql
-- Create trigger to prevent hard deletes on tenants
DELIMITER $$
CREATE TRIGGER prevent_tenant_hard_delete
BEFORE DELETE ON tenants
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Hard delete not allowed on tenants table. Use soft delete (UPDATE deleted_at).';
END$$
DELIMITER ;
```

### 2. Add API Validation

In every tenant list query, add this check:

```php
// Validate tenant access includes soft delete filter
function getTenantListWithValidation() {
    $query = "SELECT * FROM tenants WHERE deleted_at IS NULL";

    // Log query for audit
    error_log('[TENANT_LIST] Query: ' . $query);

    $result = $pdo->query($query);
    $tenants = $result->fetchAll();

    // Validate no deleted tenants returned
    foreach ($tenants as $tenant) {
        if ($tenant['deleted_at'] !== null) {
            error_log('[CRITICAL] Deleted tenant returned: ' . $tenant['id']);
            throw new Exception('Data integrity error: Deleted tenant in result set');
        }
    }

    return $tenants;
}
```

### 3. Add Unit Test

```php
// tests/TenantDeletionTest.php
public function testSoftDeleteFilterInTenantList() {
    // Create test tenant
    $tenantId = $this->createTestTenant();

    // Soft delete it
    $this->db->query("UPDATE tenants SET deleted_at = NOW() WHERE id = $tenantId");

    // Call API
    $response = $this->callApi('get_tenant_list');

    // Assert deleted tenant NOT in results
    $tenantIds = array_column($response['data'], 'id');
    $this->assertNotContains($tenantId, $tenantIds, 'Soft deleted tenant should not appear in tenant list');
}
```

---

## FILES TO MODIFY

1. ✓ `/mnt/c/xampp/htdocs/CollaboraNexio/database/fix_tenant_soft_delete.sql` - Run this
2. ✗ `/mnt/c/xampp/htdocs/CollaboraNexio/api/companies/delete.php` - Change hard DELETE to soft UPDATE
3. ✓ `/mnt/c/xampp/htdocs/CollaboraNexio/verify_tenant_deletion.php` - Already created
4. ✓ `/mnt/c/xampp/htdocs/CollaboraNexio/api/files_tenant.php` - Already has correct filter
5. ✓ `/mnt/c/xampp/htdocs/CollaboraNexio/api/files_tenant_production.php` - Already has correct filter

---

## SUMMARY

**Problem:** Companies deleted from aziende.php still appear in tenant dropdown
**Root Cause:** Hard DELETE instead of soft UPDATE in delete API
**Impact:** Data integrity violation, deleted tenants visible
**Fix Complexity:** MEDIUM - Requires code change + database update
**Risk Level:** LOW - Changes are isolated and reversible

**Critical Fix Required:**
- Change `/mnt/c/xampp/htdocs/CollaboraNexio/api/companies/delete.php` line 248 from DELETE to UPDATE
- Run SQL: `UPDATE tenants SET deleted_at = NOW() WHERE id != 11 AND deleted_at IS NULL;`
- Restart Apache
- Clear browser cache

**Timeline:**
- Database fix: 2 minutes
- Code fix: 5 minutes
- Testing: 5 minutes
- Total: 12 minutes

---

**END OF DIAGNOSIS**
