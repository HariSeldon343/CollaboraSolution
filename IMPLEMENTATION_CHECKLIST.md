# Tenant Locations Implementation Checklist

**Project**: Tenant Locations Database Redesign
**Date**: 2025-10-07
**Status**: Pre-Implementation

---

## Pre-Implementation (Day 0)

### Review & Planning
- [ ] Review `TENANT_LOCATIONS_REDESIGN.md` (complete guide)
- [ ] Review `TENANT_LOCATIONS_SUMMARY.md` (executive summary)
- [ ] Review `TENANT_LOCATIONS_QUICK_START.md` (quick reference)
- [ ] Understand current JSON structure in production
- [ ] Identify affected systems/APIs
- [ ] Schedule maintenance window (recommend 2-4 hours)

### Backup & Safety
- [ ] Full database backup completed
- [ ] Backup verified and restorable
- [ ] Rollback script reviewed and tested
- [ ] Emergency contacts list prepared
- [ ] Monitoring tools configured

### Team Preparation
- [ ] Notify stakeholders of changes
- [ ] Brief development team on new structure
- [ ] Prepare support team with documentation
- [ ] Create communication plan for users

---

## Implementation Phase 1: Database Migration (Day 1)

### Step 1.1: Pre-Migration Verification

```bash
# Check current database state
mysql -u root collaboranexio -e "DESCRIBE tenants"
mysql -u root collaboranexio -e "SELECT COUNT(*) FROM tenants"
mysql -u root collaboranexio -e "SELECT COUNT(*) FROM tenants WHERE sedi_operative IS NOT NULL"
```

**Checklist:**
- [ ] Database accessible
- [ ] Current tenants count verified: _______
- [ ] Tenants with JSON sedi_operative: _______
- [ ] No active transactions blocking tables
- [ ] Disk space sufficient (check >2GB free)

### Step 1.2: Run SQL Migration

```bash
# Execute migration
mysql -u root collaboranexio < /mnt/c/xampp/htdocs/CollaboraNexio/database/migrations/tenant_locations_schema.sql

# Verify execution
echo $?  # Should return 0 (success)
```

**Checklist:**
- [ ] Migration script executed without errors
- [ ] Exit code is 0 (success)
- [ ] No error messages in output
- [ ] Backup table created: `tenants_backup_locations_20251007`

### Step 1.3: Verify Database Structure

```bash
# Check table creation
mysql -u root collaboranexio -e "DESCRIBE tenant_locations"

# Check views
mysql -u root collaboranexio -e "SHOW CREATE VIEW v_tenants_with_sede_legale\G"
mysql -u root collaboranexio -e "SHOW CREATE VIEW v_tenant_locations_active\G"
mysql -u root collaboranexio -e "SHOW CREATE VIEW v_tenant_location_counts\G"

# Check triggers
mysql -u root collaboranexio -e "SHOW TRIGGERS LIKE 'tenant_locations'"

# Check indexes
mysql -u root collaboranexio -e "SHOW INDEX FROM tenant_locations"
```

**Checklist:**
- [ ] tenant_locations table created with all columns
- [ ] 3 views created successfully
- [ ] 5 triggers created successfully
- [ ] 9 indexes created successfully
- [ ] Foreign key constraints active

### Step 1.4: Verify Data Migration

```bash
# Check migrated sede_legale data
mysql -u root collaboranexio -e "SELECT COUNT(*) FROM tenant_locations WHERE location_type='sede_legale'"
mysql -u root collaboranexio -e "SELECT * FROM v_tenant_location_counts"

# Check demo data
mysql -u root collaboranexio -e "SELECT tenant_id, denominazione, total_locations FROM tenants WHERE total_locations > 0"
```

**Checklist:**
- [ ] Sede legale records migrated: _______
- [ ] Demo companies created: 2 (TechnoItalia, Logistics Express)
- [ ] Location counts accurate
- [ ] No NULL values in required fields
- [ ] All CAP values are 5 digits
- [ ] All provincia values are 2 chars uppercase

### Step 1.5: Run JSON Data Migration

```bash
# Dry run first (safe)
php /mnt/c/xampp/htdocs/CollaboraNexio/migrate_tenant_locations.php

# Review output
# If satisfied, edit file: Set $DRY_RUN = false

# Run actual migration
php /mnt/c/xampp/htdocs/CollaboraNexio/migrate_tenant_locations.php
```

**Checklist:**
- [ ] Dry run completed without errors
- [ ] Migration report reviewed
- [ ] Number of locations to migrate: _______
- [ ] Any validation errors noted and resolved
- [ ] Actual migration completed successfully
- [ ] All JSON sedi_operative migrated
- [ ] Cached counts updated

### Step 1.6: Database Verification

```bash
# Run comprehensive checks
mysql -u root collaboranexio -e "
SELECT
    'Locations Table' as check_type,
    COUNT(*) as total_records,
    COUNT(CASE WHEN location_type='sede_legale' THEN 1 END) as sede_legale,
    COUNT(CASE WHEN location_type='sede_operativa' THEN 1 END) as sedi_operative,
    COUNT(CASE WHEN deleted_at IS NULL THEN 1 END) as active_records
FROM tenant_locations
"

# Check data integrity
mysql -u root collaboranexio -e "
SELECT
    t.id,
    t.denominazione,
    t.total_locations as cached_count,
    COUNT(tl.id) as actual_count,
    CASE WHEN t.total_locations = COUNT(tl.id) THEN 'OK' ELSE 'MISMATCH' END as status
FROM tenants t
LEFT JOIN tenant_locations tl ON t.id = tl.tenant_id AND tl.deleted_at IS NULL AND tl.is_active = TRUE
GROUP BY t.id, t.denominazione, t.total_locations
HAVING COUNT(tl.id) > 0
"
```

**Checklist:**
- [ ] All records have valid tenant_id
- [ ] All sede_legale have is_primary = TRUE
- [ ] No duplicate primary sede_legale per tenant
- [ ] Cached counts match actual counts
- [ ] All foreign keys valid (no orphaned records)
- [ ] All CAP values validated (5 digits)
- [ ] All provincia values validated (2 chars)

---

## Implementation Phase 2: API Development (Days 2-3)

### Step 2.1: Create New API Endpoints

**Files to verify:**
```
/mnt/c/xampp/htdocs/CollaboraNexio/api/tenants/locations/list.php
/mnt/c/xampp/htdocs/CollaboraNexio/api/tenants/locations/create.php
/mnt/c/xampp/htdocs/CollaboraNexio/api/tenants/locations/update.php
/mnt/c/xampp/htdocs/CollaboraNexio/api/tenants/locations/delete.php
```

**Checklist:**
- [ ] All 4 API files created
- [ ] PHP syntax validation passed
- [ ] API authentication implemented
- [ ] CSRF protection active
- [ ] Multi-tenant isolation enforced
- [ ] Audit logging implemented
- [ ] Error handling comprehensive

### Step 2.2: Test API Endpoints

#### Test List Locations
```bash
curl -X GET "http://localhost:8888/CollaboraNexio/api/tenants/1/locations" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

**Expected:** JSON response with sede_legale and sedi_operative arrays

**Checklist:**
- [ ] 200 OK status
- [ ] Valid JSON response
- [ ] sede_legale present
- [ ] sedi_operative array present
- [ ] total_locations accurate
- [ ] indirizzo_completo formatted correctly

#### Test Create Location
```bash
curl -X POST "http://localhost:8888/CollaboraNexio/api/tenants/1/locations" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -d '{
    "location_type": "sede_operativa",
    "indirizzo": "Via Test",
    "civico": "99",
    "cap": "00100",
    "comune": "Roma",
    "provincia": "RM",
    "note": "Test location"
  }'
```

**Expected:** 201 Created with location_id

**Checklist:**
- [ ] 201 Created status
- [ ] location_id returned
- [ ] Record exists in database
- [ ] Cached count updated
- [ ] Audit log created

#### Test Update Location
```bash
curl -X PUT "http://localhost:8888/CollaboraNexio/api/tenants/1/locations/5" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: YOUR_TOKEN" \
  -d '{
    "telefono": "+39 06 12345678",
    "note": "Updated test location"
  }'
```

**Expected:** 200 OK with updated location

**Checklist:**
- [ ] 200 OK status
- [ ] Fields updated in database
- [ ] Old values preserved in audit log
- [ ] Triggers executed correctly

#### Test Delete Location
```bash
curl -X DELETE "http://localhost:8888/CollaboraNexio/api/tenants/1/locations/5" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-CSRF-Token: YOUR_TOKEN"
```

**Expected:** 200 OK with deleted_at timestamp

**Checklist:**
- [ ] 200 OK status
- [ ] deleted_at set in database
- [ ] is_active set to FALSE
- [ ] Cached count updated
- [ ] Cannot delete primary sede_legale (403 error)

### Step 2.3: Update Existing API Endpoints

#### Update api/tenants/create.php

**Changes needed:**
```php
// Old: Store JSON in sedi_operative column
$tenantData['sedi_operative'] = json_encode($input['sedi_operative']);

// New: Insert into tenant_locations table
foreach ($input['sedi_operative'] as $sede) {
    $db->insert('tenant_locations', [
        'tenant_id' => $tenantId,
        'location_type' => 'sede_operativa',
        'indirizzo' => $sede['indirizzo'],
        // ... other fields
    ]);
}
```

**Checklist:**
- [ ] Code updated to insert locations separately
- [ ] Transaction wraps entire operation
- [ ] Rollback on error
- [ ] Audit logging updated
- [ ] Backward compatibility maintained (optional)
- [ ] Tests pass

#### Update api/tenants/get.php

**Changes needed:**
```php
// Add: Fetch locations from tenant_locations table
$locations = $db->fetchAll(
    'SELECT * FROM tenant_locations WHERE tenant_id = ? AND deleted_at IS NULL',
    [$tenantId]
);

// Add: Separate sede_legale and sedi_operative
// Add to response
```

**Checklist:**
- [ ] Locations fetched from tenant_locations table
- [ ] sede_legale and sedi_operative separated in response
- [ ] indirizzo_completo formatted
- [ ] Backward compatibility maintained (optional)
- [ ] Tests pass

#### Update api/tenants/list.php

**Changes needed:**
```php
// Add: JOIN with tenant_locations for sede_legale info
LEFT JOIN tenant_locations tl
    ON t.id = tl.tenant_id
    AND tl.location_type = 'sede_legale'
    AND tl.is_primary = TRUE

// Add: total_locations to response
```

**Checklist:**
- [ ] List query updated with JOIN
- [ ] total_locations included in response
- [ ] sede_legale commune/province displayed
- [ ] Performance acceptable (uses indexes)
- [ ] Tests pass

#### Update api/tenants/update.php

**Changes needed:**
```php
// Change: Update sede_legale in tenant_locations table
$db->update('tenant_locations', [...], [
    'tenant_id' => $tenantId,
    'location_type' => 'sede_legale',
    'is_primary' => true
]);

// Note: Sedi operative updates should use dedicated endpoints
```

**Checklist:**
- [ ] Sede legale updates routed to tenant_locations
- [ ] Documentation updated for sedi operative management
- [ ] Tests pass

---

## Implementation Phase 3: Frontend Updates (Days 4-5)

### Step 3.1: Update JavaScript (aziende.js)

**Changes needed:**
- Load locations from new API
- Add location management UI (add/remove buttons)
- Handle create/update/delete operations
- Display location count

**Checklist:**
- [ ] `loadTenantLocations()` function implemented
- [ ] `addLocation()` function implemented
- [ ] `updateLocation()` function implemented
- [ ] `deleteLocation()` function implemented
- [ ] Dynamic add/remove UI working
- [ ] Location count displayed
- [ ] Validation working (CAP, provincia, etc.)
- [ ] CSRF token included in requests
- [ ] Error handling implemented
- [ ] Success/error messages displayed

### Step 3.2: Update HTML Forms

**Files to verify:**
- `aziende.php` (list page)
- `aziende_new.php` (create form)

**Checklist:**
- [ ] Form structure unchanged (already correct)
- [ ] Sede legale: 5 separate fields present
- [ ] Sedi operative: Dynamic add/remove UI present
- [ ] Location counter displayed (0/unlimited)
- [ ] No references to old single-field structure
- [ ] Modal forms updated
- [ ] Table columns updated to show location count

### Step 3.3: Frontend Testing

#### Test Create Company with Locations
**Checklist:**
- [ ] Can create company with sede legale
- [ ] Can add multiple sedi operative (test 3-5)
- [ ] CAP validation working (must be 5 digits)
- [ ] Provincia validation working (must be 2 chars)
- [ ] All locations saved to database
- [ ] Location count accurate
- [ ] No console errors

#### Test Edit Company Locations
**Checklist:**
- [ ] Can view existing locations
- [ ] Can add new sede operativa
- [ ] Can edit existing sede operativa
- [ ] Can delete sede operativa
- [ ] Cannot delete primary sede legale
- [ ] Changes reflected immediately
- [ ] Location count updates

#### Test List Companies
**Checklist:**
- [ ] Location count displayed in table
- [ ] Sede legale comune displayed
- [ ] Clicking company shows all locations
- [ ] Filtering by comune works (if implemented)
- [ ] Pagination works
- [ ] No console errors

---

## Implementation Phase 4: Testing (Day 6)

### Step 4.1: Unit Tests

**Database Tests:**
- [ ] Insert location: Valid data successful
- [ ] Insert location: Invalid CAP fails
- [ ] Insert location: Invalid provincia fails
- [ ] Update location: Changes persisted
- [ ] Delete location: Soft delete works
- [ ] Delete primary sede_legale: Fails as expected
- [ ] Trigger: Only one primary sede_legale per tenant
- [ ] Trigger: Cached counts updated correctly
- [ ] View: v_tenants_with_sede_legale accurate
- [ ] View: v_tenant_location_counts accurate

**API Tests:**
- [ ] List locations: Returns correct format
- [ ] Create location: Validation working
- [ ] Update location: Partial updates work
- [ ] Delete location: Soft delete working
- [ ] Multi-tenant: Isolation enforced
- [ ] Authentication: Required for all endpoints
- [ ] CSRF: Token validation working
- [ ] Errors: Proper error messages returned

**Frontend Tests:**
- [ ] Form validation: Client-side working
- [ ] Location management: Add/remove working
- [ ] AJAX calls: Proper error handling
- [ ] UI updates: Immediate feedback
- [ ] Browser compatibility: Chrome, Firefox, Safari, Edge

### Step 4.2: Integration Tests

**End-to-End Flows:**
- [ ] Create company → Add locations → View in list
- [ ] Edit company → Add location → Verify in DB
- [ ] Edit company → Remove location → Verify soft delete
- [ ] Create company with 10 locations → All saved
- [ ] Search by comune → Correct results
- [ ] Multi-tenant: Admin sees only their tenants

### Step 4.3: Performance Tests

**Query Performance:**
- [ ] List locations for tenant: <10ms
- [ ] Find tenants by comune: <50ms
- [ ] View v_tenant_location_counts: <100ms
- [ ] Create location: <100ms
- [ ] Update location: <50ms

**Load Tests:**
- [ ] 1000 tenants with 5 locations each
- [ ] Concurrent location updates (10 users)
- [ ] Bulk location creation (100 locations)
- [ ] Index usage verified (EXPLAIN queries)

### Step 4.4: Data Integrity Tests

**Validation:**
- [ ] Cannot create two primary sede_legale
- [ ] CAP must be 5 digits
- [ ] Provincia must be 2 chars uppercase
- [ ] Foreign keys prevent orphaned records
- [ ] Soft delete preserves data
- [ ] Cached counts always accurate

---

## Implementation Phase 5: Documentation (Day 6)

### Step 5.1: Developer Documentation

**Checklist:**
- [ ] API documentation updated with new endpoints
- [ ] Database schema diagram updated
- [ ] Code comments reviewed
- [ ] README updated with migration instructions
- [ ] CLAUDE.md updated with new patterns
- [ ] Example code snippets added

### Step 5.2: User Documentation

**Checklist:**
- [ ] User guide created for location management
- [ ] Screenshots of new UI
- [ ] Video tutorial recorded (optional)
- [ ] FAQ section created
- [ ] Troubleshooting guide written

### Step 5.3: Support Team Training

**Checklist:**
- [ ] Support team briefed on changes
- [ ] Common issues and solutions documented
- [ ] Test accounts created for support team
- [ ] Knowledge base articles updated
- [ ] Support team can answer basic questions

---

## Implementation Phase 6: Production Deployment (Day 7)

### Step 6.1: Pre-Deployment

**Checklist:**
- [ ] All tests passed in staging
- [ ] Code reviewed and approved
- [ ] Database backup completed
- [ ] Rollback plan reviewed
- [ ] Maintenance window scheduled
- [ ] Stakeholders notified
- [ ] Monitoring tools configured
- [ ] Support team on standby

### Step 6.2: Deployment

**Execution:**
```bash
# 1. Enable maintenance mode
# 2. Final database backup
# 3. Run SQL migration
# 4. Run PHP migration (if needed)
# 5. Deploy new API code
# 6. Deploy new frontend code
# 7. Verify deployment
# 8. Disable maintenance mode
```

**Checklist:**
- [ ] Maintenance mode enabled
- [ ] Final backup completed and verified
- [ ] SQL migration executed successfully
- [ ] PHP migration executed (if applicable)
- [ ] API code deployed
- [ ] Frontend code deployed
- [ ] Cache cleared
- [ ] Deployment verified
- [ ] Maintenance mode disabled

### Step 6.3: Post-Deployment Verification

**Immediate Checks (First 15 minutes):**
- [ ] Application accessible
- [ ] No 500 errors in logs
- [ ] Create tenant with locations: Works
- [ ] List tenants: Works
- [ ] Update location: Works
- [ ] Delete location: Works
- [ ] Database queries performing well
- [ ] No user complaints

**Extended Monitoring (First 24 hours):**
- [ ] Error rate normal
- [ ] Response times acceptable
- [ ] No database deadlocks
- [ ] No memory leaks
- [ ] Cache performance good
- [ ] User feedback positive

### Step 6.4: Rollback (If Needed)

**If issues detected:**
```bash
# Execute rollback script
mysql -u root collaboranexio < rollback_tenant_locations.sql

# Restore code
git revert <commit-hash>

# Clear cache
# Verify rollback successful
```

**Checklist:**
- [ ] Rollback decision made quickly
- [ ] Database restored from backup
- [ ] Old code redeployed
- [ ] Cache cleared
- [ ] Application functional
- [ ] Users notified of rollback
- [ ] Incident report created

---

## Post-Implementation (Week 2)

### Step 7.1: Monitoring & Optimization

**Daily Monitoring:**
- [ ] Error logs reviewed
- [ ] Performance metrics acceptable
- [ ] User feedback gathered
- [ ] Database growth tracked
- [ ] Index usage verified

**Weekly Review:**
- [ ] Performance trends analyzed
- [ ] Optimization opportunities identified
- [ ] User feedback addressed
- [ ] Documentation updated
- [ ] Team retrospective held

### Step 7.2: Cleanup (6 Months Later)

**After stable for 6 months:**
- [ ] Remove deprecated columns from tenants table
- [ ] Remove old JSON parsing code
- [ ] Remove backward compatibility code
- [ ] Update documentation
- [ ] Archive backup tables

---

## Sign-Off

### Pre-Implementation Sign-Off

**Approved by:**
- [ ] Database Architect: _________________ Date: _______
- [ ] Lead Developer: _________________ Date: _______
- [ ] Project Manager: _________________ Date: _______
- [ ] Product Owner: _________________ Date: _______

### Post-Implementation Sign-Off

**Verified by:**
- [ ] QA Lead: _________________ Date: _______
- [ ] Database Architect: _________________ Date: _______
- [ ] Operations: _________________ Date: _______

**Production Ready:**
- [ ] All tests passed: _________________ Date: _______
- [ ] Documentation complete: _________________ Date: _______
- [ ] Training complete: _________________ Date: _______
- [ ] Approved for production: _________________ Date: _______

---

## Emergency Contacts

**During Implementation:**
- Database Architect: [Contact]
- Lead Developer: [Contact]
- DevOps: [Contact]
- Project Manager: [Contact]

**Post-Deployment:**
- Support Team Lead: [Contact]
- On-Call Developer: [Contact]
- Database Administrator: [Contact]

---

**Document Version**: 1.0
**Last Updated**: 2025-10-07
**Status**: Pre-Implementation
